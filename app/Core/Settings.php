<?php

declare(strict_types=1);

namespace App\Core;

use PDOException;

/**
 * Impostazioni modificabili dal pannello, con il .env come ripiego.
 *
 * Le chiavi hanno gli stessi nomi delle variabili d'ambiente (MAIL_HOST,
 * GOOGLE_CALENDAR_ID...) proprio perche' il ripiego sia naturale: se in
 * tabella non c'e' niente, si legge il file come si e' sempre fatto.
 *
 * L'ordine e' voluto. Chi amministra puo' cambiare la configurazione senza
 * toccare un file che contiene anche le credenziali del database, e se un
 * valore inserito dal pannello risulta sbagliato basta svuotarlo per tornare
 * a quello del .env: nessuna pagina web scrive mai in quel file.
 *
 * Il rovescio della medaglia: la password SMTP finisce nel database, quindi
 * nei backup. La chiave privata di Google no, resta un file in /storage e in
 * tabella se ne conserva solo il percorso.
 */
class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * Chiavi che il pannello puo' scrivere. Un elenco chiuso: senza, un POST
     * costruito a mano potrebbe scrivere qualunque cosa in tabella.
     */
    public const MAIL_KEYS = [
        'MAIL_TRANSPORT',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_ENCRYPTION',
    ];

    public const GOOGLE_KEYS = [
        'GOOGLE_SERVICE_ACCOUNT_JSON',
        'GOOGLE_IMPERSONATE_EMAIL',
        'GOOGLE_CALENDAR_ID',
        'GOOGLE_CALENDAR_TIMEZONE',
    ];

    /** Chiavi da non rimandare mai al browser. */
    public const SECRET_KEYS = [
        'MAIL_PASSWORD',
    ];

    public static function isWritable(string $key): bool
    {
        return in_array($key, self::MAIL_KEYS, true) || in_array($key, self::GOOGLE_KEYS, true);
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    /**
     * Valore in vigore: tabella, poi .env, poi il valore di riserva.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();

        if (isset(self::$cache[$key]) && self::$cache[$key] !== '') {
            return self::$cache[$key];
        }

        return Env::get($key, $default);
    }

    /**
     * Da dove arriva il valore in vigore: serve al pannello per dire
     * "questo viene dal file, non l'hai scritto tu qui".
     *
     * @return string 'database' | 'file' | 'assente'
     */
    public static function source(string $key): string
    {
        self::load();

        if (isset(self::$cache[$key]) && self::$cache[$key] !== '') {
            return 'database';
        }

        $fromEnv = Env::get($key, '');

        return $fromEnv !== null && $fromEnv !== '' ? 'file' : 'assente';
    }

    /**
     * Valore scritto in tabella, senza ripiego: il pannello riempie i campi
     * con questo, per non far sembrare "salvato qui" cio' che sta nel file.
     */
    public static function stored(string $key): ?string
    {
        self::load();

        return self::$cache[$key] ?? null;
    }

    /**
     * Scrive una chiave; null o stringa vuota cancellano la riga, cioe'
     * restituiscono il comando al .env.
     */
    public static function set(string $key, ?string $value, ?int $userId = null): void
    {
        if (!self::isWritable($key)) {
            throw new \InvalidArgumentException('Impostazione non consentita: ' . $key);
        }

        // Prima di toccare la cache va riempita: altrimenti passerebbe da null
        // a un array con la sola chiave appena scritta, e load() la darebbe
        // per gia' caricata — nella stessa richiesta ogni altra lettura
        // tornerebbe vuota, ripiegando sul .env.
        self::load();

        $pdo = Database::connection();

        if ($value === null || $value === '') {
            $pdo->prepare('DELETE FROM settings WHERE setting_key = :k')->execute(['k' => $key]);
            unset(self::$cache[$key]);

            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_by)
             VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        $stmt->execute(['k' => $key, 'v' => $value, 'u' => $userId]);

        self::$cache[$key] = $value;
    }

    /**
     * Quando dell'ultima modifica dal pannello, per le due pagine.
     */
    public static function lastUpdate(array $keys): ?array
    {
        try {
            $in = implode(',', array_fill(0, count($keys), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT s.setting_key, s.updated_at, u.full_name
                 FROM settings s
                 LEFT JOIN users u ON u.id = s.updated_by
                 WHERE s.setting_key IN ($in)
                 ORDER BY s.updated_at DESC
                 LIMIT 1"
            );
            $stmt->execute(array_values($keys));
            $row = $stmt->fetch();

            return $row === false ? null : $row;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Svuota la cache: dopo un salvataggio il trasporto della posta va
     * ricostruito con i valori nuovi, nella stessa richiesta.
     */
    public static function forget(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        try {
            $rows = Database::connection()
                ->query('SELECT setting_key, setting_value FROM settings')
                ->fetchAll();

            foreach ($rows as $row) {
                self::$cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (PDOException $e) {
            // Tabella non ancora creata (installazione appena aggiornata, o
            // migrazione non eseguita): si va avanti con il solo .env.
            self::$cache = [];
        }
    }
}
