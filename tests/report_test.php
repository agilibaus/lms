<?php

declare(strict_types=1);

/**
 * Test dei tagli dei report: ricerca e paginazione.
 *
 * Esecuzione:  php tests/report_test.php
 *
 * Non tocca il database: `ReportSections::filtra()` e `::pagina()` lavorano
 * su un elenco gia' letto, quindi si provano con righe finte. E' il motivo
 * per cui stanno li' e non dentro al controller.
 *
 * COSA VERIFICA, e perche' proprio questo. Le due funzioni sono corte, ma
 * sono quelle che decidono **che cosa l'utente vede**, e sbagliano in modi
 * silenziosi: una ricerca sensibile alle maiuscole non da' errore, da' zero
 * risultati; una pagina fuori intervallo non da' errore, da' una tabella
 * vuota che sembra un difetto dei dati. Nessuna delle due si nota guardando
 * la pagina con i dati giusti.
 *
 *   1. La ricerca ignora le maiuscole e cerca **solo** nei campi dichiarati
 *      dalla sezione: se cercasse ovunque, scrivere «3» in «Per corso»
 *      restituirebbe i corsi con tre iscritti.
 *   2. La ricerca vuota restituisce tutto, e gli spazi non contano.
 *   3. La paginazione corregge la pagina invece di dare errore, in entrambi
 *      i versi, e l'ultima pagina contiene il resto, non cinquanta righe.
 *   4. Un elenco vuoto ha comunque una pagina: `0 pagine` renderebbe falsa
 *      la frase «pagina 1 di 0» e farebbe dividere per zero piu' in la'.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\ReportSections;

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
// Le cinque sezioni
// ---------------------------------------------------------------

echo PHP_EOL . 'Le sezioni' . PHP_EOL;

$sezioni = ReportSections::tutte();

check('sono cinque', count($sezioni) === 5, 'trovate: ' . implode(', ', array_keys($sezioni)));

foreach ($sezioni as $chiave => $s) {
    $completa = true;

    foreach (['titolo', 'singolare', 'plurale', 'articolo', 'base', 'vuoto', 'occhiello', 'cerca'] as $campo) {
        if (!isset($s[$campo]) || $s[$campo] === '' || $s[$campo] === []) {
            $completa = false;
        }
    }

    check("«{$chiave}» descrive tutto quello che serve a indice ed elenco", $completa);
    check("«{$chiave}» esiste", ReportSections::esiste($chiave));
}

check('una chiave inventata non esiste', !ReportSections::esiste('../etc/passwd'));
check('la chiave vuota non esiste', !ReportSections::esiste(''));

// Le colonne stanno in una vista, non in una classe: si leggono qui perche'
// l'indice e l'elenco devono parlare delle stesse sezioni.
$esc = static fn (?string $v): string => htmlspecialchars((string) $v);
/** @var array<string, array<int, array{0: string, 1: string, 2: callable}>> $colonne */
$colonne = require __DIR__ . '/../app/Views/reports/_colonne.php';

check(
    'ogni sezione ha le proprie colonne',
    array_keys($colonne) === array_keys($sezioni),
    'colonne: ' . implode(', ', array_keys($colonne))
);

$tipiBuoni = true;
$almenoUnNumero = [];

foreach ($colonne as $chiave => $lista) {
    foreach ($lista as [$etichetta, $tipo, $cella]) {
        if (!in_array($tipo, ['testo', 'numero'], true) || $etichetta === '' || !is_callable($cella)) {
            $tipiBuoni = false;
        }

        if ($tipo === 'numero') {
            $almenoUnNumero[$chiave] = true;
        }
    }
}

check('ogni colonna dichiara etichetta, tipo e come si rende', $tipiBuoni);
check(
    'ogni tabella ha almeno una colonna numerica da allineare',
    count($almenoUnNumero) === count($colonne),
    'senza: ' . implode(', ', array_diff(array_keys($colonne), array_keys($almenoUnNumero)))
);

// ---------------------------------------------------------------
// La frase sopra al campo di ricerca e il suo suggerimento
// ---------------------------------------------------------------

echo PHP_EOL . 'La frase e il suggerimento' . PHP_EOL;

// Le cinque frasi per esteso, non la regola: «Cerca fra i studenti» era
// esattamente il genere di errore che una regola dedotta da `plurale`
// rimette dentro al primo caso nuovo.
$frasi = [
    'courses' => 'Cerca fra i corsi',
    'groups' => 'Cerca fra i gruppi',
    'students' => 'Cerca fra gli studenti',
    'live' => 'Cerca fra gli incontri',
    'fruizione' => 'Cerca fra i corsi con video',
];

foreach ($frasi as $chiave => $attesa) {
    $s = $sezioni[$chiave];
    $frase = 'Cerca fra ' . $s['articolo'] . ' ' . $s['plurale'];

    check("«{$attesa}»", $frase === $attesa, 'ottenuto: «' . $frase . '»');
}

$suggerimenti = [
    'courses' => 'corso…',
    'groups' => 'gruppo, tutor…',
    'students' => 'studente, email…',
    'live' => 'incontro, corso o gruppo…',
    'fruizione' => 'corso…',
];

foreach ($suggerimenti as $chiave => $atteso) {
    $avuto = ReportSections::suggerimento($sezioni[$chiave]['cerca']);

    check(
        "il suggerimento di «{$chiave}» è «{$atteso}»",
        $avuto === $atteso,
        'ottenuto: «' . $avuto . '»'
    );
}

