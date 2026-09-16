<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Composizione degli URL assoluti da mettere nelle email.
 *
 * Usa APP_URL quando è configurato; altrimenti ricostruisce l'indirizzo dalla
 * richiesta in corso, così i link funzionano anche in un'installazione locale
 * in cui APP_URL non è stato compilato.
 */
class Url
{
    public static function base(): string
    {
        $configured = trim((string) Env::get('APP_URL', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return ($https ? 'https://' : 'http://') . $host;
    }

    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}
