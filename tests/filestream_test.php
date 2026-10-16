<?php

declare(strict_types=1);

/**
 * Test di FileStream::send() (16/10), senza database e senza server: le
 * richieste a pezzi (`Range`), compresa quella a suffisso, e un file che manca
 * o non si legge, che deve dare una risposta e non un errore fatale.
 *
 * Esecuzione:  php tests/filestream_test.php
 * (il caso del file illeggibile si prova solo da un utente diverso da root:
 *  root legge tutto)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\FileStream;

// Tutto quello che il test stampa resta in un buffer fino alla fine: da riga
// di comando, dopo il primo echo, PHP non accetta piu' header() ne' codici di
// risposta, e le prove misurerebbero quel rifiuto invece di FileStream.
ob_start();

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

/**
 * Una consegna, con quello che ne esce: codice, corpo, avvisi di PHP ed
 * eventuale errore fatale.
 *
 * @return array{codice: int, corpo: string, avvisi: list<string>, errore: ?string}
 */
function consegna(string $percorso, ?string $range): array
{
    $avvisi = [];
    set_error_handler(static function (int $n, string $testo) use (&$avvisi): bool {
        // Un avviso zittito con @ non arriva a nessuno: il gestore lo riceve
        // lo stesso, e lo riconosce da error_reporting().
        if ((error_reporting() & $n) === 0) {
            return true;
        }

        $avvisi[] = $testo;

        return true;
    });

    unset($_SERVER['HTTP_RANGE']);

    if ($range !== null) {
        $_SERVER['HTTP_RANGE'] = $range;
    }

    http_response_code(200);
    $errore = null;
    ob_start();

    try {
        FileStream::send($percorso, 'application/octet-stream');
    } catch (Throwable $e) {
        $errore = get_class($e) . ': ' . $e->getMessage();
    }

    $corpo = (string) ob_get_clean();
    restore_error_handler();

    return ['codice' => (int) http_response_code(), 'corpo' => $corpo, 'avvisi' => $avvisi, 'errore' => $errore];
}

$cartella = sys_get_temp_dir() . '/filestream_test_' . getmypid();
mkdir($cartella);
$file = $cartella . '/dati.bin';
$dati = '';

for ($i = 0; $i < 1000; $i++) {
    $dati .= chr($i % 251);
}

file_put_contents($file, $dati);

$esito = static fn (array $r): string => 'codice ' . $r['codice'] . ', ' . strlen($r['corpo']) . ' byte'
    . ($r['errore'] !== null ? ', ' . $r['errore'] : '')
    . ($r['avvisi'] !== [] ? ', avvisi: ' . implode(' | ', $r['avvisi']) : '');

// ---------------------------------------------------------------
echo 'Il file intero e i pezzi' . PHP_EOL;

$r = consegna($file, null);
check('senza Range: 200 e tutto il file', $r['codice'] === 200 && $r['corpo'] === $dati, $esito($r));

$r = consegna($file, 'bytes=10-19');
check('bytes=10-19: 206 e i byte dal 10 al 19', $r['codice'] === 206 && $r['corpo'] === substr($dati, 10, 10), $esito($r));

$r = consegna($file, 'bytes=990-');
check('bytes=990-: 206 e gli ultimi 10', $r['codice'] === 206 && $r['corpo'] === substr($dati, 990), $esito($r));

$r = consegna($file, 'bytes=0-5000');
check('bytes=0-5000, oltre la fine: 206 e tutto il file', $r['codice'] === 206 && $r['corpo'] === $dati, $esito($r));

$r = consegna($file, 'bytes=2000-');
check('bytes=2000-, oltre la fine: 416 e niente corpo', $r['codice'] === 416 && $r['corpo'] === '', $esito($r));

// ---------------------------------------------------------------
echo PHP_EOL . 'La richiesta a suffisso: gli ultimi N byte' . PHP_EOL;

$r = consegna($file, 'bytes=-100');
check('bytes=-100: 206 e gli ultimi 100 byte, non i primi', $r['codice'] === 206 && $r['corpo'] === substr($dati, -100), $esito($r));

$r = consegna($file, 'bytes=-1');
check('bytes=-1: l\'ultimo byte', $r['codice'] === 206 && $r['corpo'] === substr($dati, -1), $esito($r));

$r = consegna($file, 'bytes=-5000');
check('bytes=-5000, piu\' lungo del file: tutto il file', $r['codice'] === 206 && $r['corpo'] === $dati, $esito($r));

$r = consegna($file, 'bytes=-0');
check('bytes=-0: 416, non c\'e\' niente da mandare', $r['codice'] === 416 && $r['corpo'] === '', $esito($r));

// ---------------------------------------------------------------
echo PHP_EOL . 'Un file che manca o non si legge' . PHP_EOL;

$r = consegna($cartella . '/manca.bin', null);
check('file che manca: 404, senza avvisi ne\' errori fatali',
    $r['codice'] === 404 && $r['errore'] === null && $r['avvisi'] === [], $esito($r));

$r = consegna($cartella . '/manca.bin', 'bytes=0-10');
check('file che manca, chiesto a pezzi: 404 lo stesso', $r['codice'] === 404 && $r['errore'] === null && $r['avvisi'] === [], $esito($r));

$r = consegna($cartella, null);
check('una cartella al posto del file: 404', $r['codice'] === 404 && $r['errore'] === null && $r['avvisi'] === [], $esito($r));

$chiuso = $cartella . '/chiuso.bin';
file_put_contents($chiuso, $dati);
chmod($chiuso, 0000);

if (is_readable($chiuso)) {
    echo '  INFO il file illeggibile non si puo\' provare da qui (root legge tutto): '
        . 'eseguire il test da un altro utente' . PHP_EOL;
} else {
    $r = consegna($chiuso, null);
    check('file che non si legge: 500, senza avvisi ne\' errori fatali, e niente del file',
        $r['codice'] === 500 && $r['errore'] === null && $r['avvisi'] === [] && !str_contains($r['corpo'], $dati), $esito($r));
}

// ---------------------------------------------------------------
@unlink($chiuso);
@unlink($file);
@rmdir($cartella);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
ob_end_flush();
exit($fail > 0 ? 1 : 0);
