<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Il limite ai tentativi di accesso (13/10, verifica di sicurezza; stretto il
 * 13/10 da Alessandro).
 *
 *   - **5 password sbagliate per lo stesso indirizzo, nell'arco di un'ora,
 *     bloccano l'account per un'ora**, da qualunque IP arrivino. Il blocco
 *     parte dalla quinta: per un'ora nemmeno la password giusta apre, e
 *     l'avviso lo dice a schermo. Dalla terza password sbagliata l'avviso
 *     dice quanti tentativi restano;
 *   - **50 password sbagliate dallo stesso IP in un quarto d'ora** fermano
 *     quell'IP, qualunque indirizzo provi: e' il limite contro chi prova
 *     tanti account. E' piu' alto perche' dietro lo stesso IP possono
 *     esserci piu' persone (una scuola, un ufficio).
 *
 * Durante il blocco i tentativi non si contano (non arrivano nemmeno al
 * controllo della password), quindi il blocco dell'account finisce un'ora
 * dopo la quinta password sbagliata, e si riparte da zero. Chi entra con la
 * password giusta azzera gli errori del suo indirizzo. Contano solo le
 * password sbagliate: un account disattivato o non confermato, con la
 * password giusta, non e' un tentativo da fermare.
 *
 * Gli indirizzi che non esistono contano come gli altri, e ricevono gli
 * stessi avvisi: trattarli diversamente direbbe a chi prova quali indirizzi
 * sono registrati.
 *
 * I tempi sono quelli del database (`NOW()`), non di PHP: le due macchine
 * possono stare su fusi diversi.
 */
final class TentativiAccesso
{
    public const MAX_PER_INDIRIZZO = 5;
    public const BLOCCO_INDIRIZZO_SECONDI = 60 * 60;
    /** Da quante password sbagliate l'avviso dice quanti tentativi restano. */
    public const AVVISO_DA = 3;

    public const MAX_PER_IP = 50;
    public const FINESTRA_IP_SECONDI = 15 * 60;

    public const ACCOUNT = 'account';
    public const IP = 'ip';

    /**
     * Il blocco in corso, o null: chi e' bloccato (l'account o l'IP) e fra
     * quanti minuti si puo' riprovare, arrotondato per eccesso («fra 1
     * minuto» anche quando mancano 10 secondi).
     *
     * @return array{motivo: string, minuti: int}|null
     */
    public static function blocco(string $email, string $ip): ?array
    {
        $account = self::secondiBloccoIndirizzo(self::normalizza($email));
        $daIp = self::secondiBloccoIp($ip);

        if ($account <= 0 && $daIp <= 0) {
            return null;
        }

        return $account >= $daIp
            ? ['motivo' => self::ACCOUNT, 'minuti' => (int) ceil($account / 60)]
            : ['motivo' => self::IP, 'minuti' => (int) ceil($daIp / 60)];
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

    /** Quanti tentativi restano a questo indirizzo prima del blocco. */
    public static function restano(string $email): int
    {
        return max(0, self::MAX_PER_INDIRIZZO - self::erroriRecenti(self::normalizza($email)));
    }

    /** Chi e' entrato riparte da zero. */
    public static function azzera(string $email): void
    {
        Database::connection()->prepare('DELETE FROM login_failures WHERE email = :e')
            ->execute(['e' => self::normalizza($email)]);
    }

    /**
     * L'avviso dopo una password sbagliata: quello di sempre, poi dalla
     * terza quanti tentativi restano, e alla quinta il blocco.
     */
    public static function avvisoErrore(string $email, string $ip): string
    {
        $blocco = self::blocco($email, $ip);

        if ($blocco !== null) {
            return self::messaggio($blocco);
        }

        $restano = self::restano($email);
        $base = 'Email o password non corretti.';

        if (self::MAX_PER_INDIRIZZO - $restano < self::AVVISO_DA) {
            return $base;
        }

        return $base . ' ' . ($restano === 1 ? 'Ancora 1 tentativo' : 'Ancora ' . $restano . ' tentativi')
            . ', poi l’account si blocca per un’ora.';
    }

    /**
     * La frase per chi e' fermato: perche', quanto aspettare e che cosa si
     * puo' fare intanto.
     *
     * @param array{motivo: string, minuti: int} $blocco
     */
    public static function messaggio(array $blocco): string
    {
        $fra = $blocco['minuti'] === 1 ? '1 minuto' : $blocco['minuti'] . ' minuti';

        if ($blocco['motivo'] === self::ACCOUNT) {
            return 'Per sicurezza l’account è bloccato per un’ora: la password è stata sbagliata '
                . self::MAX_PER_INDIRIZZO . ' volte. Riprova fra ' . $fra
                . ', oppure reimposta la password con «Password dimenticata?».';
        }

        return 'Troppi tentativi di accesso con password sbagliata da questa connessione. Riprova fra '
            . $fra . '.';
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

    /** Le password sbagliate dell'ultima ora per un indirizzo. */
    private static function erroriRecenti(string $email): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM login_failures
              WHERE email = :e AND created_at > NOW() - INTERVAL ' . self::BLOCCO_INDIRIZZO_SECONDI . ' SECOND'
        );
        $stmt->execute(['e' => $email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Secondi che mancano alla fine del blocco dell'account; 0 se non c'e'.
     * Con cinque errori nell'ultima ora il blocco dura un'ora dall'ultimo,
     * che e' il quinto: durante il blocco non se ne registrano altri.
     */
    private static function secondiBloccoIndirizzo(string $email): int
    {
        if (self::erroriRecenti($email) < self::MAX_PER_INDIRIZZO) {
            return 0;
        }

        $stmt = Database::connection()->prepare(
            'SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MAX(created_at) + INTERVAL '
                . self::BLOCCO_INDIRIZZO_SECONDI . ' SECOND))
               FROM login_failures WHERE email = :e'
        );
        $stmt->execute(['e' => $email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Secondi che mancano alla fine del blocco dell'IP; 0 se non c'e'. Il
     * blocco dura finche' nel quarto d'ora restano 50 errori: finisce quando
     * esce il piu' vecchio di questi, cioe' il cinquantesimo dal piu' recente.
     */
    private static function secondiBloccoIp(string $ip): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL ' . self::FINESTRA_IP_SECONDI . ' SECOND))
               FROM login_failures
              WHERE ip = :v
                AND created_at > NOW() - INTERVAL ' . self::FINESTRA_IP_SECONDI . ' SECOND
              ORDER BY created_at DESC, id DESC
              LIMIT 1 OFFSET ' . (self::MAX_PER_IP - 1)
        );
        $stmt->execute(['v' => $ip]);
        $resta = $stmt->fetchColumn();

        return $resta === false ? 0 : (int) $resta;
    }
}
