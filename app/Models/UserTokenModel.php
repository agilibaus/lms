<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Token monouso per la verifica dell'indirizzo email e il reset della password.
 *
 * In tabella finisce solo l'hash SHA-256: il token in chiaro esiste soltanto
 * nel link inviato per email, quindi chi legge il database non può usarlo.
 */
class UserTokenModel
{
    public const PURPOSE_VERIFICATION = 'email_verification';
    public const PURPOSE_RESET = 'password_reset';

    /**
     * Crea un token e restituisce il valore in chiaro, da mettere nel link.
     * I token precedenti con lo stesso scopo vengono invalidati: un solo link
     * valido per volta.
     */
    public static function issue(int $userId, string $purpose, int $lifetimeSeconds): string
    {
        self::invalidateAll($userId, $purpose);

        $token = bin2hex(random_bytes(32));

        // La scadenza si calcola sull'orologio del database, non su quello di PHP:
        // i due possono stare su fusi diversi, e il confronto avviene in SQL.
        $stmt = Database::connection()->prepare(
            'INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at)
             VALUES (:user_id, :purpose, :token_hash, DATE_ADD(NOW(), INTERVAL :lifetime SECOND))'
        );
        $stmt->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => self::hash($token),
            'lifetime' => $lifetimeSeconds,
        ]);

        return $token;
    }

    /**
     * Restituisce la riga del token se esiste, non è scaduto e non è già stato
     * usato; altrimenti null.
     */
    public static function findValid(string $token, string $purpose): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM user_tokens
             WHERE token_hash = :token_hash AND purpose = :purpose
               AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hash($token), 'purpose' => $purpose]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function markUsed(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function invalidateAll(int $userId, string $purpose): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE user_tokens SET used_at = NOW()
             WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'purpose' => $purpose]);
    }

    /**
     * Quanti token sono stati emessi di recente per un utente: serve a non
     * trasformare il rinvio del link in uno strumento per inondare di email
     * l'indirizzo di qualcun altro.
     */
    public static function countRecent(int $userId, string $purpose, int $withinSeconds): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM user_tokens
             WHERE user_id = :user_id AND purpose = :purpose
               AND created_at > DATE_SUB(NOW(), INTERVAL :within SECOND)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'within' => $withinSeconds,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
