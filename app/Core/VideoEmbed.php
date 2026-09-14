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
    public static function render(string $provider, ?string $videoRef, int $lessonId): string
    {
        if ($provider === 'none' || $videoRef === null || $videoRef === '') {
            return '';
        }

        return match ($provider) {
            'bunny' => self::bunny($videoRef),
            'cloudflare' => self::cloudflare($videoRef),
            'self_hosted' => self::selfHosted($lessonId),
            default => '',
        };
    }

    private static function bunny(string $videoId): string
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

        return self::iframe($src);
    }

    private static function cloudflare(string $videoId): string
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

        return self::iframe($src);
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

    private static function iframe(string $src): string
    {
        return sprintf(
            '<div class="video-embed"><iframe src="%s" loading="lazy" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe></div>',
            htmlspecialchars($src)
        );
    }
}
