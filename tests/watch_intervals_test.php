<?php

declare(strict_types=1);

/**
 * Test dell'unione degli intervalli di video guardati.
 *
 * Esecuzione:  php tests/watch_intervals_test.php
 *
 * E' l'unica parte della tracciatura collaudabile dal container: aritmetica
 * su coppie di numeri, nessun database e nessun browser. Che il player mandi
 * davvero gli eventi giusti si prova solo con un video vero, nell'ambiente
 * di Elena (pistacchio-lms.md Sezione 7.1).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\WatchIntervals;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

/** @param list<array{0: int, 1: int}> $intervalli */
function scrivi(array $intervalli): string
{
    return '[' . implode(' ', array_map(
        static fn (array $i): string => $i[0] . '-' . $i[1],
        $intervalli
    )) . ']';
}

/**
 * @param list<array{0: int|float, 1: int|float}> $ingresso
 * @param list<array{0: int, 1: int}>             $atteso
 */
function uguale(string $label, array $ingresso, array $atteso, ?int $durata = null): void
{
    $ottenuto = WatchIntervals::merge($ingresso, $durata);
    $esito = $ottenuto === $atteso;

    check($label . ($esito ? '' : ' — atteso ' . scrivi($atteso) . ', ottenuto ' . scrivi($ottenuto)), $esito);
}

echo PHP_EOL . '--- Unione' . PHP_EOL;

uguale('un intervallo solo resta com\'e\'', [[0, 100]], [[0, 100]]);
uguale('due separati restano due', [[0, 50], [80, 100]], [[0, 50], [80, 100]]);
uguale('due sovrapposti diventano uno', [[0, 60], [40, 100]], [[0, 100]]);
uguale('uno dentro l\'altro sparisce', [[0, 100], [30, 60]], [[0, 100]]);
uguale('due identici diventano uno', [[10, 50], [10, 50]], [[10, 50]]);

// E' il caso vero: lo script salva ogni 60 secondi, quindi manda pezzi
// consecutivi che si toccano. Devono tornare una riga sola, non venti.
uguale(
    'i pezzi consecutivi del salvataggio periodico diventano uno',
    [[0, 60], [60, 120], [120, 180], [180, 240]],
    [[0, 240]]
);

uguale('arrivano in disordine e si riordinano', [[120, 180], [0, 60], [60, 120]], [[0, 180]]);
uguale('tre gruppi distinti restano tre', [[0, 20], [15, 30], [100, 120], [200, 210]], [[0, 30], [100, 120], [200, 210]]);

echo PHP_EOL . '--- Cosa si butta via' . PHP_EOL;

uguale('l\'elenco vuoto da\' elenco vuoto', [], []);
uguale('un intervallo di un secondo e\' rumore', [[10, 11]], []);
uguale('un intervallo a lunghezza zero sparisce', [[10, 10]], []);
uguale('un intervallo rovesciato sparisce', [[100, 50]], []);
uguale('i secondi negativi si tagliano a zero', [[-30, 40]], [[0, 40]]);
uguale('il rumore non impedisce il resto', [[10, 11], [20, 80]], [[20, 80]]);

echo PHP_EOL . '--- Taglio alla durata del video' . PHP_EOL;

uguale('oltre la durata si taglia', [[0, 5000]], [[0, 600]], 600);
uguale('tutto oltre la durata sparisce', [[900, 1200]], [], 600);
uguale('dentro la durata non cambia niente', [[0, 300]], [[0, 300]], 600);
uguale('senza durata non si taglia', [[0, 5000]], [[0, 5000]], null);
uguale('durata zero non taglia (non la sappiamo)', [[0, 5000]], [[0, 5000]], 0);

echo PHP_EOL . '--- Secondi visti e percentuale' . PHP_EOL;

check('il totale somma gli intervalli', WatchIntervals::total([[0, 60], [100, 140]]) === 100);
check('il totale di niente e\' zero', WatchIntervals::total([]) === 0);

// La ragione per cui si tengono gli intervalli invece di un contatore:
// riguardare lo stesso pezzo non deve gonfiare il tempo guardato.
$riguardato = WatchIntervals::merge([[0, 60], [0, 60], [0, 60]]);
check('riguardare lo stesso minuto non gonfia il totale', WatchIntervals::total($riguardato) === 60);

