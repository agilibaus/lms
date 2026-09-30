<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Aspetto delle pagine pubbliche: accesso, registrazione, recupero e nuova
 * password, e la pagina di cambio password obbligato.
 *
 * Sono l'unica eccezione alla decisione di non offrire strutture di pagina
 * diverse (pistacchio-lms.md Sezione 8.5): qui non ci sono dati, ruoli,
 * stati ne' JavaScript, quindi due strutture non diventano due interfacce da
 * mantenere. Dentro l'applicazione la regola resta quella di prima.
 *
 * La scelta vale per tutte e cinque le pagine insieme, non per una sola:
 * cambiare aspetto nel giro di tre clic — accesso, "password dimenticata",
 * nuova password — si leggerebbe come un difetto.
 */
class AuthLayout
{
    /** Riquadro centrato sul fondo crema con i cerchi: com'e' sempre stato. */
    public const GUSCIO = 'guscio';

    /** Presentazione a sinistra, modulo a destra; su schermo stretto si impilano. */
    public const AFFIANCATO = 'affiancato';

    public const CHOICES = [
        self::GUSCIO => 'Guscio — riquadro centrato',
        self::AFFIANCATO => 'Affiancato — presentazione a sinistra, modulo a destra',
    ];

    /**
     * Testi della sezione di presentazione, usati solo dall'aspetto
     * affiancato. Stanno in `settings` come i testi delle email delle
     * sessioni live, e per lo stesso motivo: sono parole che si riscrivono.
     * Svuotare un campo torna a questi valori, non al .env — nel file non ci
     * sono mai stati.
     */
    public const DEFAULTS = [
        'AUTH_SPLIT_TITLE' => "Impara con calma,\nal tuo passo.",
        'AUTH_SPLIT_TEXT' => 'Corsi, incontri dal vivo e materiali, in un unico posto. '
            . 'Riprendi da dove eri rimasto, quando ti è comodo.',
    ];

    public static function current(): string
    {
        $scelta = (string) Settings::get('AUTH_LAYOUT', self::GUSCIO);

        // Un valore non previsto — riga scritta a mano in tabella, o aspetto
        // tolto in futuro — non deve lasciare una pagina senza vestito.
        return array_key_exists($scelta, self::CHOICES) ? $scelta : self::GUSCIO;
    }

    /**
     * Titolo della presentazione. Gli a capo scritti dall'admin contano:
     * decidere dove spezza il titolo e' l'unico modo per non ritrovarsi una
     * parola sola sull'ultima riga al variare della larghezza.
     */
    public static function title(): string
    {
        return self::testo('AUTH_SPLIT_TITLE');
    }

    public static function text(): string
    {
        return self::testo('AUTH_SPLIT_TEXT');
    }

    private static function testo(string $key): string
    {
        $valore = trim((string) (Settings::stored($key) ?? ''));

        return $valore !== '' ? $valore : self::DEFAULTS[$key];
    }
}
