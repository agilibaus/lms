<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Firma degli indirizzi di Bunny Stream.
 *
 * Con la Token Authentication attiva nella libreria, un iframe senza token
 * valido riceve 403. Il token e' `SHA256(chiave + id del video + scadenza)` in
 * esadecimale, e viaggia nell'indirizzo insieme a `expires`, un istante UNIX in
 * secondi.
 *
 * **La chiave resta sul server.** Non compare mai nell'HTML: quello che arriva
 * al browser e' solo il risultato dell'hash, da cui la chiave non si ricava.
 *
 * **Il token non e' legato al video ma alla singola apertura della pagina.**
 * Ogni volta che lo studente apre la lezione ne riceve uno nuovo, quindi la
 * scadenza non gli impedisce mai di riprendere un video giorni dopo. Limita
 * due cose sole: una pagina lasciata aperta oltre la durata, e un indirizzo
 * copiato dagli strumenti per sviluppatori e passato a qualcun altro — che e'
 * poi il motivo per cui si firma.
 *
 * **Da verificare nell'ambiente di Elena** (da qui Bunny e' irraggiungibile,
 * pistacchio-lms.md Sezione 7.1): cosa succede se il token scade *mentre* il
 * video e' in riproduzione. La documentazione di Bunny non lo dice. La durata
 * predefinita di 12 ore e' scelta perche' quel caso non si presenti.
 */
class BunnyToken
{
    /** Durata predefinita del token, in ore. */
    public const DEFAULT_TTL_HOURS = 12;

    /** Limiti accettati dal pannello: sotto e' scomodo, sopra non protegge piu' niente. */
    public const MIN_TTL_HOURS = 1;
    public const MAX_TTL_HOURS = 168;

    /**
     * Vero se la firma e' configurata. Senza chiave l'indirizzo resta quello
     * di prima, non firmato: attivare la Token Authentication su Bunny prima
     * di aver messo la chiave qui spegnerebbe i video, e il contrario no.
     */
    public static function isConfigured(): bool
    {
        return self::key() !== '' && self::libraryId() !== '';
    }

    public static function libraryId(): string
    {
        return trim((string) Settings::get('BUNNY_LIBRARY_ID', ''));
    }

    private static function key(): string
    {
        return trim((string) Settings::get('BUNNY_TOKEN_KEY', ''));
    }

    public static function ttlHours(): int
    {
        $ore = (int) Settings::get('BUNNY_TOKEN_TTL_HOURS', (string) self::DEFAULT_TTL_HOURS);

        // Un valore fuori scala — riga scritta a mano in tabella, o un .env
        // vecchio — non deve produrre token gia' scaduti o eterni.
        if ($ore < self::MIN_TTL_HOURS || $ore > self::MAX_TTL_HOURS) {
            return self::DEFAULT_TTL_HOURS;
        }

        return $ore;
    }

    /**
     * Il token per un video a una data scadenza.
     *
     * L'istante e' un parametro e non viene calcolato qui dentro: cosi' il
     * calcolo e' verificabile con valori noti, senza dipendere dall'orologio.
     */
    public static function sign(string $videoId, int $expires, ?string $key = null): string
    {
        return hash('sha256', ($key ?? self::key()) . $videoId . $expires);
    }

    /**
     * Indirizzo di embed, firmato se la chiave c'e'.
     *
     * @param int|null $now istante di riferimento, per i test.
     */
    public static function embedUrl(string $videoId, ?int $now = null): string
    {
        $src = sprintf(
            'https://iframe.mediadelivery.net/embed/%s/%s',
            rawurlencode(self::libraryId()),
            rawurlencode($videoId)
        );

        if (!self::isConfigured()) {
            return $src;
        }

        $expires = ($now ?? time()) + self::ttlHours() * 3600;

        return $src . '?token=' . self::sign($videoId, $expires) . '&expires=' . $expires;
    }
}
