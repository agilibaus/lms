<?php

declare(strict_types=1);

/**
 * Test dei questionari H5P: quali file di un pacchetto si accettano, come si
 * legge h5p.json, quali punteggi si salvano, e l'apertura di pacchetti
 * costruiti apposta (uno buono, e quelli che devono essere rifiutati).
 *
 * Esecuzione:  php tests/h5p_test.php
 *
 * Niente server e niente database. I pacchetti si costruiscono qui con
 * ZipArchive e si aprono in storage/h5p con un numero di questionario
 * finto; alla fine si tolgono.
 *
 * COSA VERIFICA, e perche' proprio questo:
 *
 *   1. **Nessun file esce dalla sua cartella.** Un nome come «../../x» in
 *      un archivio («zip slip») scriverebbe ovunque sul server.
 *   2. **Niente codice eseguibile dal server o aperto come pagina**: php,
 *      html, .htaccess rifiutano l'intero pacchetto.
 *   3. **I file inutili si saltano senza fermare tutto** (.DS_Store,
 *      estensioni sconosciute), e il codice nella cartella dei dati no.
 *   4. **Un punteggio che non ha senso non diventa un tentativo**: negativo,
 *      oltre il massimo, massimo zero, testo.
 *   5. **Un file si serve solo se e' dentro la cartella del contenuto.**
 *   6. **Un contenuto senza punteggio non diventa un questionario**: non
 *      verrebbe mai superato e il modulo resterebbe incompleto.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\H5P;
use App\Core\H5PArchivio;

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

echo PHP_EOL . 'Le voci di un pacchetto' . PHP_EOL;

check('un file di libreria', H5P::voce('H5P.DragText-1.10/dist/h5p-drag-text.js') === 'H5P.DragText-1.10/dist/h5p-drag-text.js');
check('il contenuto', H5P::voce('content/content.json') === 'content/content.json');
check('un immagine del contenuto', H5P::voce('content/images/foto-1.jpg') === 'content/images/foto-1.jpg');
check('le cartelle si saltano', H5P::voce('content/') === H5P::SALTA);
check('«..» rifiuta', H5P::voce('../../public/x.json') === H5P::RIFIUTA);
check('«..» in mezzo rifiuta', H5P::voce('content/../../x.json') === H5P::RIFIUTA);
check('percorso assoluto rifiuta', H5P::voce('/etc/passwd.txt') === H5P::RIFIUTA);
check('barra rovescia rifiuta', H5P::voce('content\\x.json') === H5P::RIFIUTA);
check('lettera di unita rifiuta', H5P::voce('C:/x.json') === H5P::RIFIUTA);
check('php rifiuta', H5P::voce('H5P.X-1.0/shell.php') === H5P::RIFIUTA);
check('php in maiuscolo rifiuta', H5P::voce('H5P.X-1.0/shell.PHP') === H5P::RIFIUTA);
check('phtml rifiuta', H5P::voce('content/a.phtml') === H5P::RIFIUTA);
check('html rifiuta', H5P::voce('content/pagina.html') === H5P::RIFIUTA);
check('.htaccess rifiuta', H5P::voce('H5P.X-1.0/.htaccess') === H5P::RIFIUTA);
check('.DS_Store si salta', H5P::voce('content/.DS_Store') === H5P::SALTA);
check('estensione sconosciuta si salta', H5P::voce('content/note.xyz') === H5P::SALTA);
check('js nella cartella dei dati si salta', H5P::voce('content/x.js') === H5P::SALTA);
check('carattere di controllo rifiuta', H5P::voce("content/a\x00.json") === H5P::RIFIUTA);

echo PHP_EOL . 'h5p.json' . PHP_EOL;

$d = H5P::descrizione('{"title":"Esercizio","mainLibrary":"H5P.DragText","preloadedDependencies":[{"machineName":"H5P.DragText","majorVersion":1,"minorVersion":10}]}');
check('titolo e tipo', $d === ['titolo' => 'Esercizio', 'libreria' => 'H5P.DragText 1.10', 'principale' => 'H5P.DragText'], json_encode($d));
check('Drag the Words da un punteggio', H5P::senzaPunteggio('H5P.DragText') === null);
check('Accordion non da punteggio', H5P::senzaPunteggio('H5P.Accordion') === 'Accordion');
check('un tipo sconosciuto si prova', H5P::senzaPunteggio('H5P.TipoNuovo') === null);
check('Course Presentation si prova (puo avere domande)', H5P::senzaPunteggio('H5P.CoursePresentation') === null);
check('senza mainLibrary non e un pacchetto', H5P::descrizione('{"title":"x"}') === null);
check('un nome di libreria strano no', H5P::descrizione('{"mainLibrary":"<script>"}') === null);
check('non JSON', H5P::descrizione('ciao') === null);

echo PHP_EOL . 'Il punteggio' . PHP_EOL;

check('5 su 5', H5P::punteggio(5, 5) === ['raw' => 5.0, 'max' => 5.0, 'pct' => 100.0]);
check('3 su 5 e il 60%', (H5P::punteggio('3', '5')['pct'] ?? null) === 60.0);
check('zero e un punteggio', H5P::punteggio(0, 5) !== null);
check('negativo no', H5P::punteggio(-1, 5) === null);
check('oltre il massimo no', H5P::punteggio(6, 5) === null);
check('massimo zero no', H5P::punteggio(0, 0) === null);
check('massimo enorme no', H5P::punteggio(1, 1e9) === null);
check('testo no', H5P::punteggio('tanti', 5) === null && H5P::punteggio(null, 5) === null);
check('array no', H5P::punteggio([1], 5) === null);
check('«3,5 su 10»', H5P::punti(3.5, 10) === '3,5 su 10');

echo PHP_EOL . 'Il tipo dei file serviti' . PHP_EOL;

check('js', H5P::tipo('a/b.js') === 'text/javascript');
check('svg', H5P::tipo('a/b.SVG') === 'image/svg+xml');
check('php nessuno', H5P::tipo('a/b.php') === null);

echo PHP_EOL . 'L apertura dei pacchetti' . PHP_EOL;

$tmp = sys_get_temp_dir() . '/h5p-test-' . bin2hex(random_bytes(4));
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
$base = [
    'h5p.json' => '{"title":"Prova","mainLibrary":"H5P.Prova","preloadedDependencies":[{"machineName":"H5P.Prova","majorVersion":1,"minorVersion":2}]}',
    'content/content.json' => '{}',
    'H5P.Prova-1.2/library.json' => '{}',
    'H5P.Prova-1.2/prova.js' => 'void 0;',
    'content/.DS_Store' => 'x',
];
$aperte = [];

if (!H5PArchivio::disponibile()) {
    check('estensione zip presente (serve ai test di apertura)', false);
} else {
    $a = H5PArchivio::apri($pacchetto($base), 999999);
    $aperte[] = $a['dir'];
    check('un pacchetto buono si apre', $a['titolo'] === 'Prova' && $a['libreria'] === 'H5P.Prova 1.2', json_encode($a));
    check('la cartella la sceglie Pistacchio', preg_match('/^q999999-[0-9a-f]{12}$/', $a['dir']) === 1);
    check('i file ci sono', is_file(H5PArchivio::RADICE . '/' . $a['dir'] . '/H5P.Prova-1.2/prova.js'));
    check('.DS_Store saltato', !file_exists(H5PArchivio::RADICE . '/' . $a['dir'] . '/content/.DS_Store'));
    check('un file del contenuto si trova', H5PArchivio::file($a['dir'], 'content/content.json') !== null);
    check('«..» non esce dalla cartella', H5PArchivio::file($a['dir'], '../' . $a['dir'] . '/h5p.json') === null);
    check('una cartella inventata no', H5PArchivio::file('../../config', 'config.php') === null);
    check('un file che non c e', H5PArchivio::file($a['dir'], 'content/nessuno.json') === null);

    $rifiutato = static function (array $file) use ($pacchetto): ?string {
        try {
            $a = H5PArchivio::apri($pacchetto($file), 999998);
            H5PArchivio::elimina($a['dir']);

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };

    $accordion = [
        'h5p.json' => '{"title":"Fisarmonica","mainLibrary":"H5P.Accordion","preloadedDependencies":[{"machineName":"H5P.Accordion","majorVersion":1,"minorVersion":0}]}',
        'content/content.json' => '{}',
        'H5P.Accordion-1.0/library.json' => '{}',
    ];
    $msg = $rifiutato($accordion);
    check('un Accordion come questionario rifiutato, con il suo nome', $msg !== null && str_contains($msg, '«Accordion»') && str_contains($msg, 'lezione'), (string) $msg);
    $msg = $rifiutato(['h5p.json' => $base['h5p.json'], 'content/content.json' => '{}']);
    check('senza il codice della libreria rifiutato (23/10)', $msg !== null && str_contains($msg, 'H5P.Prova'), (string) $msg);
    check('zip slip rifiutato', $rifiutato($base + ['../../fuori.json' => '{}']) !== null);
    check('php rifiutato', $rifiutato($base + ['H5P.Prova-1.2/x.php' => '<?php']) !== null);
    check('html rifiutato', $rifiutato($base + ['content/x.html' => '<script>']) !== null);
    check('senza h5p.json rifiutato', $rifiutato(['content/content.json' => '{}']) !== null);
    check('senza content.json rifiutato', $rifiutato(['h5p.json' => $base['h5p.json']]) !== null);
    $nonZip = $tmp . '/finto.h5p';
    file_put_contents($nonZip, 'non sono un archivio');

    try {
        H5PArchivio::apri($nonZip, 999997);
        check('un file che non e un archivio rifiutato', false);
    } catch (RuntimeException $e) {
        check('un file che non e un archivio rifiutato', true);
    }

    check('nessuna cartella lasciata dai rifiutati', glob(H5PArchivio::RADICE . '/q999998-*') === [] && glob(H5PArchivio::RADICE . '/q999997-*') === []);

    H5PArchivio::elimina($a['dir']);
    check('eliminata la cartella, sparisce', !is_dir(H5PArchivio::RADICE . '/' . $a['dir']));
}

array_map('unlink', glob($tmp . '/*') ?: []);
rmdir($tmp);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