check('meta\' video fa 50%', WatchIntervals::percentage([[0, 300]], 600) === 50);
check('video intero fa 100%', WatchIntervals::percentage([[0, 600]], 600) === 100);
check('non si sfora il 100%', WatchIntervals::percentage([[0, 900]], 600) === 100);
check('niente guardato fa 0%', WatchIntervals::percentage([], 600) === 0);
check('senza durata non c\'e\' percentuale', WatchIntervals::percentage([[0, 300]], null) === null);
check('durata zero non da\' percentuale', WatchIntervals::percentage([[0, 300]], 0) === null);

echo PHP_EOL . '--- Plausibilita\' nel tempo trascorso' . PHP_EOL;

// Non si guardano dieci minuti di video in due minuti di orologio. Il tetto
// e' generoso di proposito: 2,5x piu' mezzo minuto di margine, perche' i
// player arrivano a 2x e fra misura e arrivo della richiesta passa tempo.
check('in un minuto ci stanno 60 secondi di video', WatchIntervals::plausibleCeiling(60) >= 60);
check('in un minuto non ce ne stanno 600', WatchIntervals::plausibleCeiling(60) < 600);
check('il tetto cresce col tempo', WatchIntervals::plausibleCeiling(600) > WatchIntervals::plausibleCeiling(60));
check('anche a tempo zero c\'e\' un margine', WatchIntervals::plausibleCeiling(0) > 0);

$onesto = WatchIntervals::capToElapsed([[0, 60]], 60);
check('una visione onesta passa intera', $onesto === [[0, 60]]);

$gonfiato = WatchIntervals::capToElapsed([[0, 3600]], 60);
check('un\'ora dichiarata in un minuto viene tagliata', WatchIntervals::total($gonfiato) < 3600);
check('ma non azzerata: resta la parte plausibile', WatchIntervals::total($gonfiato) > 0);
check(
    'il taglio rispetta il tetto',
    WatchIntervals::total($gonfiato) <= WatchIntervals::plausibleCeiling(60)
);

$duePezzi = WatchIntervals::capToElapsed([[0, 60], [500, 5000]], 60);
check('il taglio parte dai primi intervalli', $duePezzi[0] === [0, 60]);
check(
    'e si ferma dentro il tetto',
    WatchIntervals::total($duePezzi) <= WatchIntervals::plausibleCeiling(60)
);

echo PHP_EOL . '--- Lettura di quello che manda il browser' . PHP_EOL;

// Il corpo della richiesta lo scrive il browser: qui dentro puo' arrivare
// qualunque cosa, e la risposta giusta non e' un errore fatale.
check('il JSON buono si legge', WatchIntervals::fromJson('[[0,60],[60,120]]') === [[0, 120]]);
check('JSON rotto da\' elenco vuoto', WatchIntervals::fromJson('{{{') === []);
check('JSON nullo da\' elenco vuoto', WatchIntervals::fromJson(null) === []);
check('stringa vuota da\' elenco vuoto', WatchIntervals::fromJson('') === []);
check('un oggetto al posto dell\'elenco non rompe', WatchIntervals::fromJson('{"a":1}') === []);
check('le voci che non sono coppie si saltano', WatchIntervals::fromJson('[[0,60],"ciao",[70,130]]') === [[0, 60], [70, 130]]);
check('le voci non numeriche si saltano', WatchIntervals::fromJson('[["a","b"],[0,60]]') === [[0, 60]]);
check('i decimali si arrotondano verso l\'esterno', WatchIntervals::fromJson('[[0.7,59.2]]') === [[0, 60]]);
check('la durata vale anche qui', WatchIntervals::fromJson('[[0,5000]]', 600) === [[0, 600]]);

// Un invio smisurato non deve far lavorare il server a vuoto.
$tanti = json_encode(array_map(static fn (int $i): array => [$i * 10, $i * 10 + 5], range(0, 2000)));
$letti = WatchIntervals::fromJson((string) $tanti);
check('un invio smisurato viene troncato', count($letti) <= 500);
check('ma quello che resta e\' valido', $letti !== [] && $letti[0] === [0, 5]);

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
