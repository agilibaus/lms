<?php

declare(strict_types=1);

/**
 * Test di «Tre parole»: le parole come si salvano, quando si chiedono, le
 * parole che tornano, l'ordine delle terne anonime.
 *
 * Esecuzione:  php tests/tre_parole_test.php
 *
 * Niente server e niente database: `App\Core\TreParole` lavora su quello
 * che gli viene dato. Chi legge la pagina si prova in `tests/permessi.js`.
 *
 * COSA VERIFICA, e perche' proprio questo:
 *
 *   1. **«Calma» e «calma» sono la stessa parola**: senza, le parole che
 *      tornano si dividerebbero per una maiuscola.
 *   2. **Tre parole o niente**: una casella vuota, o con soli spazi, rimanda
 *      indietro; un campo che arriva come array non passa.
 *   3. **Solo i moduli completati da quando il corso le chiede.**
 *   4. **Una parola ripetuta nella stessa terna conta una volta**: le parole
 *      che tornano dicono quante persone l'hanno scelta.
 *   5. **L'ordine anonimo non e' quello di arrivo**, ed e' sempre lo stesso.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\TreParole;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $dettaglio = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condition && $dettaglio !== '') {
        echo '         · ' . $dettaglio . PHP_EOL;
    }
}

echo PHP_EOL . 'Le parole come si salvano' . PHP_EOL;

check('minuscole', TreParole::normalizza('Calma') === 'calma');
check('le lettere accentate in minuscolo', TreParole::normalizza('ÈNERGIA') === 'ènergia');
check('spazi in piu tolti', TreParole::normalizza("  mare \t  calmo ") === 'mare calmo');
check('al massimo 30 caratteri', mb_strlen(TreParole::normalizza(str_repeat('à', 40))) === 30);
check('niente caratteri invisibili', TreParole::normalizza("cal\u{200B}ma") === 'calma');

echo PHP_EOL . 'Tre parole o niente' . PHP_EOL;

check('tre parole', TreParole::daModulo(['Calma', 'luce', 'Casa']) === ['calma', 'luce', 'casa']);
check('una vuota', TreParole::daModulo(['calma', '', 'casa']) === null);
check('una di soli spazi', TreParole::daModulo(['calma', '   ', 'casa']) === null);
check('solo due', TreParole::daModulo(['calma', 'luce']) === null);
check('niente', TreParole::daModulo(null) === null);
check('una parola come array', TreParole::daModulo(['calma', ['luce'], 'casa']) === null, '?parole[1][]=luce');
check('la quarta si ignora', TreParole::daModulo(['a', 'b', 'c', 'd']) === ['a', 'b', 'c']);

echo PHP_EOL . 'Quando si chiedono' . PHP_EOL;

check('modulo completato dopo l attivazione', TreParole::daChiedere('2026-10-21 10:00:00', '2026-10-20 09:00:00'));
check('modulo completato prima', !TreParole::daChiedere('2026-10-19 10:00:00', '2026-10-20 09:00:00'), 'non sarebbero piu d istinto');
check('corso che non le chiede', !TreParole::daChiedere('2026-10-21 10:00:00', null));
check('modulo non completato', !TreParole::daChiedere(null, '2026-10-20 09:00:00'));

echo PHP_EOL . 'Le parole che tornano' . PHP_EOL;

$r = TreParole::ricorrenti([['calma', 'luce', 'casa'], ['respiro', 'calma', 'luce'], ['calma', 'onde', 'silenzio']]);
check('la piu frequente in cima', $r[0] === ['parola' => 'calma', 'volte' => 3], json_encode($r[0]));
check('poi luce', $r[1] === ['parola' => 'luce', 'volte' => 2]);
check('a parita in ordine alfabetico', array_column(array_slice($r, 2), 'parola') === ['casa', 'onde', 'respiro', 'silenzio'], json_encode(array_column($r, 'parola')));
$r = TreParole::ricorrenti([['calma', 'calma', 'calma'], ['calma', 'luce', 'casa']]);
check('ripetuta nella stessa terna conta una volta', $r[0] === ['parola' => 'calma', 'volte' => 2], json_encode($r));
$r = TreParole::ricorrenti([['perché', 'zebra', 'pera']]);
check('l accento non sposta in fondo', array_column($r, 'parola') === ['pera', 'perché', 'zebra'], json_encode(array_column($r, 'parola')));

echo PHP_EOL . 'L ordine anonimo' . PHP_EOL;

$righe = array_map(static fn (int $i): array => ['id' => $i], range(1, 12));
$a = TreParole::mescola($righe, '7|3');
check('le stesse righe', array_column($a, 'id') !== range(1, 12) && count($a) === 12 && array_sum(array_column($a, 'id')) === 78);
check('sempre lo stesso ordine', $a === TreParole::mescola($righe, '7|3'));
check('per un altro modulo un altro ordine', $a !== TreParole::mescola($righe, '8|3'));

echo PHP_EOL . 'La vista' . PHP_EOL;

check('di partenza anonime', TreParole::vista(null) === TreParole::ANONIME);
check('con i nomi', TreParole::vista('nomi') === TreParole::NOMI);
check('altro torna anonime', TreParole::vista('tutto') === TreParole::ANONIME && TreParole::vista(['nomi']) === TreParole::ANONIME);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