// Negli incontri due campi diversi stanno sotto la stessa colonna: il
// suggerimento la nomina una volta, altrimenti direbbe «corso o gruppo,
// corso o gruppo».
check(
    'un suggerimento non ripete due volte la stessa colonna',
    substr_count(ReportSections::suggerimento($sezioni['live']['cerca']), 'corso o gruppo') === 1
);

// Il legame fra i due file: le intestazioni citate nel suggerimento devono
// essere colonne che esistono davvero in quella tabella. Sono scritte in
// `ReportSections` e in `_colonne.php`, e due stringhe uguali scritte in
// due posti divergono alla prima modifica.
foreach ($sezioni as $chiave => $s) {
    $intestazioni = array_column($colonne[$chiave], 0);
    $sconosciute = array_values(array_diff(array_unique(array_values($s['cerca'])), $intestazioni));

    check(
        "le colonne citate dal suggerimento di «{$chiave}» esistono nella tabella",
        $sconosciute === [],
        'non sono colonne di questa tabella: ' . implode(', ', $sconosciute)
    );
}

// ---------------------------------------------------------------
// La ricerca
// ---------------------------------------------------------------

echo PHP_EOL . 'La ricerca' . PHP_EOL;

$righe = [
    ['full_name' => 'Anna Rossi', 'email' => 'anna@test.it', 'enrolled_count' => 3],
    ['full_name' => 'Bruno Bianchi', 'email' => 'bruno@esempio.it', 'enrolled_count' => 13],
    ['full_name' => 'Carla ROSSINI', 'email' => 'carla@test.it', 'enrolled_count' => 0],
];
$campi = ['full_name', 'email'];

check(
    'la ricerca vuota restituisce tutto',
    count(ReportSections::filtra($righe, $campi, '')) === 3
);
check(
    'e anche quella fatta di soli spazi',
    count(ReportSections::filtra($righe, $campi, '   ')) === 3
);
check(
    'trova una parte del nome scritta in minuscolo',
    count(ReportSections::filtra($righe, $campi, 'rossi')) === 2,
    'deve prendere «Anna Rossi» e «Carla ROSSINI»'
);
// Le due direzioni vanno provate separatamente: abbassare solo il testo
// cercato, o solo quello dei dati, passa comunque una delle due prove.
// Visto mutando la funzione.
check(
    'e la trova anche scritta tutta in maiuscolo',
    count(ReportSections::filtra($righe, $campi, 'ROSSI')) === 2
);
check(
    'maiuscole accentate comprese',
    count(ReportSections::filtra(
        [['full_name' => 'Niccolò Però', 'email' => '']],
        ['full_name'],
        'PERÒ'
    )) === 1,
    'per questo si usa mb_strtolower e non strtolower'
);
check(
    'cerca anche nei campi diversi dal primo',
    count(ReportSections::filtra($righe, $campi, 'esempio.it')) === 1
);
check(
    'non cerca nei campi che la sezione non dichiara',
    ReportSections::filtra($righe, $campi, '13') === [],
    'altrimenti scrivere un numero filtrerebbe per conteggio'
);
check(
    'senza risultati restituisce un elenco vuoto, non le righe di partenza',
    ReportSections::filtra($righe, $campi, 'zzz') === []
);
check(
    'le chiavi dell elenco filtrato ripartono da zero',
    array_keys(ReportSections::filtra($righe, $campi, 'carla')) === [0],
    'un elenco con buchi si rompe appena qualcuno lo tratta da lista'
);

// ---------------------------------------------------------------
// La paginazione
// ---------------------------------------------------------------

echo PHP_EOL . 'La paginazione' . PHP_EOL;

$per = ReportSections::PER_PAGINA;
$molte = [];

for ($i = 1; $i <= ($per * 2) + 5; $i++) {
    $molte[] = ['id' => $i];
}

$p1 = ReportSections::pagina($molte, 1);
$p3 = ReportSections::pagina($molte, 3);

check('la prima pagina ha il numero di righe previsto', count($p1['righe']) === $per);
check('e comincia dalla prima riga', $p1['righe'][0]['id'] === 1);
check('il totale e quello dell elenco intero, non della pagina', $p1['totale'] === ($per * 2) + 5);
check('le pagine sono tre', $p1['pagine'] === 3);
check('l ultima pagina contiene il resto', count($p3['righe']) === 5);
check('e comincia dove finisce la seconda', $p3['righe'][0]['id'] === ($per * 2) + 1);

$oltre = ReportSections::pagina($molte, 99);
$sotto = ReportSections::pagina($molte, -4);

check(
    'una pagina oltre l ultima porta all ultima',
    $oltre['pagina'] === 3 && count($oltre['righe']) === 5,
    'un indirizzo vecchio non deve mostrare una tabella vuota'
);
check('una pagina negativa porta alla prima', $sotto['pagina'] === 1 && $sotto['righe'][0]['id'] === 1);

$vuoto = ReportSections::pagina([], 1);

check(
    'un elenco vuoto ha comunque una pagina',
    $vuoto['pagine'] === 1 && $vuoto['pagina'] === 1 && $vuoto['righe'] === [],
    '«pagina 1 di 0» sarebbe falsa, e il conto dividerebbe per zero'
);

$esatte = ReportSections::pagina(array_slice($molte, 0, $per), 1);

check(
    'un elenco lungo esattamente una pagina non ne fa due',
    $esatte['pagine'] === 1,
    'il caso in cui ceil() sbaglia di uno'
);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
