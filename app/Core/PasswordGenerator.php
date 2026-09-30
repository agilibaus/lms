<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Password temporanee generate dall'amministratore.
 *
 * Nascono per essere lette in un'email e ribattute a mano, non per essere
 * ricordate: durano il tempo di un accesso, perche' chi entra con una di
 * queste deve sceglierne una sua prima di fare altro.
 *
 * Per questo l'alfabeto **esclude i caratteri che si confondono**: O e 0, l e
 * I e 1. Una password che non si riesce a ribattere costa una telefonata a
 * chi amministra, e la sicurezza persa togliendo sei caratteri su
 * sessantadue e' nulla a questa lunghezza.
 */
class PasswordGenerator
{
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const LUNGHEZZA = 14;

    /**
     * I caratteri sono presi con `random_int`, che attinge al generatore
     * crittografico del sistema: `rand()` e `mt_rand()` sono prevedibili.
     */
    public static function genera(int $lunghezza = self::LUNGHEZZA): string
    {
        $ultimo = strlen(self::ALFABETO) - 1;
        $password = '';

        for ($i = 0; $i < $lunghezza; $i++) {
            $password .= self::ALFABETO[random_int(0, $ultimo)];
        }

        return $password;
    }
}
