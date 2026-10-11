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

// ---------------------------------------------------------------
echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
