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

    /**
     * Oggetto e testo delle email delle sessioni live. Svuotare un campo qui
     * non restituisce il comando al .env — quei testi nel file non ci sono
     * mai stati — ma al testo predefinito in LiveSessionMail.
     */
    public const LIVE_MAIL_KEYS = [
        'LIVE_INVITE_SUBJECT',
        'LIVE_INVITE_BODY',
        'LIVE_UPDATE_SUBJECT',
        'LIVE_UPDATE_BODY',
        'LIVE_CANCEL_SUBJECT',
        'LIVE_CANCEL_BODY',
    ];

    public const GOOGLE_KEYS = [
        'GOOGLE_SERVICE_ACCOUNT_JSON',
        'GOOGLE_IMPERSONATE_EMAIL',
        'GOOGLE_CALENDAR_ID',
        'GOOGLE_CALENDAR_TIMEZONE',
    ];

    /**
     * Aspetto delle pagine pubbliche. `AUTH_LAYOUT` sceglie la struttura; i
     * due testi servono solo all'aspetto affiancato e, svuotati, tornano ai
     * predefiniti in AuthLayout::DEFAULTS, non al .env.
     */
    public const APPEARANCE_KEYS = [
        'AUTH_LAYOUT',
        'AUTH_SPLIT_TITLE',
        'AUTH_SPLIT_TEXT',
        'THEME_PALETTE',
        'THEME_PRIMARY',
        'THEME_RADIUS',
        'THEME_TEXT_SIZE',
        'THEME_TEXT_COLOR',
        'THEME_SCENE_SIZE',
        'THEME_FONT_AUTH',
        'THEME_FONT_APP',
        'THEME_ELEMENTI',
    ];

    /**
     * Bunny Stream: libreria, chiave di firma degli embed e durata del token.
     * `BUNNY_LIBRARY_ID` esisteva gia' nel .env e continua a funzionare da li'
     * se in tabella non c'e' niente.
     */
    public const BUNNY_KEYS = [
        'BUNNY_LIBRARY_ID',
        'BUNNY_TOKEN_KEY',
        'BUNNY_TOKEN_TTL_HOURS',
    ];

    /**
     * Il video di benvenuto, mostrato una volta al primo accesso di uno
     * studente. Provider e identificativo, come per le lezioni.
     */
    public const WELCOME_KEYS = [
        'WELCOME_VIDEO_PROVIDER',
        'WELCOME_VIDEO_REF',
    ];

    /** Chiavi da non rimandare mai al browser. */
    public const SECRET_KEYS = [
        'MAIL_PASSWORD',
        'BUNNY_TOKEN_KEY',
    ];

    public static function isWritable(string $key): bool
    {
        return in_array($key, self::MAIL_KEYS, true)
            || in_array($key, self::GOOGLE_KEYS, true)
            || in_array($key, self::LIVE_MAIL_KEYS, true)
            || in_array($key, self::APPEARANCE_KEYS, true)
            || in_array($key, self::BUNNY_KEYS, true)
            || in_array($key, self::WELCOME_KEYS, true);
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
     * Chiavi che scrive la piattaforma stessa, non chi amministra.
     *
     * Stanno fuori da `isWritable()` di proposito: quell'elenco esiste per
     * impedire che un POST costruito a mano scriva qualunque cosa in
     * tabella, e una traccia di esecuzione non e' un'impostazione da
     * modificare dal pannello. Il pannello la legge e basta.
     */
    public const SYSTEM_KEYS = [
        'DRIP_LAST_RUN_AT',
    ];

    /**
     * Scrive una delle chiavi di sistema. Stesso meccanismo di `set()`, ma
     * con il proprio elenco chiuso e senza un utente che l'ha cambiata.
     */
    public static function recordSystem(string $key, string $value): void
    {
        if (!in_array($key, self::SYSTEM_KEYS, true)) {
            throw new \InvalidArgumentException('Chiave di sistema sconosciuta: ' . $key);
        }

        self::load();

        $stmt = Database::connection()->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['k' => $key, 'v' => $value]);

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
