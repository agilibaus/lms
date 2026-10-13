<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Il limite ai tentativi di accesso (13/10, verifica di sicurezza).
 *
 * Senza, si potevano provare password a ripetizione sullo stesso account.
 * Ora, nell'arco di un quarto d'ora:
 *
 *   - **10 password sbagliate per lo stesso indirizzo** lo fermano, da
 *     qualunque IP arrivino: e' il limite che protegge l'account;
 *   - **50 password sbagliate dallo stesso IP** lo fermano, qualunque
 *     indirizzo provi: e' il limite contro chi prova tanti account. E' piu'
 *     alto perche' dietro lo stesso IP possono esserci piu' persone (una
 *     scuola, un ufficio).
 *
 * Il blocco finisce da solo, quando il primo errore della finestra esce dal
 * quarto d'ora. Chi entra con la password giusta azzera gli errori del suo
 * indirizzo. Contano solo le password sbagliate: un account disattivato o
 * non confermato, con la password giusta, non e' un tentativo da fermare.
 *
 * Gli indirizzi che non esistono contano come gli altri: trattarli
 * diversamente direbbe a chi prova quali indirizzi sono registrati.
 *
 * I tempi sono quelli del database (`NOW()`), non di PHP: le due macchine
 * possono stare su fusi diversi.
 */
final class TentativiAccesso
{
    public const FINESTRA_SECONDI = 15 * 60;
    public const MAX_PER_INDIRIZZO = 10;
    public const MAX_PER_IP = 50;

    /**
     * Fra quanti minuti si puo' riprovare, o null se non c'e' blocco.
     * Arrotondato per eccesso: «fra 1 minuto» anche quando mancano 10 secondi.
     */
    public static function minutiDiBlocco(string $email, string $ip): ?int
    {
        $secondi = max(
            self::secondiDiBlocco('email', self::normalizza($email), self::MAX_PER_INDIRIZZO),
            self::secondiDiBlocco('ip', $ip, self::MAX_PER_IP)
        );

        return $secondi > 0 ? (int) ceil($secondi / 60) : null;
    }

    public static function registraErrore(string $email, string $ip): void
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO login_failures (email, ip) VALUES (:e, :ip)')
            ->execute(['e' => self::normalizza($email), 'ip' => $ip]);
        // Le righe vecchie non servono piu' a niente: si tolgono qui invece
        // che con un lavoro periodico in piu'.
        $db->exec('DELETE FROM login_failures WHERE created_at < NOW() - INTERVAL 1 DAY');
    }

    /** Chi e' entrato riparte da zero. */
    public static function azzera(string $email): void
    {
        Database::connection()->prepare('DELETE FROM login_failures WHERE email = :e')
            ->execute(['e' => self::normalizza($email)]);
    }

    /** La frase per chi e' fermato: dice quanto aspettare e che cosa si puo' fare intanto. */
    public static function messaggio(int $minuti): string
    {
        return 'Troppi tentativi di accesso con password sbagliata. Riprova fra '
            . ($minuti === 1 ? '1 minuto' : $minuti . ' minuti')
            . ', oppure reimposta la password con «Password dimenticata?».';
    }

    /** L'IP di chi chiede: quello della connessione, mai un'intestazione che il browser puo' scrivere. */
    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    private static function normalizza(string $email): string
    {
        return mb_substr(strtolower(trim($email)), 0, 255);
    }

    /** Secondi che mancano alla fine del blocco per una colonna; 0 se non c'e' blocco. */
    private static function secondiDiBlocco(string $colonna, string $valore, int $massimo): int
    {
        // Il blocco dura finche' nella finestra restano `$massimo` errori: finisce
        // quando esce il piu' vecchio di questi, cioe' il `$massimo`-esimo dal
        // piu' recente.
        $stmt = Database::connection()->prepare(
            'SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL ' . self::FINESTRA_SECONDI . ' SECOND))
               FROM login_failures
              WHERE ' . ($colonna === 'ip' ? 'ip' : 'email') . ' = :v
                AND created_at > NOW() - INTERVAL ' . self::FINESTRA_SECONDI . ' SECOND
              ORDER BY created_at DESC, id DESC
              LIMIT 1 OFFSET ' . ($massimo - 1)
        );
        $stmt->execute(['v' => $valore]);
        $resta = $stmt->fetchColumn();

        return $resta === false ? 0 : (int) $resta;
    }
}
