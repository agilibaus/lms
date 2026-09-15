<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Protezione CSRF: un token per sessione, verificato dal Router su ogni POST.
 *
 * Senza questa barriera una pagina esterna potrebbe far eseguire a un utente
 * gia' autenticato azioni sensibili (cambio ruolo, reset password, eliminazioni)
 * semplicemente inviando un form verso l'applicazione.
 */
class Csrf
{
    public const FIELD = '_token';

    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Campo nascosto da includere in ogni form POST.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function isValid(?string $token): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Rigenera il token: da chiamare al login e al logout, insieme alla
     * rigenerazione dell'id di sessione.
     */
    public static function rotate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
