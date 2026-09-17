<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Costruisce l'HTML di embed video in base al provider configurato per la lezione.
 * Nessuna dipendenza JS: iframe per i provider esterni, tag <video> per i file
 * self-hosted (serviti in streaming autenticato da LessonController::streamVideo,
 * mai esposti direttamente sotto /public).
 */
class VideoEmbed
{
    /**
     * @param bool $deferred true mette l'indirizzo in data-src invece che in
     *                       src: l'iframe non viene caricato finche' qualcuno
     *                       non preme il pulsante di avvio. Serve alle lezioni
     *                       di solo video, dove l'avvio va rilevato senza
     *                       dipendere dal protocollo del player esterno — e
     *                       come effetto la pagina non contatta Bunny o
     *                       Cloudflare finche' nessuno guarda.
     */
    public static function render(string $provider, ?string $videoRef, int $lessonId, bool $deferred = false): string
    {
        if ($provider === 'none' || $videoRef === null || $videoRef === '') {
            return '';
        }

        return match ($provider) {
            'bunny' => self::bunny($videoRef, $deferred),
            'cloudflare' => self::cloudflare($videoRef, $deferred),
            'self_hosted' => self::selfHosted($lessonId),
            default => '',
        };
    }

    /**
     * I provider che vivono dentro un iframe: di loro non sappiamo cosa
     * succede, quindi l'avvio si rileva dal pulsante nostro.
     */
    public static function isIframeProvider(string $provider): bool
    {
        return $provider === 'bunny' || $provider === 'cloudflare';
    }

    private static function bunny(string $videoId, bool $deferred = false): string
    {
        $libraryId = Env::get('BUNNY_LIBRARY_ID', '');

        if ($libraryId === '') {
            return '<p class="video-embed-error">Video Bunny Stream non configurato (manca BUNNY_LIBRARY_ID nel .env).</p>';
        }

        $src = sprintf(
            'https://iframe.mediadelivery.net/embed/%s/%s',
            rawurlencode($libraryId),
            rawurlencode($videoId)
        );

        return self::iframe($src, $deferred);
    }

    private static function cloudflare(string $videoId, bool $deferred = false): string
    {
        $customerCode = Env::get('CLOUDFLARE_STREAM_CUSTOMER_CODE', '');

        if ($customerCode === '') {
            return '<p class="video-embed-error">Video Cloudflare Stream non configurato (manca CLOUDFLARE_STREAM_CUSTOMER_CODE nel .env).</p>';
        }

        $src = sprintf(
            'https://customer-%s.cloudflarestream.com/%s/iframe',
            rawurlencode($customerCode),
            rawurlencode($videoId)
        );

        return self::iframe($src, $deferred);
    }

    private static function selfHosted(int $lessonId): string
    {
        // Il file fisico non è mai esposto sotto /public: l'src passa da un
        // endpoint autenticato che verifica l'iscrizione al corso e supporta
        // le richieste Range per consentire il seek nel player.
        $src = '/lessons/' . $lessonId . '/video';

        return sprintf(
            '<div class="video-embed"><video controls preload="metadata" src="%s"></video></div>',
            htmlspecialchars($src)
        );
    }

    private static function iframe(string $src, bool $deferred = false): string
    {
        if ($deferred) {
            // L'avvio automatico serve perche' il clic sul pulsante nostro
            // valga anche come clic sul play: altrimenti sarebbero due.
            $separator = str_contains($src, '?') ? '&' : '?';

            return sprintf(
                '<div class="video-embed"><iframe data-src="%s" loading="lazy" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe></div>',
                htmlspecialchars($src . $separator . 'autoplay=true')
            );
        }

        return sprintf(
            '<div class="video-embed"><iframe src="%s" loading="lazy" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe></div>',
            htmlspecialchars($src)
        );
    }
}
