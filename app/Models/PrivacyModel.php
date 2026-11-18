<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Privacy (18/11, Alessandro): le versioni dell'informativa e le richieste
 * di cancellazione dei dati.
 *
 * La versione in vigore e' la piu' recente. «Pubblica una nuova versione»
 * aggiunge una riga, e gli studenti vedono l'avviso al prossimo accesso;
 * «Salva la correzione» riscrive la versione in vigore, per i refusi, e non
 * avvisa nessuno.
 */
class PrivacyModel
{
    /** @return array{id: int, content_html: string, published_at: string, corrected_at: ?string}|null */
    public static function inVigore(): ?array
    {
        $riga = Database::connection()
            ->query('SELECT id, content_html, published_at, corrected_at FROM privacy_versions ORDER BY id DESC LIMIT 1')
            ->fetch();

        return $riga ? self::tipizza($riga) : null;
    }

    /** L'id della versione in vigore, o null se l'informativa non e' ancora pubblicata. */
    public static function idInVigore(): ?int
    {
        $id = Database::connection()->query('SELECT MAX(id) FROM privacy_versions')->fetchColumn();

        return $id === null || $id === false ? null : (int) $id;
    }

    /** @return array{id: int, content_html: string, published_at: string, corrected_at: ?string}|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, content_html, published_at, corrected_at FROM privacy_versions WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $riga = $stmt->fetch();

        return $riga ? self::tipizza($riga) : null;
    }

    /**
     * Le versioni precedenti a quella in vigore, dalla piu' recente.
     *
     * @return list<array{id: int, published_at: string}>
     */
    public static function precedenti(): array
    {
        $righe = Database::connection()->query(
            'SELECT id, published_at FROM privacy_versions
              WHERE id < (SELECT MAX(id) FROM privacy_versions)
              ORDER BY id DESC'
        )->fetchAll();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'published_at' => (string) $r['published_at']], $righe);
    }

    public static function pubblica(string $html): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO privacy_versions (content_html, published_at) VALUES (:t, NOW())');
        $stmt->execute(['t' => $html]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function correggi(int $id, string $html): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE privacy_versions SET content_html = :t, corrected_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['t' => $html, 'id' => $id]);
    }

    /**
     * Segna la versione comunicata a uno studente. Non torna mai indietro:
     * un'email «Il tuo accesso» partita in ritardo non riapre un avviso gia'
     * chiuso per una versione piu' recente.
     */
    public static function segnaComunicata(int $userId, int $versionId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET privacy_version_id = :v
              WHERE id = :id AND (privacy_version_id IS NULL OR privacy_version_id < :v2)'
        );
        $stmt->execute(['v' => $versionId, 'v2' => $versionId, 'id' => $userId]);
    }

    /** L'ultima versione comunicata allo studente, o null. */
    public static function comunicata(int $userId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT privacy_version_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $v = $stmt->fetchColumn();

        return $v === null || $v === false ? null : (int) $v;
    }

    /**
     * La richiesta di cancellazione dal profilo: la data, e l'accesso chiuso
     * nella stessa scrittura. `guardSession()` butta fuori le altre sessioni
     * aperte alla loro prossima richiesta, come per un account disattivato.
     */
    public static function chiediCancellazione(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET deletion_requested_at = NOW(), is_active = 0
              WHERE id = :id AND deletion_requested_at IS NULL'
        );
        $stmt->execute(['id' => $userId]);
    }

    /** L'Amministratore riattiva l'account: la richiesta non vale piu'. */
    public static function annullaCancellazione(int $userId): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET deletion_requested_at = NULL WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Le richieste in attesa, dalla piu' vecchia: e' la prima da evadere.
     *
     * @return list<array{id: int, full_name: string, email: string, avatar_path: ?string, deletion_requested_at: string}>
     */
    public static function richieste(): array
    {
        $righe = Database::connection()->query(
            'SELECT id, full_name, email, avatar_path, deletion_requested_at FROM users
              WHERE deletion_requested_at IS NOT NULL
              ORDER BY deletion_requested_at, id'
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'full_name' => (string) $r['full_name'],
            'email' => (string) $r['email'],
            'avatar_path' => $r['avatar_path'] !== null ? (string) $r['avatar_path'] : null,
            'deletion_requested_at' => (string) $r['deletion_requested_at'],
        ], $righe);
    }

    public static function contaRichieste(): int
    {
        return (int) Database::connection()
            ->query('SELECT COUNT(*) FROM users WHERE deletion_requested_at IS NOT NULL')
            ->fetchColumn();
    }

    /** @param array<string, mixed> $r */
    private static function tipizza(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'content_html' => (string) $r['content_html'],
            'published_at' => (string) $r['published_at'],
            'corrected_at' => $r['corrected_at'] !== null ? (string) $r['corrected_at'] : null,
        ];
    }
}
