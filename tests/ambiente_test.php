<?php

declare(strict_types=1);

/**
 * I dati per l'assistenza in «Informazioni su Pistacchio LMS» (18/10),
 * senza database: come si scrivono PHP e database, e da dove viene la data
 * di installazione.
 *
 * Esecuzione:  php tests/ambiente_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Ambiente;

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

echo 'PHP e database' . PHP_EOL;

check('PHP: maggiore.minore.patch, senza aggiunte del pacchetto', Ambiente::php() === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION, Ambiente::php());

$casi = [
    ['10.11.14-MariaDB-0ubuntu0.24.04.1', 'MariaDB 10.11.14'],
    ['5.5.5-10.6.12-MariaDB', 'MariaDB 10.6.12'],
    ['8.0.30', 'MySQL 8.0.30'],
    ['8.4.2-log', 'MySQL 8.4.2'],
    ['', 'non nota'],
    ['qualcosa di strano', 'non nota'],
];

foreach ($casi as [$dato, $atteso]) {
    check('«' . $dato . '» → ' . $atteso, Ambiente::nomeDatabase($dato) === $atteso, Ambiente::nomeDatabase($dato));
}

echo PHP_EOL . 'La data di installazione' . PHP_EOL;

$cartella = sys_get_temp_dir() . '/ambiente_test_' . getmypid();
mkdir($cartella);
$lock = $cartella . '/installed.lock';
$env = $cartella . '/.env';

check('senza lock né .env: non nota', Ambiente::installazioneDa($lock, $env) === null);

file_put_contents($env, "DB_HOST=localhost\n");
touch($env, mktime(9, 0, 0, 3, 5, 2026));
check('solo il .env (installazione a mano): la sua data', Ambiente::installazioneDa($lock, $env) === '05/03/2026', (string) Ambiente::installazioneDa($lock, $env));

file_put_contents($lock, "Installazione completata il 2026-10-16T08:25:12+02:00\n");
touch($lock, mktime(9, 0, 0, 1, 1, 2027));
check('il lock dell\'installer: la data scritta dentro, non quella del file', Ambiente::installazioneDa($lock, $env) === '16/10/2026', (string) Ambiente::installazioneDa($lock, $env));

file_put_contents($lock, "scritto a mano\n");
touch($lock, mktime(9, 0, 0, 7, 20, 2026));
check('un lock senza data: la data del file', Ambiente::installazioneDa($lock, $env) === '20/07/2026', (string) Ambiente::installazioneDa($lock, $env));

unlink($lock);
unlink($env);
rmdir($cartella);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
