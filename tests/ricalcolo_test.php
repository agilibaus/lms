<?php

declare(strict_types=1);

/**
 * I conti del ricalcolo dei voti (16/11), senza database.
 *
 * Esecuzione:  php tests/ricalcolo_test.php
 *
 * Il percorso intero (blocchi, conferma, salvataggio) lo prova
 * `tests/ricalcolo.js`; qui i casi che con i dati della semina non si
 * vedono: i tentativi vecchi, di quando il questionario aveva altre domande
 * o un'opzione tolta, e i questionari di sole domande aperte.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Ricalcolo;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

$t = static fn (int $utente, float $pct, bool $sup, array $risposte): array => ['user_id' => $utente, 'score_pct' => $pct, 'passed' => $sup, 'risposte' => $risposte];
$corrette = [1 => [11], 2 => [21, 22], 3 => [31]];

echo PHP_EOL . '--- Il punteggio' . PHP_EOL;

$e = Ricalcolo::esiti([
    100 => $t(7, 0, false, [1 => ['tipo' => 'single_choice', 'scelte' => [11]], 2 => ['tipo' => 'multiple_choice', 'scelte' => [22, 21]], 3 => ['tipo' => 'true_false', 'scelte' => [32]]]),
], $corrette, 60);
check('due su tre: 66,67%, superato con 60%', $e[100]['score_pct'] === 66.67 && $e[100]['passed'] === true);
check('la multipla e\' giusta con tutte le corrette, in qualunque ordine', $e[100]['giuste'][2] === true);
check('ogni domanda valutata ha il suo «giusta»', $e[100]['giuste'] === [1 => true, 2 => true, 3 => false]);

$e = Ricalcolo::esiti([
    101 => $t(7, 0, false, [2 => ['tipo' => 'multiple_choice', 'scelte' => [21]]]),
    102 => $t(7, 0, false, [2 => ['tipo' => 'multiple_choice', 'scelte' => [21, 22, 23]]]),
], $corrette, 60);
check('la multipla con una parte delle corrette e\' sbagliata', $e[101]['giuste'][2] === false);
check('la multipla con una sbagliata in piu\' e\' sbagliata', $e[102]['giuste'][2] === false);

echo PHP_EOL . '--- I tentativi vecchi' . PHP_EOL;

$e = Ricalcolo::esiti([
    103 => $t(7, 0, false, [1 => ['tipo' => 'single_choice', 'scelte' => [11]]]),
], $corrette, 60);
check('un tentativo con meno domande si ricalcola sulle sue: una su una, 100%', $e[103]['score_pct'] === 100.0);

$e = Ricalcolo::esiti([
    104 => $t(7, 0, false, [1 => ['tipo' => 'single_choice', 'scelte' => []], 3 => ['tipo' => 'true_false', 'scelte' => [31]]]),
], $corrette, 60);
check('una scelta rimasta senza opzione (tolta prima del 16/11) conta sbagliata', $e[104]['giuste'][1] === false && $e[104]['score_pct'] === 50.0);

echo PHP_EOL . '--- Le aperte' . PHP_EOL;

$e = Ricalcolo::esiti([
    105 => $t(7, 100, true, [4 => ['tipo' => 'open', 'scelte' => []]]),
    106 => $t(7, 0, false, [1 => ['tipo' => 'single_choice', 'scelte' => [11]], 4 => ['tipo' => 'open', 'scelte' => []]]),
], $corrette, 90);
check('sole aperte: 100% e superato, con qualunque soglia', $e[105]['score_pct'] === 100.0 && $e[105]['passed'] === true);
check('l\'aperta non entra nel conto e non ha un «giusta»', $e[106]['score_pct'] === 100.0 && !array_key_exists(4, $e[106]['giuste']));

echo PHP_EOL . '--- Il riepilogo' . PHP_EOL;

$tentativi = [
    200 => $t(1, 100, true, [1 => ['tipo' => 'single_choice', 'scelte' => [11]]]),
    201 => $t(2, 0, false, [1 => ['tipo' => 'single_choice', 'scelte' => [12]]]),
    202 => $t(2, 100, true, [1 => ['tipo' => 'single_choice', 'scelte' => [11]]]),
    203 => $t(3, 0, false, [1 => ['tipo' => 'single_choice', 'scelte' => [13]]]),
];
// La corretta passa da 11 a 12.
$r = Ricalcolo::riepilogo($tentativi, Ricalcolo::esiti($tentativi, [1 => [12]], 60));
check('cambiano i tre tentativi con 11 o 12, non quello con 13', $r['tentativi'] === [200, 201, 202]);
check('di due studenti', $r['studenti'] === [1, 2]);
check('lo studente 1 non supera piu\'; il 2 superava e supera ancora', $r['perdono'] === [1] && $r['superano'] === []);
check('in tabella solo lo studente 1: il miglior risultato del 2 resta 100%',
    count($r['righe']) === 1 && $r['righe'][0]['user_id'] === 1 && $r['righe'][0]['prima'] === 100.0 && $r['righe'][0]['dopo'] === 0.0);
$r = Ricalcolo::riepilogo($tentativi, Ricalcolo::esiti($tentativi, [1 => [11]], 60));
check('niente che cambi: nessun tentativo, nessuna riga', $r['tentativi'] === [] && $r['righe'] === []);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
