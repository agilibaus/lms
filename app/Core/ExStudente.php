<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

/**
 * Lo studente diventato staff (13/11, Alessandro).
 *
 * Ai corsi si iscrivono solo gli studenti, ma chi lo era e poi e' diventato
 * tutor, assistente, supervisore o Amministratore conserva le iscrizioni di
 * allora: sono la sua storia di studente. Fra gli iscritti di un corso (la
 * scheda del corso e il Report per corso) una frase sotto il nome lo dice
 * (variante 3A), e il certificato non gli si emette piu'.
 *
 * Una frase sola per le due pagine, scritta qui.
 */
final class ExStudente
{
    /**
     * La frase per un iscritto con questo ruolo, o null se e' uno studente.
     */
    public static function frase(?string $ruolo): ?string
    {
        if ($ruolo === null || $ruolo === '' || $ruolo === 'studente') {
            return null;
        }

        // «Amministratore» sempre con la maiuscola (0325); gli altri ruoli
        // in minuscolo, come si scrivono in una frase.
        $nome = $ruolo === 'admin' ? Auth::roleLabel($ruolo) : mb_strtolower(Auth::roleLabel($ruolo));

        return 'Ora è ' . $nome . ': iscrizione di quando era studente.';
    }
}
