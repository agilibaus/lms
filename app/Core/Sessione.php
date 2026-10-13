<?php

declare(strict_types=1);

namespace App\Core;

/**
 * L'avvio della sessione, con il cookie protetto (13/10, verifica di
 * sicurezza). Prima si usavano le impostazioni predefinite di PHP, che non
 * proteggono il cookie in nessuno dei tre modi:
 *
 *   - **HttpOnly**: il cookie non si legge da JavaScript. Se un giorno un
 *     testo riuscisse a far girare codice nella pagina, non potrebbe
 *     portarsi via la sessione;
 *   - **Secure** quando la piattaforma e' in https (la richiesta lo e', o
 *     APP_URL comincia con https): il cookie non viaggia mai in chiaro. In
 *     locale, in http, resta spento, o non si entrerebbe;
 *   - **SameSite=Lax**: un altro sito non manda il cookie con le sue
 *     richieste nascoste. Lax e non Strict: con Strict chi apre un link da
 *     un'email arriverebbe senza sessione e si troverebbe davanti
 *     l'accesso.
 *
 * E la modalita' rigida: PHP non accetta un identificativo di sessione che
 * non ha creato lui, cosi' nessuno puo' imporne uno scelto in anticipo.
 */
final class Sessione
{
    public static function avvia(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(self::parametri(self::inHttps()));
        session_start();
    }

    /**
     * @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    public static function parametri(bool $https): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function inHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || str_starts_with(strtolower(trim((string) Env::get('APP_URL', ''))), 'https://');
    }
}
