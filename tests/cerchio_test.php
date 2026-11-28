<?php

declare(strict_types=1);

/**
 * Test della disposizione in cerchio della pagina di un gruppo.
 *
 * Esecuzione:  php tests/cerchio_test.php
 *
 * Solo geometria: `GroupCircle` non tocca il database. Che il cerchio stia
 * davvero nella pagina senza sforare lo dice `accessibilita.js`, che apre il
 * gruppo «cerchio» della semina; qui si prova che i conti siano giusti, ed
 * e' l'unico posto in cui si prova la soglia dei 20 partecipanti, perche'
 * seminare ventun persone per guardare una griglia non vale la pena.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\GroupCircle;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

function vicino(float $a, float $b): bool
{
    return abs($a - $b) < 0.05;
}

echo PHP_EOL . '--- Quando si usa il cerchio' . PHP_EOL;

check('nessun partecipante: niente cerchio', !GroupCircle::usaCerchio(0));
check('un partecipante: cerchio', GroupCircle::usaCerchio(1));
check('venti partecipanti: ancora cerchio', GroupCircle::usaCerchio(20));
check('ventuno: griglia, come deciso da Elena', !GroupCircle::usaCerchio(21));
check('la soglia e\' venti', GroupCircle::MASSIMO === 20);

echo PHP_EOL . '--- Le posizioni' . PHP_EOL;

foreach ([1, 2, 5, 8, 20] as $n) {
    $pos = GroupCircle::posizioni($n);
    check("$n partecipanti: $n posizioni", count($pos) === $n);

    $sulCerchio = true;
    foreach ($pos as $p) {
        $sulCerchio = $sulCerchio && vicino(hypot($p['x'] - 50, $p['y'] - 50), GroupCircle::RAGGIO);
    }
    check("$n partecipanti: tutti alla stessa distanza dal centro", $sulCerchio);

    $dentro = true;
    foreach ($pos as $p) {
        $dentro = $dentro && $p['x'] > 0 && $p['x'] < 100 && $p['y'] > 0 && $p['y'] < 100;
    }
    check("$n partecipanti: tutti dentro il quadrato", $dentro);
}

$otto = GroupCircle::posizioni(8);
check('il primo sta in alto, al centro', vicino($otto[0]['x'], 50) && $otto[0]['y'] < 50);
check('il secondo sta a destra del primo: senso orario', $otto[1]['x'] > $otto[0]['x']);
check('il quinto di otto sta in basso, al centro', vicino($otto[4]['x'], 50) && $otto[4]['y'] > 50);

// Le coordinate tornano con due decimali: sono scritte nella pagina come
// variabili CSS, e diciotto cifre dopo la virgola non servono a nessuno.
check('le coordinate hanno al massimo due decimali', $otto[1]['x'] === round($otto[1]['x'], 2));

echo PHP_EOL . '--- Il nome verso l\'esterno' . PHP_EOL;

check('in alto il nome sta sopra', $otto[0]['lato'] === 'sopra');
check('in basso il nome sta sotto', $otto[4]['lato'] === 'sotto');
check('sul fianco destro il nome sta a destra', $otto[2]['lato'] === 'destra');
check('sul fianco sinistro il nome sta a sinistra', $otto[6]['lato'] === 'sinistra');

// Ogni nome va dalla parte della sua foto: a destra solo chi sta a destra
// del centro, e cosi' via. E' la regola che, sbagliata, manda un nome dentro
// il cerchio — sopra la foto del tutor.
$versoFuori = true;
foreach (GroupCircle::posizioni(20) as $p) {
    $versoFuori = $versoFuori && match ($p['lato']) {
        'destra' => $p['x'] > 50,
        'sinistra' => $p['x'] < 50,
        'sopra' => $p['y'] < 50,
        'sotto' => $p['y'] > 50,
        default => false,
    };
}
check('con venti partecipanti ogni nome va verso l\'esterno', $versoFuori);

// Il cerchio e' simmetrico: tanti nomi a destra quanti a sinistra, altrimenti
// un lato avrebbe bisogno di piu' spazio dell'altro.
$lati = array_count_values(array_column(GroupCircle::posizioni(20), 'lato'));
check('con venti: tanti a destra quanti a sinistra', ($lati['destra'] ?? 0) === ($lati['sinistra'] ?? 0));

echo PHP_EOL . '--- Gli anelli, da 21 a 50 (27/11)' . PHP_EOL;

check('fino a venti niente anelli: c\'e\' il cerchio', GroupCircle::anelli(20) === null);
check('ventuno: due anelli', (GroupCircle::anelli(21)['anelli'] ?? null) === 2);
check('trenta: ancora due anelli', (GroupCircle::anelli(30)['anelli'] ?? null) === 2);
check('quaranta: tre anelli', (GroupCircle::anelli(40)['anelli'] ?? null) === 3);
check('cinquanta: tre anelli', (GroupCircle::anelli(50)['anelli'] ?? null) === 3);
check('cinquantuno: non ci stanno, la griglia', GroupCircle::anelli(51) === null);

// Per ogni numero da 21 a 50: tutti al loro posto, nessuno troppo vicino a un
// altro o al tutor, anelli a un passo l'uno dall'altro, raggi che crescono con
// le persone, e il disegno nella larghezza per cui il CSS lo accende.
$tutti = $distanze = $passi = $larghezze = $tutorLibero = $crescono = true;
$minimoVisto = INF;
$raggioPrima = [2 => 0.0, 3 => 0.0];
for ($n = 21; $n <= 50; $n++) {
    $a = GroupCircle::anelli($n);
    if ($a === null) {
        $tutti = false;
        continue;
    }
    $k = $a['anelli'];
    $m = GroupCircle::ANELLI[$k];
    $passo = $m['foto'] + 3.125 + 1.0;
    $tutti = $tutti && count($a['posizioni']) === $n && array_sum($a['quanti']) === $n && min($a['quanti']) >= 1;
    for ($i = 1; $i < $k; $i++) {
        $passi = $passi && vicino($a['raggi'][$i] - $a['raggi'][$i - 1], $passo);
    }
    $larghezze = $larghezze && $a['larghezza'] <= $m['larghezza'];
    // Lo spazio fra il tutor e l'anello interno: la foto del tutor, il suo nome
    // e mezza foto, piu' un margine.
    $tutorLibero = $tutorLibero && $a['raggi'][0] >= 6.5 / 2 + 3.75 + $m['foto'] / 2;
    $crescono = $crescono && $a['raggi'][$k - 1] >= $raggioPrima[$k] - 0.001;
    $raggioPrima[$k] = $a['raggi'][$k - 1];
    $p = $a['posizioni'];
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $d = hypot($p[$i]['x'] - $p[$j]['x'], $p[$i]['y'] - $p[$j]['y']) / $passo;
            $minimoVisto = min($minimoVisto, $d);
        }
    }
}
check('da 21 a 50 tutti gli anelli hanno posto per tutti, almeno uno per anello', $tutti);
check('due vicini stanno almeno al 93% del passo: la corda e\' un po\' piu\' corta dell\'arco (piu\' vicini: ' . round($minimoVisto, 3) . ')', $minimoVisto >= 0.93);
check('fra due anelli, esattamente un passo', $passi);
check('il disegno sta nella larghezza per cui il CSS lo accende (52 rem con due anelli, 58,6 con tre)', $larghezze);
check('l\'anello interno lascia libero il tutor con il suo nome', $tutorLibero);
check('con piu\' persone gli anelli non si stringono', $crescono);

// Il tutor al centro del riquadro, e il riquadro contiene tutti.
$a = GroupCircle::anelli(50);
$dentro = true;
foreach ($a['posizioni'] as $q) {
    $dentro = $dentro && $q['x'] - $a['nome'] / 2 >= -0.01 && $q['x'] + $a['nome'] / 2 <= $a['larghezza'] + 0.01
        && $q['y'] - $a['foto'] / 2 >= 0 && $q['y'] + $a['foto'] / 2 + 3.125 <= $a['altezza'];
}
check('con cinquanta: il tutor al centro, e nomi e foto dentro il riquadro', vicino($a['tutor']['x'], $a['larghezza'] / 2) && $dentro);

// Nessun raggio fisso: con 25 l'anello interno e' piu' largo che con 21.
check('nessun raggio fisso: con 25 l\'anello interno e\' piu\' largo che con 21',
    GroupCircle::anelli(25)['raggi'][0] > GroupCircle::anelli(21)['raggi'][0] + 0.5);

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
