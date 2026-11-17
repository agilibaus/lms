<?php

declare(strict_types=1);

namespace App\Core\Google;

use App\Core\Env;
use App\Core\Settings;

/**
 * Dove sta la chiave dell'account di servizio di Google (17/11, Alessandro,
 * strada B).
 *
 * La chiave caricata dal pannello sta in `storage/google`, con un nome
 * casuale. Fino al 17/11 in tabella si salvava il percorso completo
 * (`C:\laragon\www\lms\...\storage\google\....json`): copiando il database
 * su un altro server, o spostando la cartella del sito, Meet smetteva di
 * funzionare senza dirlo. Adesso in tabella c'e' solo il nome del file, e il
 * file si cerca sempre nella cartella `storage/google` di questo sito.
 *
 * LE DUE PROVENIENZE, trattate in modo diverso:
 * - **dalla tabella** (il pannello): conta solo il nome del file. Un valore
 *   di prima, con il percorso completo, vale per il suo nome: la chiave gia'
 *   caricata continua a funzionare senza migrazione. Fuori da
 *   `storage/google` non si legge niente, qualunque cosa ci sia scritto;
 * - **dal `.env`**: il percorso com'e' scritto, come dice il manuale
 *   (`/percorso/protetto/credenziali.json`). Il `.env` sta sul server e non
 *   viaggia con il database: il problema li' non c'e'.
 */
final class ChiaveGoogle
{
    public const IMPOSTAZIONE = 'GOOGLE_SERVICE_ACCOUNT_JSON';

    /** La cartella delle chiavi caricate dal pannello. */
    public static function cartella(): string
    {
        $cartella = dirname(__DIR__, 3) . '/storage/google';

        return realpath($cartella) ?: $cartella;
    }

    /**
     * Il percorso del file della chiave in vigore, o '' se non ce n'e'.
     * Non dice se il file esiste: lo controlla chi lo usa.
     */
    public static function percorso(): string
    {
        $inTabella = trim((string) (Settings::stored(self::IMPOSTAZIONE) ?? ''));

        if ($inTabella !== '') {
            return self::nellaCartella($inTabella, self::cartella());
        }

        return trim((string) Env::get(self::IMPOSTAZIONE, ''));
    }

    /**
     * Il valore della tabella ridotto al nome del file, dentro la cartella.
     * '' se il nome non e' quello di un file JSON semplice.
     */
    public static function nellaCartella(string $valore, string $cartella): string
    {
        // Anche le barre rovesciate di Windows: il valore puo' venire dal
        // PC di sviluppo e essere letto su Linux.
        $nome = basename(str_replace('\\', '/', $valore));

        if (preg_match('/^[A-Za-z0-9_-]+\.json$/', $nome) !== 1) {
            return '';
        }

        return rtrim($cartella, '/\\') . '/' . $nome;
    }

    /** Il nome da salvare in tabella per un file appena caricato. */
    public static function nome(string $percorso): string
    {
        return basename(str_replace('\\', '/', $percorso));
    }

    /** Il file e' una chiave caricata dal pannello (e quindi si puo' cancellare). */
    public static function eNostra(string $percorso): bool
    {
        $vero = realpath($percorso);

        return $vero !== false && dirname($vero) === self::cartella();
    }
}
