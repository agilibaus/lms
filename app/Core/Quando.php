<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Le date come si mostrano in tutta la piattaforma (06/11, Alessandro):
 * «16/09/2026 18:00» dove c'e' anche l'ora, «16/09/2026» dove c'e' solo il
 * giorno. Dal valore del database (`2026-09-16 18:00:42`); un valore vuoto o
 * illeggibile da' una stringa vuota.
 */
final class Quando
{
    public static function dataOra(?string $valore): string
    {
        $t = self::tempo($valore);

        return $t === null ? '' : date('d/m/Y H:i', $t);
    }

    public static function data(?string $valore): string
    {
        $t = self::tempo($valore);

        return $t === null ? '' : date('d/m/Y', $t);
    }

    private static function tempo(?string $valore): ?int
    {
        if ($valore === null || trim($valore) === '') {
            return null;
        }

        $t = strtotime($valore);

        return $t === false ? null : $t;
    }
}
