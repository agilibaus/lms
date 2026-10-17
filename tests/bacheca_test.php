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

check('nessuna risorsa', Bacheca::conta(0) === 'Ancora nessuna risorsa', Bacheca::conta(0));
check('una risorsa', Bacheca::conta(1) === '1 risorsa', Bacheca::conta(1));
check('tre risorse', Bacheca::conta(3) === '3 risorse', Bacheca::conta(3));
check('una proposta da valutare', Bacheca::daValutare(1) === '1 proposta da valutare', Bacheca::daValutare(1));
check('due proposte da valutare', Bacheca::daValutare(2) === '2 proposte da valutare', Bacheca::daValutare(2));

// ---------------------------------------------------------------
echo PHP_EOL . 'I colori delle categorie' . PHP_EOL;

check('sono le quattro tavolozze del tema', array_keys(Bacheca::COLORI) === ['verde', 'ardesia', 'terracotta', 'prugna'],
    implode(', ', array_keys(Bacheca::COLORI)));

foreach (array_keys(Bacheca::COLORI) as $chiave) {
    $c = Bacheca::colore($chiave);
    check($chiave . ': colore e tinta sono quelli del tema, gia\' verificati per il contrasto',
        $c['primary'] === Theme::TAVOLOZZE[$chiave]['primary'] && $c['soft'] === Theme::TAVOLOZZE[$chiave]['soft'],
        json_encode($c));
}

check('un colore sconosciuto torna verde', Bacheca::colore('fucsia')['primary'] === Theme::TAVOLOZZE['verde']['primary']);

// ---------------------------------------------------------------
echo PHP_EOL . 'I tipi e i link' . PHP_EOL;

check('i sei tipi', array_keys(Bacheca::TIPI) === ['scheda', 'checklist', 'video', 'podcast', 'articolo', 'altro']);
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
check('si caricano gli stessi tipi dei materiali delle lezioni', Bacheca::FILE_EXTENSIONS === $materiali,
    implode(', ', Bacheca::FILE_EXTENSIONS));
check('e la stessa dimensione massima', Bacheca::FILE_MAX_BYTES
    === (int) (new ReflectionClassConstant(LessonController::class, 'MATERIAL_MAX_BYTES'))->getValue());
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

$buona = ['title' => ' Dieci domande ', 'kind' => 'scheda', 'category_id' => '7', 'url' => '', 'description' => "Riga uno\r\nriga due"];
$r = Bacheca::valida($buona, [7, 8]);
check('una risorsa giusta passa', $r['errore'] === null, (string) $r['errore']);
check('il titolo senza spazi ai lati', ($r['dati']['title'] ?? '') === 'Dieci domande');
check('la categoria come numero', ($r['dati']['category_id'] ?? 0) === 7);
check('il link vuoto diventa null', array_key_exists('url', $r['dati']) && $r['dati']['url'] === null);
check('l\'a capo conta un carattere', ($r['dati']['description'] ?? '') === "Riga uno\nriga due");

$pieno = str_repeat("a\r\n", Bacheca::MAX_DESCRIZIONE / 2);
check('una descrizione piena di a capo sta nel limite', Bacheca::valida(['description' => $pieno] + $buona, [7])['errore'] === null);

$errori = [
    [['title' => ''], 'manca il titolo'],
    [['title' => str_repeat('t', Bacheca::MAX_TITOLO + 1)], 'titolo troppo lungo'],
    [['kind' => 'romanzo'], 'tipo sconosciuto'],
    [['category_id' => '99'], 'categoria di un altro corso'],
    [['description' => '   '], 'manca la descrizione'],
    [['description' => str_repeat('d', Bacheca::MAX_DESCRIZIONE + 1)], 'descrizione troppo lunga'],
    [['url' => 'javascript:alert(1)'], 'link non valido'],
    [['kind' => 'video', 'url' => ''], 'un video senza link'],
    [['kind' => 'podcast', 'url' => ''], 'un podcast senza link'],
];

foreach ($errori as [$cambio, $cosa]) {
    $esito = Bacheca::valida($cambio + $buona, [7, 8]);
    check('si ferma: ' . $cosa, $esito['errore'] !== null && $esito['errore'] !== '', (string) $esito['errore']);
}

check('un video con il link passa', Bacheca::valida(['kind' => 'video', 'url' => 'https://esempio.it/v'] + $buona, [7])['errore'] === null);

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
