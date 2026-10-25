<?php

declare(strict_types=1);

/**
 * Le regole della Bacheca (17/10) che non hanno bisogno del database:
 * conteggi, colori, tipi, link, ordine, controllo dei campi, ritorno.
 *
 * Chi vede e chi modera (`BachecaScope`) lo prova `tests/permessi.js`.
 *
 * Esecuzione:  php tests/bacheca_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\LessonController;
use App\Core\Bacheca;
use App\Core\Theme;

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
echo 'I conteggi' . PHP_EOL;

check('nessuna risorsa', Bacheca::conta(0) === 'Nessuna risorsa', Bacheca::conta(0));
check('una risorsa', Bacheca::conta(1) === '1 risorsa', Bacheca::conta(1));
check('tre risorse', Bacheca::conta(3) === '3 risorse', Bacheca::conta(3));
check('una proposta da valutare', Bacheca::daValutare(1) === '1 proposta da valutare', Bacheca::daValutare(1));
check('due proposte da valutare', Bacheca::daValutare(2) === '2 proposte da valutare', Bacheca::daValutare(2));

// ---------------------------------------------------------------
echo PHP_EOL . 'I colori delle categorie: qualunque colore (17/10, strada 2)' . PHP_EOL;

// Il filo e lo sfondo usano il colore scelto, esattamente; il titolo la
// stessa tinta, scurita solo quanto serve a leggersi sulla tinta chiara.
$campioni = ['#FFFF00', '#FFF59D', '#FFC0CB', '#87CEEB', '#FFFFFF', '#00FF00', '#FF00FF', '#E0E0E0',
    '#000000', '#4F7256', '#1E4C70', '#B63B39', '#808080', '#FFA500', '#00FFFF', '#7F00FF'];
$deboli = [];

foreach ($campioni as $c) {
    $t = Bacheca::colore($c);
    $titolo = Theme::contrasto($t['titolo'], $t['soft']);
    $testo = Theme::contrasto('#1C1C1A', $t['soft']);

    if ($t['primary'] !== $c || $titolo < 4.5 || $testo < 4.5) {
        $deboli[] = sprintf('%s: titolo %s %.2f, testo %.2f', $c, $t['titolo'], $titolo, $testo);
    }
}

check('ogni colore resta com\'è, e il suo titolo si legge sulla tinta chiara (almeno 4,5:1)', $deboli === [], implode('; ', $deboli));

$giallo = Bacheca::colore('#FFFF00');
check('un giallo limone: il titolo è un giallo scurito, non un altro colore',
    $giallo['titolo'] !== '#FFFF00' && hexdec(substr($giallo['titolo'], 5, 2)) === 0
        && hexdec(substr($giallo['titolo'], 1, 2)) === hexdec(substr($giallo['titolo'], 3, 2)), $giallo['titolo']);
check('un colore già leggibile non si scurisce', Bacheca::colore('#1E4C70')['titolo'] === '#1E4C70');

foreach (['verde', 'ardesia', 'terracotta', 'prugna'] as $chiave) {
    $primo = Theme::TAVOLOZZE[$chiave]['primary'];
    check($chiave . ': il colore del tema ha la sua tinta di sempre, e il titolo nel colore',
        Bacheca::colore($primo) === ['primary' => $primo, 'soft' => Theme::TAVOLOZZE[$chiave]['soft'], 'titolo' => $primo]);
    check($chiave . ': la vecchia chiave dà lo stesso colore', Bacheca::colore($chiave) === Bacheca::colore($primo));
}

check('un valore che non è un colore torna verde', Bacheca::colore('fucsia')['primary'] === Theme::TAVOLOZZE['verde']['primary']);
check('un colore valido si scrive #RRGGBB maiuscolo', Bacheca::coloreValido(' #ffd700 ') === '#FFD700' && Bacheca::coloreValido('#abc') === '#AABBCC');
check('un valore che non è un colore: null', Bacheca::coloreValido('giallo') === null && Bacheca::coloreValido('#12345') === null
    && Bacheca::coloreValido('red;background:url(x)') === null);

// ---------------------------------------------------------------
echo PHP_EOL . 'I tipi e i link' . PHP_EOL;

check('i sei tipi', array_keys(Bacheca::TIPI) === ['scheda', 'checklist', 'video', 'podcast', 'articolo', 'altro']);
check('«Scheda pratica» e «Promemoria» (17/10): cambiano i nomi, non le chiavi salvate',
    Bacheca::TIPI['scheda'] === 'Scheda pratica' && Bacheca::TIPI['checklist'] === 'Promemoria');
check('il video si apre come video', Bacheca::etichettaLink('video') === 'Apri il video');
check('il podcast come podcast', Bacheca::etichettaLink('podcast') === 'Apri il podcast');
check('l\'articolo come articolo', Bacheca::etichettaLink('articolo') === 'Apri l’articolo');
check('il resto come link', Bacheca::etichettaLink('scheda') === 'Apri il link');

$casi = [
    ['https://esempio.it/pagina?a=1', 'https://esempio.it/pagina?a=1', 'un indirizzo https resta com\'è'],
    ['http://esempio.it', 'http://esempio.it', 'anche http'],
    ['  www.esempio.it/podcast  ', 'https://www.esempio.it/podcast', 'senza schema prende https'],
    ['', '', 'vuoto: nessun link'],
    ['javascript:alert(1)', null, 'javascript: no'],
    ['data:text/html,ciao', null, 'data: no'],
    ['ftp://esempio.it/file', null, 'ftp no'],
    ['https://', null, 'senza dominio no'],
    ['https://esempio .it', null, 'con uno spazio no'],
    ['https://' . str_repeat('a', 500) . '.it', null, 'oltre 500 caratteri no'],
];

foreach ($casi as [$dato, $atteso, $cosa]) {
    $avuto = Bacheca::link($dato);
    check('link: ' . $cosa, $avuto === $atteso, var_export($avuto, true));
}

// ---------------------------------------------------------------
echo PHP_EOL . 'I file' . PHP_EOL;

$materiali = (array) (new ReflectionClassConstant(LessonController::class, 'MATERIAL_EXTENSIONS'))->getValue();
// Documenti, non audio e archivi (17/10, Alessandro): i podcast sono link,
// e in 3 MB un audio sta in un paio di minuti.
check('documenti: PDF, Word, PowerPoint, Excel e testo', Bacheca::FILE_EXTENSIONS === ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt'],
    implode(', ', Bacheca::FILE_EXTENSIONS));
check('niente audio né ZIP', array_intersect(Bacheca::FILE_EXTENSIONS, ['mp3', 'wav', 'm4a', 'zip']) === []);
check('tutti fra quelli dei materiali delle lezioni', array_diff(Bacheca::FILE_EXTENSIONS, $materiali) === []);
// La dimensione no (17/10, Alessandro): 3 MB, non i 50 dei materiali. Una
// scheda o un promemoria in PDF pesano poche centinaia di kB.
check('fino a 3 MB', Bacheca::FILE_MAX_BYTES === 3 * 1024 * 1024, (string) Bacheca::FILE_MAX_BYTES);
check('il limite vero è il più basso fra il nostro e quello del php.ini',
    Bacheca::limiteFile() === min(Bacheca::FILE_MAX_BYTES, \App\Core\Upload::iniLimitBytes()),
    Bacheca::limiteFile() . ' / ini ' . \App\Core\Upload::iniLimitBytes());
check('nessun SVG', array_intersect(Bacheca::FILE_EXTENSIONS, ['svg', 'svgz']) === []);

// ---------------------------------------------------------------
echo PHP_EOL . 'L\'ordine' . PHP_EOL;

$ordinate = Bacheca::ordina([
    ['id' => 1, 'featured' => 0, 'published_at' => '2026-10-10 09:00:00'],
    ['id' => 2, 'featured' => 0, 'published_at' => '2026-10-15 09:00:00'],
    ['id' => 3, 'featured' => 1, 'published_at' => '2026-10-01 09:00:00'],
    ['id' => 4, 'featured' => 0, 'published_at' => '2026-10-15 09:00:00'],
    ['id' => 5, 'featured' => 1, 'published_at' => '2026-10-12 09:00:00'],
]);
$ids = array_map(static fn (array $r): int => $r['id'], $ordinate);
check('in evidenza sopra, poi dalla più recente', $ids === [5, 3, 4, 2, 1], implode(', ', $ids));

// ---------------------------------------------------------------
echo PHP_EOL . 'I campi di una risorsa' . PHP_EOL;

$buona = ['title' => ' Dieci domande ', 'kind' => 'scheda', 'module_id' => '3', 'category_id' => '7', 'url' => '', 'description' => "Riga uno\r\nriga due"];
$r = Bacheca::valida($buona, [7, 8], [3, 4]);
check('una risorsa giusta passa', $r['errore'] === null, (string) $r['errore']);
check('il titolo senza spazi ai lati', ($r['dati']['title'] ?? '') === 'Dieci domande');
check('la categoria come numero', ($r['dati']['category_id'] ?? 0) === 7);
check('il modulo come numero (25/10)', ($r['dati']['module_id'] ?? 0) === 3);
check('il link vuoto diventa null', array_key_exists('url', $r['dati']) && $r['dati']['url'] === null);
check('l\'a capo conta un carattere', ($r['dati']['description'] ?? '') === "Riga uno\nriga due");

$pieno = str_repeat("a\r\n", Bacheca::MAX_DESCRIZIONE / 2);
check('una descrizione piena di a capo sta nel limite', Bacheca::valida(['description' => $pieno] + $buona, [7], [3])['errore'] === null);

$errori = [
    [['title' => ''], 'manca il titolo'],
    [['title' => str_repeat('t', Bacheca::MAX_TITOLO + 1)], 'titolo troppo lungo'],
    [['kind' => 'romanzo'], 'tipo sconosciuto'],
    [['category_id' => '99'], 'categoria di un altro corso'],
    [['module_id' => ''], 'manca il modulo (25/10)'],
    [['module_id' => '99'], 'un modulo che chi scrive non puo\' scegliere (chiuso, o di un altro corso)'],
    [['description' => '   '], 'manca la descrizione'],
    [['description' => str_repeat('d', Bacheca::MAX_DESCRIZIONE + 1)], 'descrizione troppo lunga'],
    [['url' => 'javascript:alert(1)'], 'link non valido'],
    [['kind' => 'video', 'url' => ''], 'un video senza link'],
    [['kind' => 'podcast', 'url' => ''], 'un podcast senza link'],
];

foreach ($errori as [$cambio, $cosa]) {
    $esito = Bacheca::valida($cambio + $buona, [7, 8], [3, 4]);
    check('si ferma: ' . $cosa, $esito['errore'] !== null && $esito['errore'] !== '', (string) $esito['errore']);
}

$senzaModulo = Bacheca::valida(['module_id' => ''] + $buona, [7, 8], [3, 4]);
check('senza modulo, il messaggio dice di sceglierlo', $senzaModulo['errore'] === 'Scegli il modulo a cui serve la risorsa.', (string) $senzaModulo['errore']);
check('un video con il link passa', Bacheca::valida(['kind' => 'video', 'url' => 'https://esempio.it/v'] + $buona, [7], [3])['errore'] === null);

// ---------------------------------------------------------------
echo PHP_EOL . 'Chi l\'ha condivisa' . PHP_EOL;

check('il tutor', Bacheca::attribuzione('tutor', 'Mario Rossi') === 'Dal tutor');
check('l\'admin', Bacheca::attribuzione('admin', 'Elena') === 'Dallo staff');
check('uno studente, con il nome come lo vede chi guarda', Bacheca::attribuzione('studente', 'Marta C.') === 'Proposta da Marta C.');
check('un account cancellato', Bacheca::attribuzione(null, '') === 'Condivisa in bacheca');

// ---------------------------------------------------------------
echo PHP_EOL . 'Il ritorno dopo un\'azione' . PHP_EOL;

check('una pagina della bacheca', Bacheca::torna('/bacheca/categorie/4?mie=1', '/bacheca') === '/bacheca/categorie/4?mie=1');
check('un altro sito no', Bacheca::torna('https://altro.it', '/bacheca') === '/bacheca');
check('un indirizzo che comincia con // no', Bacheca::torna('//altro.it/bacheca', '/bacheca') === '/bacheca');
check('un\'altra pagina della piattaforma no', Bacheca::torna('/admin/users', '/bacheca') === '/bacheca');
check('un a capo no', Bacheca::torna("/bacheca\r\nX: y", '/bacheca') === '/bacheca');
check('/bachecaxyz no', Bacheca::torna('/bachecaxyz', '/bacheca') === '/bacheca');

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
