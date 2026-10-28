<?php

declare(strict_types=1);

/**
 * Test della gerarchia dei moduli nella pagina del corso (11/10), senza
 * database: lo stato di ogni modulo, «Riprendi da…» e le parole delle righe.
 *
 * Esecuzione:  php tests/percorso_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Percorso;

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

// Cinque moduli: 1 con due lezioni e un questionario, 2, 3, 4 e 5 con una
// lezione ciascuno.
$moduli = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];
$lezioni = [1 => [['id' => 10], ['id' => 11]], 2 => [['id' => 20]], 3 => [['id' => 30]], 4 => [['id' => 40]], 5 => [['id' => 50]]];
$quiz = [1 => ['id' => 9]];
$stati = static fn (array $fatte, array $superati = []): array
    => Percorso::stati($moduli, $lezioni, $fatte, $quiz, $superati);
$mostra = static fn (array $s): string => json_encode($s);

// ---------------------------------------------------------------
echo 'Gli stati' . PHP_EOL;

$s = $stati([]);
check('niente fatto: il primo in corso, il secondo prossimo, gli altri da fare',
    $s === [1 => 'in_corso', 2 => 'prossimo', 3 => 'da_fare', 4 => 'da_fare', 5 => 'da_fare'], $mostra($s));

$s = $stati([10, 11]);
check('lezioni fatte ma questionario no: il primo resta in corso', $s[1] === 'in_corso' && $s[2] === 'prossimo', $mostra($s));

$s = $stati([10, 11, 20], [1 => true]);
check('primo e secondo finiti: completati, il terzo in corso, il quarto prossimo',
    $s === [1 => 'completato', 2 => 'completato', 3 => 'in_corso', 4 => 'prossimo', 5 => 'da_fare'], $mostra($s));

$s = $stati([10, 11, 40], [1 => true]);
check('un modulo finito piu\' avanti e\' completato, e il prossimo e\' il primo non finito dopo quello in corso',
    $s === [1 => 'completato', 2 => 'in_corso', 3 => 'prossimo', 4 => 'completato', 5 => 'da_fare'], $mostra($s));

$s = Percorso::stati($moduli, $lezioni, [10, 11, 30], [1 => ['id' => 9], 3 => ['id' => 8]], [1 => true]);
check('lezioni di un modulo dopo fatte ma il suo questionario no: non e\' completato',
    $s[2] === 'in_corso' && $s[3] === 'prossimo', $mostra($s));

$s = $stati([10, 11, 20, 30, 40, 50], [1 => true]);
check('corso finito: tutti completati, nessuno in corso', array_unique(array_values($s)) === ['completato'], $mostra($s));

$s = $stati([10, 11, 20, 30, 40], [1 => true]);
check('in corso l\'ultimo: nessun prossimo', $s[5] === 'in_corso' && !in_array('prossimo', $s, true), $mostra($s));

$s = Percorso::stati([['id' => 1], ['id' => 2], ['id' => 3]], [1 => [['id' => 10]], 2 => [], 3 => [['id' => 30]]], [], [], []);
check('un modulo vuoto non e\' completato ne\' in corso', $s === [1 => 'in_corso', 2 => 'prossimo', 3 => 'da_fare'], $mostra($s));

// ---------------------------------------------------------------
echo PHP_EOL . 'Riprendi da' . PHP_EOL;

$r = Percorso::riprendi($lezioni[1], []);
check('niente fatto: la prima lezione, 0 di 2', $r !== null && $r['lezione']['id'] === 10 && $r['fatte'] === 0 && $r['totale'] === 2);
$r = Percorso::riprendi($lezioni[1], [10]);
check('la prima fatta: la seconda, 1 di 2', $r !== null && $r['lezione']['id'] === 11 && $r['fatte'] === 1);
$r = Percorso::riprendi($lezioni[1], [11]);
check('fatta solo la seconda: si riprende dalla prima, 1 di 2', $r !== null && $r['lezione']['id'] === 10 && $r['fatte'] === 1);
check('tutte fatte: niente da riprendere (resta il questionario)', Percorso::riprendi($lezioni[1], [10, 11]) === null);

// ---------------------------------------------------------------
echo PHP_EOL . 'Le parole' . PHP_EOL;

check('«3 lezioni»', Percorso::lezioni(3) === '3 lezioni');
check('«1 lezione»', Percorso::lezioni(1) === '1 lezione');
check('«nessuna lezione»', Percorso::lezioni(0) === 'nessuna lezione');

check('«questionario» per la verifica finale', Percorso::contenuto(0, true) === 'questionario');
check('con lezioni conta le lezioni, anche con il questionario', Percorso::contenuto(2, true) === '2 lezioni');
check('senza lezioni e senza questionario: «nessuna lezione»', Percorso::contenuto(0, false) === 'nessuna lezione');

// ---------------------------------------------------------------
echo PHP_EOL . 'Quando si apre il questionario (27/10)' . PHP_EOL;

// Il corso di sopra, piu' un modulo vuoto (6) e la verifica finale (7):
// nessuna lezione, un questionario, e la casella accesa (28/10).
$moduliV = array_merge($moduli, [['id' => 6], ['id' => 7, 'is_final' => 1]]);
$lezioniV = $lezioni + [6 => [], 7 => []];
$quizV = [1 => ['id' => 9], 7 => ['id' => 70]];
$chiuso = static fn (int $m, array $fatte, array $superati = []): ?string
    => Percorso::questionarioChiuso($m, $moduliV, $lezioniV, $fatte, $quizV, $superati);
$tutteLeLezioni = [10, 11, 20, 30, 40, 50];

check('nessuna lezione fatta: chiuso, per le lezioni', $chiuso(1, []) === Percorso::CHIUSO_LEZIONI);
check('una lezione su due: ancora chiuso', $chiuso(1, [10]) === Percorso::CHIUSO_LEZIONI);
check('fatta solo la seconda: ancora chiuso', $chiuso(1, [11]) === Percorso::CHIUSO_LEZIONI);
check('tutte le lezioni del modulo: aperto', $chiuso(1, [10, 11]) === null);
check('le lezioni di altri moduli non contano', $chiuso(1, [20, 30, 40, 50]) === Percorso::CHIUSO_LEZIONI);
check('verifica finale, moduli prima non finiti: chiusa, per i moduli', $chiuso(7, [10, 11]) === Percorso::CHIUSO_MODULI);
check('verifica finale, lezioni tutte fatte ma un questionario prima non superato: chiusa',
    $chiuso(7, $tutteLeLezioni) === Percorso::CHIUSO_MODULI);
check('verifica finale, tutto completato prima: aperta (il modulo vuoto non conta)',
    $chiuso(7, $tutteLeLezioni, [1 => true]) === null);
check('verifica finale, manca una lezione di un modulo prima: chiusa',
    $chiuso(7, [10, 11, 20, 30, 40], [1 => true]) === Percorso::CHIUSO_MODULI);
check('la verifica aspetta tutti gli altri moduli, anche uno messo dopo di lei',
    Percorso::questionarioChiuso(7, array_merge($moduliV, [['id' => 8]]), $lezioniV + [8 => [['id' => 80]]], $tutteLeLezioni, $quizV, [1 => true]) === Percorso::CHIUSO_MODULI);
$senzaCasella = array_merge($moduli, [['id' => 6], ['id' => 7]]);
check('un modulo con il solo questionario e la casella spenta: aperto da subito (non e\' una verifica)',
    Percorso::questionarioChiuso(7, $senzaCasella, $lezioniV, [], $quizV, []) === null);

// ---------------------------------------------------------------
echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
