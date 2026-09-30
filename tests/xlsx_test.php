<?php

declare(strict_types=1);

/**
 * Test dell'export XLSX scritto in casa (App\Core\Xlsx).
 *
 * Esecuzione:  php tests/xlsx_test.php
 *
 * Non serve il database. Il file .xlsx e' un archivio zip di XML: qui si
 * controlla che ci sia tutto quello che Excel pretende, che l'XML sia ben
 * formato, che le date diventino numeri seriali giusti e che i caratteri
 * scomodi non rompano il file. Un solo byte fuori posto rende il foglio
 * illeggibile, e Excel non dice perche': per questo il controllo e' sui
 * contenuti e non sul fatto che il file esista.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Xlsx;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

if (!Xlsx::disponibile()) {
    echo 'Estensione zip non disponibile: questi test non possono girare.' . PHP_EOL;
    exit(1);
}

/**
 * Costruisce un foglio e restituisce i contenuti dei pezzi dell'archivio.
 *
 * @param string[] $header
 * @param array<int, array<int, string|int|float|null>> $rows
 * @param array<int, string> $types
 * @return array<string, string>
 */
function parts(array $header, array $rows, array $types = [], string $sheetName = 'Foglio1'): array
{
    $percorso = Xlsx::build($header, $rows, $types, $sheetName);

    if ($percorso === null) {
        echo 'Impossibile creare il file temporaneo.' . PHP_EOL;
        exit(1);
    }

    $zip = new ZipArchive();
    $zip->open($percorso);

    $pezzi = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nome = (string) $zip->getNameIndex($i);
        $pezzi[$nome] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink($percorso);

    return $pezzi;
}

echo PHP_EOL . '--- Ossatura dell\'archivio' . PHP_EOL;

$pezzi = parts(['Nome', 'Data'], [['Anna', '2026-09-30 08:06:07']], ['testo', 'data']);

foreach ([
    '[Content_Types].xml',
    '_rels/.rels',
    'xl/workbook.xml',
    'xl/_rels/workbook.xml.rels',
    'xl/styles.xml',
    'xl/worksheets/sheet1.xml',
] as $atteso) {
    check('presente ' . $atteso, isset($pezzi[$atteso]));
}

echo PHP_EOL . '--- XML ben formato' . PHP_EOL;

foreach ($pezzi as $nome => $contenuto) {
    $precedente = libxml_use_internal_errors(true);
    $documento = simplexml_load_string($contenuto);
    libxml_clear_errors();
    libxml_use_internal_errors($precedente);

    check('ben formato ' . $nome, $documento !== false);
}

echo PHP_EOL . '--- Ordine degli elementi del foglio' . PHP_EOL;

$foglio = $pezzi['xl/worksheets/sheet1.xml'];
$posViste = strpos($foglio, '<sheetViews');
$posColonne = strpos($foglio, '<cols>');
$posDati = strpos($foglio, '<sheetData>');

check('sheetViews prima di cols', $posViste !== false && $posColonne !== false && $posViste < $posColonne);
check('cols prima di sheetData', $posColonne !== false && $posDati !== false && $posColonne < $posDati);

echo PHP_EOL . '--- Intestazione e riquadri bloccati' . PHP_EOL;

check('intestazione con lo stile grassetto', str_contains($foglio, '<c r="A1" s="1" t="inlineStr">'));
check('prima riga bloccata', str_contains($foglio, 'state="frozen"'));
check('nome del foglio nel workbook', str_contains($pezzi['xl/workbook.xml'], 'name="Foglio1"'));

echo PHP_EOL . '--- Date come numeri seriali' . PHP_EOL;

// 30/12/1899 e' lo zero di Excel; 01/01/1900 e' 2, non 1.
$date = parts(['Data'], [
    ['1899-12-30 00:00:00'],
    ['1900-01-01 00:00:00'],
    ['2026-09-30 00:00:00'],
    ['2026-09-30 12:00:00'],
], ['data'])['xl/worksheets/sheet1.xml'];

check('lo zero di Excel', str_contains($date, '<c r="A2" s="2"><v>0</v></c>'));
check('01/01/1900 vale 2', str_contains($date, '<c r="A3" s="2"><v>2</v></c>'));
check('30/09/2026 vale 46295', str_contains($date, '<c r="A4" s="2"><v>46295</v></c>'));
check('mezzogiorno aggiunge mezza giornata', str_contains($date, '<c r="A5" s="2"><v>46295.5</v></c>'));

$rotta = parts(['Data'], [['ieri mattina']], ['data'])['xl/worksheets/sheet1.xml'];

// Regola di Sezione 4 del promemoria: un valore sbagliato resta visibile
// invece di sparire.
check('una data illeggibile viene scritta com\'e\'', str_contains($rotta, '<t>ieri mattina</t>'));

echo PHP_EOL . '--- Numeri e celle vuote' . PHP_EOL;

$numeri = parts(['N', 'Vuoto'], [[42, null], ['7', '']], ['numero', 'testo'])['xl/worksheets/sheet1.xml'];

check('il numero non e\' una stringa', str_contains($numeri, '<c r="A2"><v>42</v></c>'));
check('una stringa numerica dichiarata numero resta numero', str_contains($numeri, '<c r="A3"><v>7</v></c>'));
check('null non produce una cella', !str_contains($numeri, '<c r="B2"'));
check('stringa vuota non produce una cella', !str_contains($numeri, '<c r="B3"'));

echo PHP_EOL . '--- Caratteri scomodi' . PHP_EOL;

$scomodi = parts(
    ['Testo'],
    [
        ['Tizio & Caio <s> "virgolette"'],
        ["riga\ncon a capo"],
        ["campanella\x07e byte nullo\x00"],
        ['Città, però, nell’Emilia'],
    ],
    ['testo']
)['xl/worksheets/sheet1.xml'];

check('la e commerciale e le parentesi angolari sono protette', str_contains($scomodi, 'Tizio &amp; Caio &lt;s&gt;'));
check('l\'a capo resta nel testo', str_contains($scomodi, "riga\ncon a capo"));
check('i caratteri di controllo sono tolti', !str_contains($scomodi, "\x07") && !str_contains($scomodi, "\x00"));
check('gli accenti restano in UTF-8', str_contains($scomodi, 'Città, però, nell’Emilia'));

$precedente = libxml_use_internal_errors(true);
$documento = simplexml_load_string($scomodi);
libxml_clear_errors();
libxml_use_internal_errors($precedente);
check('il foglio resta leggibile con quei caratteri dentro', $documento !== false);

echo PHP_EOL . '--- Riferimenti di cella oltre la colonna Z' . PHP_EOL;

$intestazione = [];

for ($i = 1; $i <= 28; $i++) {
    $intestazione[] = 'C' . $i;
}

$larga = parts($intestazione, [array_fill(0, 28, 'x')])['xl/worksheets/sheet1.xml'];

check('la 26esima colonna e\' Z', str_contains($larga, '<c r="Z1"'));
check('la 27esima colonna e\' AA', str_contains($larga, '<c r="AA1"'));
check('la 28esima colonna e\' AB', str_contains($larga, '<c r="AB1"'));

echo PHP_EOL . '--- Nome del foglio' . PHP_EOL;

// Excel non accetta nomi di foglio oltre i 31 caratteri.
$lungo = parts(['A'], [['x']], ['testo'], str_repeat('N', 40))['xl/workbook.xml'];

check('il nome del foglio e\' tagliato a 31 caratteri', str_contains($lungo, 'name="' . str_repeat('N', 31) . '"'));

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
