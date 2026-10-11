<?php

declare(strict_types=1);

/**
 * Test delle regole dell'obiettivo nella pagina del corso (11/10), senza
 * database: quando l'obiettivo del corso e' completo, qual e' il modulo in
 * corso, e l'etichetta «Obiettivo:» davanti a quello del modulo.
 *
 * Esecuzione:  php tests/obiettivo_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Obiettivo;

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

// ---------------------------------------------------------------
echo 'L\'obiettivo del corso' . PHP_EOL;

check('prima visita: completo', Obiettivo::modoCorso(1) === 'completo');
check('terza visita: completo', Obiettivo::modoCorso(3) === 'completo');
check('quarta visita: ridotto', Obiettivo::modoCorso(4) === 'ridotto');
check('dopo: ridotto', Obiettivo::modoCorso(40) === 'ridotto');

// ---------------------------------------------------------------
echo PHP_EOL . 'Il modulo in corso' . PHP_EOL;

// Tre moduli: 1 con due lezioni e un questionario, 2 con una lezione, 3 con
// una lezione.
$moduli = [['id' => 1], ['id' => 2], ['id' => 3]];
$lezioni = [1 => [['id' => 10], ['id' => 11]], 2 => [['id' => 20]], 3 => [['id' => 30]]];
$quiz = [1 => ['id' => 5], 2 => null, 3 => null];
$inCorso = static fn (array $fatte, array $superati = []): ?int
    => Obiettivo::moduloInCorso($moduli, $lezioni, $fatte, $quiz, $superati);

check('niente fatto: il primo', $inCorso([]) === 1);
check('una lezione del primo fatta: ancora il primo', $inCorso([10]) === 1);
check('lezioni del primo fatte ma questionario no: ancora il primo', $inCorso([10, 11]) === 1);
check('lezioni e questionario del primo: il secondo', $inCorso([10, 11], [1 => true]) === 2);
check('si guarda l\'ordine dei moduli, non le lezioni fatte dopo', $inCorso([20, 30], [1 => true]) === 1);
check('tutto fatto: nessuno', $inCorso([10, 11, 20, 30], [1 => true]) === null);
check('un modulo senza lezioni ne\' questionario si salta',
    Obiettivo::moduloInCorso([['id' => 7], ['id' => 8]], [7 => [], 8 => [['id' => 80]]], [], [], []) === 8);
check('un modulo chiuso puo\' essere quello in corso: le regole non guardano i blocchi',
    Obiettivo::moduloInCorso([['id' => 1], ['id' => 2]], [1 => [['id' => 10]], 2 => [['id' => 20]]], [10], [], []) === 2);

// ---------------------------------------------------------------
echo PHP_EOL . 'L\'obiettivo del modulo' . PHP_EOL;

$e = '<strong class="obiettivo-modulo-etichetta">Obiettivo:</strong>';
check('dentro il primo paragrafo', Obiettivo::htmlModulo('Conoscere il <em>percorso</em>.') === '<p>' . $e . ' Conoscere il <em>percorso</em>.</p>',
    Obiettivo::htmlModulo('Conoscere il <em>percorso</em>.'));
check('sopra un elenco, in un paragrafo suo', Obiettivo::htmlModulo('<ul><li>uno</li></ul>') === '<p>' . $e . '</p><ul><li>uno</li></ul>',
    Obiettivo::htmlModulo('<ul><li>uno</li></ul>'));
check('vuoto: niente', Obiettivo::htmlModulo('  ') === '' && Obiettivo::htmlModulo(null) === '');
check('ripulito come gli altri testi brevi', !str_contains(Obiettivo::htmlModulo('Ciao<script>x()</script>'), 'script'));

// ---------------------------------------------------------------
echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
