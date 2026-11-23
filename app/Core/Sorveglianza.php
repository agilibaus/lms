<?php

declare(strict_types=1);

namespace App\Core;

/**
 * La sorveglianza esterna con Healthchecks.io (23/11, Alessandro; la
 * proposta «Avvisi via SMS»).
 *
 * COME FUNZIONA. Ogni comando pianificato, a giro finito, manda un segnale a
 * un «check» di Healthchecks.io: «tutto bene», oppure «qualcosa non va»
 * (`/fail`). Se un segnale non arriva in tempo, o arriva un «qualcosa non
 * va», e' Healthchecks.io ad avvisare, per SMS o per email. Pistacchio non
 * manda SMS e non conosce numeri di telefono: conosce una chiave sola.
 *
 * LA CHIAVE. E' la «ping key» del progetto su Healthchecks.io; i check si
 * raggiungono per nome, `<base>/<chiave>/<nome-del-check>`. Chi la conosce
 * puo' mandare falsi «tutto bene» e tenere zitti gli avvisi: si tratta come
 * la chiave di Bunny, si scrive nel pannello e non torna mai al browser.
 * Senza chiave la sorveglianza e' spenta e i comandi girano come prima.
 *
 * LA PROTEZIONE DELL'INDIRIZZO (23/11, Alessandro). Con la chiave si salva
 * l'indirizzo del sito (`APP_URL`) in cui e' stata scritta, e i segnali
 * partono solo da li'. Un database di produzione copiato in locale porta con
 * se' la chiave: senza questa protezione il computer di sviluppo
 * manderebbe segnali ai check veri, e spegnendolo la sera partirebbero gli
 * SMS. Una chiave scritta nel `.env` e' di quell'installazione: vale
 * ovunque.
 *
 * MAI UN ERRORE. Un servizio che non risponde non ferma il comando che
 * segnala: attesa massima di 5 secondi, nessuna eccezione verso fuori.
 *
 * IL SITO E LO SPAZIO (`bin/sorveglia`, ogni 5 minuti): il sito si apre
 * da `APP_URL/salute`, come lo apre un visitatore, e lo spazio si misura
 * una volta all'ora contro lo spazio del piano scritto nel pannello.
 *
 * NIENTE DATI DI PERSONE. Ai check arrivano nomi dei check, orari e numeri
 * («2 email in coda da piu' di un'ora»), mai indirizzi o nomi: il registro
 * di `bin/avvisi` scrive gli indirizzi delle email non partite, e quel testo
 * non va spedito fuori.
 */
final class Sorveglianza
{
    public const KEY = 'HEALTHCHECKS_PING_KEY';

    /** L'indirizzo del sito (`APP_URL`) in cui la chiave e' stata salvata. */
    public const KEY_URL = 'HEALTHCHECKS_PER_URL';

    public const AVVISI = 'pistacchio-avvisi';
    public const INVITI = 'pistacchio-inviti';
    public const RILASCIO = 'pistacchio-rilascio';
    public const POSTA = 'pistacchio-posta';
    public const SITO = 'pistacchio-sito';
    public const DISCO = 'pistacchio-disco';

    /** Tutti i check, nell'ordine del pannello. Sito e disco li segnala `bin/sorveglia`. */
    public const CHECK = [self::AVVISI, self::INVITI, self::RILASCIO, self::POSTA, self::SITO, self::DISCO];

    /** Lo spazio del piano di hosting, in GB: vuoto, il controllo dello spazio e' spento. */
    public const KEY_SPAZIO = 'SPAZIO_PIANO_GB';

    /** Oltre questa parte dello spazio del piano, «qualcosa non va». */
    public const SOGLIA_DISCO = 0.8;

    /** Il sito «non va» dopo tanti controlli falliti di seguito: uno solo e' un riavvio. */
    public const FALLIMENTI_DEL_SITO = 2;

    /** Ogni quanto si misura lo spazio, in secondi. */
    public const OGNI_QUANTO_IL_DISCO = 3600;

    public const SPENTA = 'spenta';
    public const ATTIVA = 'attiva';
    public const ALTROVE = 'altrove';

    /** Il servizio pubblico; un Healthchecks installato in proprio si scrive nel `.env`. */
    private const BASE = 'https://hc-ping.com';

    /** Quanto si aspetta il servizio, in secondi. */
    private const ATTESA = 5;

    /** Dopo quanti minuti un'email gia' tentata e ancora in coda accende l'allarme. */
    public const MINUTI_DI_POSTA = 60;

