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

    /** I check di questa patch; sito e disco arrivano con `bin/sorveglia`. */
    public const CHECK = [self::AVVISI, self::INVITI, self::RILASCIO, self::POSTA];

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
     * «Manda un segnale di prova»: un segnale a ogni check (la posta con il
     * suo stato vero), e per ciascuno la risposta del servizio: 200 arrivato,
     * 404 un check con quel nome non c'e', 0 nessuna risposta.
     *
     * @return array<string, int>
     */
    public static function prova(): array
    {
        $esiti = [];

        foreach (self::CHECK as $check) {
            $esiti[$check] = $check === self::POSTA ? self::chiamaPosta() : self::chiama($check, true, 'prova dal pannello');
        }

        return $esiti;
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
