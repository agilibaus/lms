<?php

declare(strict_types=1);

/**
 * La versione di Pistacchio LMS (18/10), senza database: il file VERSION
 * e la sua lettura.
 *
 * Esecuzione:  php tests/versione_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Versione;

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

$file = __DIR__ . '/../VERSION';
$scritta = is_file($file) ? trim((string) file_get_contents($file)) : '';

check('il file VERSION c\'è, nella cartella principale', is_file($file));
check('dice maggiore.minore.patch (es. 1.0.236)', preg_match('/^\d+\.\d+\.\d+$/', $scritta) === 1, $scritta);
check('Versione::corrente() lo legge', Versione::corrente() === $scritta, Versione::corrente());

$tmp = sys_get_temp_dir() . '/versione_test_' . getmypid();
file_put_contents($tmp, "  2.1.300\n");
check('spazi e a capo intorno non contano', Versione::da($tmp) === '2.1.300', (string) Versione::da($tmp));
file_put_contents($tmp, "<script>alert(1)</script>");
check('un contenuto che non è una versione non si mostra', Versione::da($tmp) === null);
unlink($tmp);
check('un file che manca: nessuna versione', Versione::da($tmp) === null);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