    /** La chiave: lettere, cifre, trattino e trattino basso (una ping key di Healthchecks.io). */
    public static function chiaveValida(string $chiave): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $chiave) === 1;
    }

    /**
     * «spenta» senza chiave; «attiva» con la chiave del `.env`, o con quella
     * del pannello salvata per questo stesso indirizzo; «altrove» con una
     * chiave salvata per un altro indirizzo.
     */
    public static function stato(): string
    {
        if (trim((string) Settings::get(self::KEY, '')) === '') {
            return self::SPENTA;
        }

        if (Settings::source(self::KEY) === 'file') {
            return self::ATTIVA;
        }

        $per = self::salvataPer();

        return $per !== null && $per === Url::base() ? self::ATTIVA : self::ALTROVE;
    }

    /** L'indirizzo per cui la chiave del pannello e' stata salvata, o null. */
    public static function salvataPer(): ?string
    {
        $per = trim((string) (Settings::stored(self::KEY_URL) ?? ''));

        return $per === '' ? null : $per;
    }

    /** Il pannello scrive la chiave insieme all'indirizzo in cui la si scrive. */
    public static function salva(string $chiave, ?int $userId): void
    {
        Settings::set(self::KEY, $chiave, $userId);
        Settings::set(self::KEY_URL, Url::base(), $userId);
    }

    public static function cancella(?int $userId): void
    {
        Settings::set(self::KEY, null, $userId);
        Settings::set(self::KEY_URL, null, $userId);
    }

    /** L'indirizzo a cui va il segnale di un check. */
    public static function indirizzo(string $check, bool $bene = true): string
    {
        $base = rtrim((string) Env::get('HEALTHCHECKS_BASE_URL', self::BASE), '/');

        return $base . '/' . rawurlencode(trim((string) Settings::get(self::KEY, ''))) . '/' . rawurlencode($check)
            . ($bene ? '' : '/fail');
    }

    /**
     * Manda il segnale di un check, se la sorveglianza e' attiva. Vero se il
     * servizio l'ha ricevuto; mai un'eccezione.
     */
    public static function segnala(string $check, bool $bene = true, string $messaggio = ''): bool
    {
        try {
            return self::chiama($check, $bene, $messaggio) === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Il segnale della posta: «qualcosa non va» se c'e' almeno un'email gia'
     * tentata, ancora in coda e nata da piu' di un'ora; altrimenti «tutto
     * bene». Le email «non partite» (oltre le 24 ore di tentativi) non
     * contano: un indirizzo sbagliato di settimane fa non tiene acceso
     * l'allarme. Gli inviti agli utenti importati non passano dalla coda.
     */
    public static function segnalaPosta(): bool
    {
        try {
            return self::chiamaPosta() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Quante email accenderebbero l'allarme della posta. */
    public static function emailFerme(): int
    {
        return (int) Database::connection()->query(
            'SELECT COUNT(*) FROM email_queue
             WHERE attempts > 0
               AND created_at <= NOW() - INTERVAL ' . self::MINUTI_DI_POSTA . ' MINUTE
               AND retry_until > NOW()'
        )->fetchColumn();
    }

    /**
     * «Manda un segnale di prova»: un segnale a ogni check (la posta e lo
     * spazio con il loro stato vero, se c'e'), e per ciascuno la risposta
     * del servizio: 200 arrivato, 404 un check con quel nome non c'e', 0
     * nessuna risposta.
     *
     * @return array<string, int>
     */
    public static function prova(): array
    {
        $esiti = [];

        foreach (self::CHECK as $check) {
            $esiti[$check] = match (true) {
                $check === self::POSTA => self::chiamaPosta(),
                $check === self::DISCO && self::spazioPiano() !== null => self::chiamaDisco(),
                default => self::chiama($check, true, 'prova dal pannello'),
            };
        }

        return $esiti;
    }

    // ---------------------------------------------------------------
    // Il sito e lo spazio, per `bin/sorveglia`
    // ---------------------------------------------------------------

    /**
     * Un giro del controllo del sito: apre l'indirizzo (di solito
     * `APP_URL/salute`) in https, con il certificato verificato, come un
     * visitatore. Una risposta buona (200, «ok») manda «tutto bene» e azzera
     * il conto; un controllo fallito non manda niente; il secondo di seguito
     * manda «qualcosa non va». Cosi' un riavvio di un minuto non costa un SMS,
     * e un sito giu' davvero si segnala al secondo giro. Il conto sta in un
     * file, perche' con il database fermo non si scriverebbe.
     *
     * @return string 'bene' | 'primo' (primo fallimento, niente segnale) | 'giu'
     */
    public static function giroSito(string $indirizzo, string $fileStato): string
    {
        [$codice, $corpo] = self::apri($indirizzo);
        $stato = self::leggiStato($fileStato);

        if ($codice === 200 && strtolower(trim($corpo)) === 'ok') {
            $stato['fallimenti'] = 0;
            self::scriviStato($fileStato, $stato);
            self::segnala(self::SITO, true);

            return 'bene';
        }

        $stato['fallimenti'] = (int) ($stato['fallimenti'] ?? 0) + 1;
        self::scriviStato($fileStato, $stato);

        if ($stato['fallimenti'] < self::FALLIMENTI_DEL_SITO) {
            return 'primo';
        }

        self::segnala(self::SITO, false, $codice === 0
            ? 'la pagina /salute non risponde'
            : 'la pagina /salute risponde ' . $codice);

        return 'giu';
    }

    /**
     * Lo spazio, se e' passata un'ora dall'ultima misura (`bin/sorveglia`
     * gira ogni 5 minuti). Con il campo dello spazio vuoto non misura e non
     * segnala niente.
     *
     * @return string 'spento' | 'presto' | 'misurato'
     */
    public static function giroDisco(string $fileStato, int $adesso): string
    {
        if (self::spazioPiano() === null) {
            return 'spento';
        }

        $stato = self::leggiStato($fileStato);

        if ($adesso - (int) ($stato['disco'] ?? 0) < self::OGNI_QUANTO_IL_DISCO) {
            return 'presto';
        }

        $stato['disco'] = $adesso;
        self::scriviStato($fileStato, $stato);
        self::chiamaDisco();

        return 'misurato';
    }

    /** Lo spazio del piano in GB, o null se non e' scritto. */
    public static function spazioPiano(): ?float
    {
        $valore = str_replace(',', '.', trim((string) Settings::get(self::KEY_SPAZIO, '')));

        return is_numeric($valore) && (float) $valore > 0 ? (float) $valore : null;
    }

    /**
     * Lo spazio usato: `storage/`, dove stanno tutti i file caricati, e il
     * database. Su un hosting condiviso conta la quota dell'account, non lo
     * spazio libero del disco: per questo si sommano le due cose e si
     * confrontano con lo spazio del piano.
     */
    public static function spazioUsato(): int
    {
        $byte = 0;
        $cartella = dirname(__DIR__, 2) . '/storage';

        if (is_dir($cartella)) {
            $file = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($file as $f) {
                if ($f->isFile()) {
                    $byte += (int) $f->getSize();
                }
            }
        }

        $byte += (int) Database::connection()->query(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()'
        )->fetchColumn();

        return $byte;
    }

    /** «uso 82% di 10 GB»: la parte usata, in testo. */
    public static function descriviSpazio(int $usati, float $pianoGb): string
    {
        $parte = $usati / ($pianoGb * 1024 ** 3);

        return 'uso ' . (int) round($parte * 100) . '% di ' . rtrim(rtrim(number_format($pianoGb, 2, '.', ''), '0'), '.') . ' GB';
    }

    private static function chiamaDisco(): int
    {
        $piano = self::spazioPiano();

        if ($piano === null || self::stato() !== self::ATTIVA) {
            return 0;
        }

        $usati = self::spazioUsato();
        $bene = $usati / ($piano * 1024 ** 3) <= self::SOGLIA_DISCO;

        return self::chiama(self::DISCO, $bene, self::descriviSpazio($usati, $piano));
    }

    /** @return array{0: int, 1: string} il codice e il corpo, [0, ''] senza risposta */
    private static function apri(string $indirizzo): array
    {
        if (!function_exists('curl_init')) {
            return [0, ''];
        }

        try {
            $ch = curl_init($indirizzo);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $corpo = curl_exec($ch);
            $codice = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $corpo === false ? [0, ''] : [$codice, (string) $corpo];
        } catch (\Throwable) {
            return [0, ''];
        }
    }

    /** @return array<string, int> */
    private static function leggiStato(string $file): array
    {
        $dati = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($dati) ? $dati : [];
    }

    /** @param array<string, int> $stato */
    private static function scriviStato(string $file, array $stato): void
    {
        @file_put_contents($file, (string) json_encode($stato));
    }

    private static function chiamaPosta(): int
    {
        // Senza sorveglianza la coda non si conta nemmeno.
        if (self::stato() !== self::ATTIVA) {
            return 0;
        }

        try {
            $ferme = self::emailFerme();
        } catch (\Throwable) {
            // Il database non risponde: il segnale non parte, e l'assenza
            // accende comunque l'allarme quando passa il margine.
            return 0;
        }

        return $ferme > 0
            ? self::chiama(self::POSTA, false, $ferme === 1 ? '1 email in coda da più di un\'ora' : $ferme . ' email in coda da più di un\'ora')
            : self::chiama(self::POSTA, true);
    }

    /** Il codice della risposta, 0 se non c'e' stata. */
    private static function chiama(string $check, bool $bene, string $messaggio = ''): int
    {
        if (self::stato() !== self::ATTIVA || !function_exists('curl_init')) {
            return 0;
        }

        try {
            $ch = curl_init(self::indirizzo($check, $bene));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => mb_substr($messaggio, 0, 200),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::ATTESA,
                CURLOPT_CONNECTTIMEOUT => self::ATTESA,
            ]);
            $risposta = curl_exec($ch);
            $stato = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $risposta === false ? 0 : $stato;
        } catch (\Throwable) {
            return 0;
        }
    }
}
