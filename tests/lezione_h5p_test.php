<?php

declare(strict_types=1);

/**
 * Test dei contenuti H5P nelle lezioni (23/10, passo 3): il segnaposto nel
 * testo, come diventa l'esercizio, e l'apertura di un pacchetto per una
 * lezione.
 *
 * Esecuzione:  php tests/lezione_h5p_test.php
 *
 * Niente server e niente database: i pacchetti si costruiscono con
 * ZipArchive e si aprono in storage/h5p con un numero di lezione finto.
 *
 * COSA VERIFICA, e perche' proprio questo:
 *
 *   1. **Il segnaposto sopravvive al sanificatore, e solo lui**: un
 *      `data-h5p` con un numero su un div resta; su altri tag, o con altro
 *      dentro, sparisce. Il resto del div (classi, contenteditable,
 *      gestori di eventi) se ne va come sempre.
 *   2. **Ogni esercizio una volta sola, dove l'ha messo l'autore**: un
 *      segnaposto ripetuto compare al primo posto; quello di un contenuto
 *      eliminato sparisce; quelli non messi vanno dopo il testo.
 *   3. **Il testo intorno non cambia.**
 *   4. **Una lezione con solo segnaposto non e' «solo video»**, ma il
 *      segnaposto non conta come testo da leggere.
 *   5. **Nelle lezioni va bene qualunque tipo**, anche senza punteggio, in
 *      una cartella sua («l…»); senza il codice della libreria no.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\H5PController;
use App\Core\H5P;
use App\Core\H5PArchivio;
use App\Core\HtmlSanitizer;
use App\Core\LezioneH5P;

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

echo PHP_EOL . 'Il segnaposto e il sanificatore' . PHP_EOL;

$pulito = (string) HtmlSanitizer::clean('<p>Prima</p><div class="h5p-segnaposto" contenteditable="false" data-h5p="12" onclick="x()">Esercizio</div><p>Dopo</p>');
check('resta con il suo numero', str_contains($pulito, '<div data-h5p="12">Esercizio</div>'), $pulito);
check('senza classi, contenteditable, onclick', !str_contains($pulito, 'class=') && !str_contains($pulito, 'contenteditable') && !str_contains($pulito, 'onclick'), $pulito);
$pulito = (string) HtmlSanitizer::clean('<div data-h5p="12x">a</div><div data-h5p="">b</div><p data-h5p="3">c</p><span data-h5p="4">d</span>');
check('senza un numero, o su altri tag, sparisce', !str_contains($pulito, 'data-h5p'), $pulito);

echo PHP_EOL . 'Dal segnaposto all esercizio' . PHP_EOL;

$testo = '<p>Uno</p><div data-h5p="7">Esercizio interattivo: a.h5p</div><h2>Due</h2>'
    . '<ul><li>voce<div data-h5p="8">b</div></li></ul>'
    . '<div data-h5p="7">ripetuto</div><div data-h5p="99">eliminato</div><p>Tre</p>';
check('i segnaposto, in ordine e senza ripetizioni', LezioneH5P::segnaposti($testo) === [7, 8, 99]);
check('nessun segnaposto in un testo senza', LezioneH5P::segnaposti('<p>niente</p>') === [] && LezioneH5P::segnaposti(null) === []);

$composto = LezioneH5P::componi($testo, [7, 8, 9], static fn (int $id): string => '<figure id="es-' . $id . '"></figure>');
$html = $composto['html'];
check('l esercizio al posto del segnaposto', str_contains($html, '<p>Uno</p><figure id="es-7"></figure><h2>Due</h2>'), $html);
check('anche dentro un elenco', str_contains($html, '<li>voce<figure id="es-8"></figure></li>'), $html);
check('il ripetuto una volta sola', substr_count($html, 'es-7') === 1, $html);
check('l eliminato sparisce', !str_contains($html, 'eliminato') && !str_contains($html, 'data-h5p'), $html);
check('il testo intorno resta', str_contains($html, '<p>Tre</p>'), $html);
check('quelli messi', $composto['messi'] === [7, 8]);
$dopo = LezioneH5P::dopoIlTesto([['id' => 7], ['id' => 9], ['id' => 8], ['id' => 10]], $composto['messi']);
check('quelli non messi dopo il testo, nel loro ordine', array_column($dopo, 'id') === [9, 10]);

$senza = LezioneH5P::componi('<p>Solo testo, «virgolette» &amp; accenti: è</p>', [1], static fn (int $id): string => 'X');
check('un testo senza segnaposto non si tocca', $senza['html'] === '<p>Solo testo, «virgolette» &amp; accenti: è</p>' && $senza['messi'] === []);
$accenti = LezioneH5P::componi('<p>perché è così</p><div data-h5p="1">x</div>', [1], static fn (int $id): string => '[E]');
check('gli accenti restano accenti', str_contains($accenti['html'], 'perché è così') || str_contains($accenti['html'], 'perch&eacute;'), $accenti['html']);

check('solo segnaposto: niente testo da leggere', LezioneH5P::testoSenzaSegnaposti('<div data-h5p="3">Esercizio interattivo</div>') === '');
check('con un paragrafo: c e testo', LezioneH5P::testoSenzaSegnaposti('<p>Ciao</p><div data-h5p="3">x</div>') === 'Ciao');

echo PHP_EOL . 'Nomi e indirizzi' . PHP_EOL;

check('il nome del tipo', H5P::nome('H5P.DragText 1.10') === 'Drag the Words' && H5P::nome('H5P.Accordion 1.0') === 'Accordion');
check('un tipo sconosciuto senza «H5P.»', H5P::nome('H5P.NuovoTipo 2.1') === 'NuovoTipo');
$c = ['id' => 12, 'lesson_id' => 5, 'h5p_dir' => 'l5-0123456789ab', 'h5p_file' => 'stop.h5p', 'h5p_library' => 'H5P.DragText 1.10'];
check('l etichetta del segnaposto', LezioneH5P::etichetta($c) === 'Esercizio interattivo: stop.h5p (Drag the Words)');
check('l indirizzo cambia con la cartella', H5PController::percorsoLezione($c) === '/lessons/5/h5p/12-0123456789ab');

echo PHP_EOL . 'L apertura per una lezione' . PHP_EOL;

$tmp = sys_get_temp_dir() . '/lezione-h5p-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$pacchetto = static function (array $file) use ($tmp): string {
    $percorso = $tmp . '/' . bin2hex(random_bytes(4)) . '.h5p';
    $zip = new ZipArchive();
    $zip->open($percorso, ZipArchive::CREATE);

    foreach ($file as $nome => $contenuto) {
        $zip->addFromString($nome, $contenuto);
    }

    $zip->close();

    return $percorso;
};

if (!H5PArchivio::disponibile()) {
    check('estensione zip presente (serve ai test di apertura)', false);
} else {
    $accordion = [
        'h5p.json' => '{"title":"Emozioni","mainLibrary":"H5P.Accordion","preloadedDependencies":[{"machineName":"H5P.Accordion","majorVersion":1,"minorVersion":0}]}',
        'content/content.json' => '{}',
        'H5P.Accordion-1.0/library.json' => '{}',
        'H5P.Accordion-1.0/accordion.js' => 'void 0;',
    ];
    $a = H5PArchivio::apriPerLezione($pacchetto($accordion), 999999);
    check('un Accordion in una lezione si apre', $a['principale'] === 'H5P.Accordion' && $a['titolo'] === 'Emozioni', json_encode($a));
    check('nella sua cartella «l»', preg_match('/^l999999-[0-9a-f]{12}$/', $a['dir']) === 1, $a['dir']);
    check('i suoi file si servono', H5PArchivio::file($a['dir'], 'H5P.Accordion-1.0/accordion.js') !== null);
    H5PArchivio::elimina($a['dir']);
    check('e si elimina', !is_dir(H5PArchivio::RADICE . '/' . $a['dir']));

    try {
        H5PArchivio::apriPerLezione($pacchetto(['h5p.json' => $accordion['h5p.json'], 'content/content.json' => '{}']), 999998);
        check('senza la libreria rifiutato', false);
    } catch (RuntimeException $e) {
        check('senza la libreria rifiutato', str_contains($e->getMessage(), 'H5P.Accordion'), $e->getMessage());
    }

    try {
        H5PArchivio::apriPerLezione($pacchetto($accordion + ['content/x.php' => '<?php']), 999998);
        check('le regole dei file valgono anche qui', false);
    } catch (RuntimeException $e) {
        check('le regole dei file valgono anche qui', true);
    }

    check('nessuna cartella lasciata dai rifiutati', glob(H5PArchivio::RADICE . '/l999998-*') === []);
    check('una cartella con un nome inventato no', H5PArchivio::file('x5-0123456789ab', 'h5p.json') === null);
}

array_map('unlink', glob($tmp . '/*') ?: []);
rmdir($tmp);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
