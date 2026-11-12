<?php

declare(strict_types=1);

/**
 * Dopo l'accesso si torna alla pagina chiesta (06/11): quali indirizzi si
 * accettano. Solo pagine di questa piattaforma: niente altri siti, niente
 * accesso e uscita. La prova vera (dal collegamento dell'email al corso)
 * sta in `tests/pagamenti.js`.
 *
 * Esecuzione:  php tests/dopo_accesso_test.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Auth\Auth;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

foreach (['/courses/4', '/lessons/12', '/reports/students/5?scheda=incontri', '/agenda?vista=mese', '/loginx', '/'] as $d) {
    check("«{$d}» si accetta", Auth::destinazioneValida($d));
}

foreach ([
    '' => 'vuoto',
    '//altro.it/pagina' => 'un altro sito, senza schema',
    'https://altro.it/' => 'un altro sito, con lo schema',
    '/\\altro.it' => 'una barra rovesciata, che i browser leggono come un altro sito',
    "/corso\x00" => 'un carattere di controllo',
    "/a\tb" => 'una tabulazione',
    '/login' => 'la pagina di accesso',
    '/login?motivo=sessione' => 'la pagina di accesso con un parametro',
    '/logout' => 'l\'uscita',
    'courses/4' => 'senza la barra iniziale',
] as $d => $cosa) {
    check("non si accetta {$cosa}", !Auth::destinazioneValida((string) $d));
}

check('un indirizzo lunghissimo non si accetta', !Auth::destinazioneValida('/' . str_repeat('a', 2100)));

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
