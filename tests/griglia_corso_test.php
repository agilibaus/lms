<?php

declare(strict_types=1);

/**
 * Test della «Griglia del corso»: gli stati delle caselle e i conti.
 *
 * Esecuzione:  php tests/griglia_corso_test.php
 *
 * Niente server e niente database: `App\Core\GrigliaCorso` decide su dati
 * che gli vengono dati. Chi vede la pagina si prova in `tests/permessi.js`.
 *
 * COSA VERIFICA, e perche' proprio questo:
 *
 *   1. **Gli incontri futuri e quelli di un altro gruppo non contano.**
 *      Altrimenti chi ha fatto tutto il modulo risulterebbe «3/4» per un
 *      incontro che ci sara' fra due settimane.
 *   2. **Un modulo senza niente da fare non e' «completo».** 0 su 0 non e'
 *      un modulo finito: e' un modulo in cui per ora non c'e' niente.
 *   3. **Il questionario conta solo se superato**, dal tentativo migliore.
 *   4. **Il modulo chiesto nell'indirizzo** e' un numero del corso o niente.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\GrigliaCorso as G;

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

$c = static fn (string ...$stati): array => array_map(static fn (string $s): array => ['stato' => $s], $stati);

echo PHP_EOL . 'I conti' . PHP_EOL;

$conto = G::conteggio($c(G::FATTO, G::FATTO, G::FUTURO));
check('l incontro futuro non conta', $conto === ['fatte' => 2, 'totale' => 2], json_encode($conto));
check('e il modulo e completo', G::completo($conto));

$conto = G::conteggio($c(G::FATTO, G::NON_PREVISTO));
check('l incontro di un altro gruppo non conta', $conto === ['fatte' => 1, 'totale' => 1]);

$conto = G::conteggio($c(G::FATTO, G::ASSENTE, G::TENTATO, G::VUOTO));
check('assente, non superato e non fatto contano come da fare', $conto === ['fatte' => 1, 'totale' => 4], json_encode($conto));
check('e il modulo non e completo', !G::completo($conto));

$conto = G::conteggio($c(G::FUTURO));
check('un modulo con solo un incontro futuro non ha niente da fare', $conto['totale'] === 0);
check('e non e completo', !G::completo($conto), '0 su 0 non e un modulo finito');

echo PHP_EOL . 'Gli stati' . PHP_EOL;

check('incontro passato, presente', G::statoIncontro(true, false, true) === G::FATTO);
check('incontro passato, non presente', G::statoIncontro(false, false, true) === G::ASSENTE);
check('incontro non ancora finito, non presente', G::statoIncontro(false, true, true) === G::FUTURO, 'durante l incontro chi non e entrato non e ancora assente');
check('incontro in corso, gia entrato', G::statoIncontro(true, true, true) === G::FATTO);
check('incontro a cui non e atteso (un altro gruppo, un altro periodo): non previsto', G::statoIncontro(false, false, false) === G::NON_PREVISTO);
check('incontro futuro a cui non e atteso: non previsto, non futuro', G::statoIncontro(false, true, false) === G::NON_PREVISTO);
check('questionario superato', G::statoQuestionario(true) === G::FATTO);
check('questionario non superato', G::statoQuestionario(false) === G::TENTATO);
check('questionario mai fatto', G::statoQuestionario(null) === G::VUOTO);

echo PHP_EOL . 'Il modulo dall indirizzo' . PHP_EOL;

check('un numero del corso', G::modulo('3', 8) === 3);
check('nessuno', G::modulo(null, 8) === null);
check('zero', G::modulo('0', 8) === null);
check('oltre l ultimo', G::modulo('9', 8) === null);
check('testo', G::modulo('tre', 8) === null);
check('un array', G::modulo(['3'], 8) === null, '?modulo[]=3 arriva come array');

echo PHP_EOL . 'Le scritte' . PHP_EOL;

check('il punteggio intero', G::punteggio(90.0) === '90%');
check('il punteggio con decimali, all italiana', G::punteggio(72.5) === '72,5%');
check('l intestazione del file', G::intestazione(1, G::LEZIONE, 'Benvenuto!') === 'M1 · Lezione · Benvenuto!');

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
