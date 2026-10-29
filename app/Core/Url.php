<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Composizione degli URL assoluti da mettere nelle email.
 *
 * Usa APP_URL, che l'installazione scrive nel .env. Senza APP_URL, fino al
 * 29/10 ricostruiva l'indirizzo dalla richiesta in corso, cioe' dall'host
 * che dichiara chi fa la richiesta: chi chiedeva il recupero della password
 * di qualcun altro con un host suo faceva partire un'email con il link di
 * reimpostazione verso il proprio sito (verifica di sicurezza, punto 7).
 *
 * Dal 29/10 l'host della richiesta si usa solo se e' un indirizzo di
 * sviluppo sul proprio computer (`localhost`, `127.0.0.1`, `::1`, un nome
 * che finisce in `.test` o `.localhost`, come quelli di Laragon). Negli altri
 * casi i collegamenti puntano a `localhost`, sbagliati ma innocui, e il log
 * lo dice: la cura e' compilare APP_URL.
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
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        if (!self::sviluppo($host)) {
            error_log('[Url] APP_URL non è configurato nel .env: i collegamenti nelle email puntano a localhost.');
            $host = 'localhost';
        }

        return ($https ? 'https://' : 'http://') . $host;
    }

    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * Un host di sviluppo sul proprio computer, con la porta se c'e'.
     */
    public static function sviluppo(string $host): bool
    {
        $nome = strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? '');

        return in_array($nome, ['localhost', '127.0.0.1', '[::1]'], true)
            || (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.(test|localhost)$/', $nome);
    }
}
