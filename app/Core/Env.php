<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Loader minimale per file .env — niente dipendenze esterne.
 */
class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Esiste ma non si legge (permessi): come se non ci fosse, e valgono
        // le variabili d'ambiente del server.
        if ($lines === false) {
            return;
        }

        // Il Blocco note di Windows salva facilmente con il BOM in testa: senza
        // toglierlo, la prima chiave si chiamava «\xEF\xBB\xBFDB_HOST» e veniva
        // ignorata in silenzio.
        if ($lines !== [] && str_starts_with($lines[0], "\xEF\xBB\xBF")) {
            $lines[0] = substr($lines[0], 3);
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            self::$vars[trim($key)] = self::senzaVirgolette(trim($value));
        }
    }

    /**
     * Toglie una coppia di virgolette uguali che apre e chiude il valore, e
     * nient'altro: una virgoletta sola, in testa o in fondo, fa parte del
     * valore (una password come `abc"` perdeva l'ultimo carattere). Un
     * commento in coda (`valore # nota`) resta nel valore, deciso il 16/10:
     * una password puo' contenere « #».
     */
    private static function senzaVirgolette(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }

        $fromEnv = getenv($key);

        return $fromEnv !== false ? $fromEnv : $default;
    }
}
