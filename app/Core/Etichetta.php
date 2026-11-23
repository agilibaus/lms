<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Le etichette di stato (`.badge`) cominciano con la maiuscola (23/11,
 * Alessandro): «Attiva», «Completato», «Assente». Per le etichette che
 * arrivano da un valore usato anche altrove (nei confronti, nelle frasi)
 * la maiuscola si mette qui, al momento di mostrarle, e il valore resta
 * com'e'.
 */
final class Etichetta
{
    public static function maiuscola(string $testo): string
    {
        $testo = trim($testo);

        return mb_strtoupper(mb_substr($testo, 0, 1)) . mb_substr($testo, 1);
    }
}
