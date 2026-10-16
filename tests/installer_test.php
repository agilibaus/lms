<?php

declare(strict_types=1);

/**
 * Test dell'auto-blocco dell'installer (16/10), senza database: quando la
 * procedura guidata si considera chiusa.
 *
 * La regola: chiusa se c'e' storage/installed.lock oppure se c'e' il .env.
 * Fino al 16/10, senza lock, l'installer leggeva il .env con una regola sua e
 * provava a collegarsi: un .env con il BOM, una password con una virgoletta
 * in fondo o un database spento per un minuto lo facevano riaprire su un
 * sito gia' installato.
 *
 * Esecuzione:  php tests/installer_test.php
 */

define('LMS_INSTALLER', true);
require __DIR__ . '/../public/install/Installer.php';

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

// Una radice di progetto finta, con la sua cartella storage.
$radice = sys_get_temp_dir() . '/installer_test_' . getmypid();
mkdir($radice . '/storage', 0775, true);
$env = $radice . '/.env';
$lock = $radice . '/storage/installed.lock';

$pulisci = static function () use ($env, $lock): void {
    @unlink($env);
    @unlink($lock);
};

$chiuso = static fn (): bool => (new Installer($radice))->isInstalled();

// Un database su cui non risponde nessuno: se l'installer provasse a
// collegarsi, la connessione fallirebbe subito.
$envSenzaDatabase = "DB_HOST=127.0.0.1;port=1\nDB_NAME=lms\nDB_USER=nessuno\nDB_PASS=x\n";

// ---------------------------------------------------------------
echo 'Quando la procedura e\' aperta' . PHP_EOL;

$pulisci();
check('niente lock e niente .env: aperta (prima installazione)', $chiuso() === false);

// ---------------------------------------------------------------
echo PHP_EOL . 'Quando e\' chiusa' . PHP_EOL;

$pulisci();
touch($lock);
check('con il lock: chiusa', $chiuso() === true);

$pulisci();
file_put_contents($env, $envSenzaDatabase);
check('con il .env e il database che non risponde: chiusa', $chiuso() === true);

$pulisci();
file_put_contents($env, "\xEF\xBB\xBF" . $envSenzaDatabase);
check('con il .env salvato con il BOM: chiusa', $chiuso() === true);

$pulisci();
file_put_contents($env, "DB_HOST=127.0.0.1;port=1\nDB_PASS=abc\"\n");
check('con una password che finisce con una virgoletta: chiusa', $chiuso() === true);

$pulisci();
file_put_contents($env, '');
check('con un .env vuoto: chiusa (il file c\'e\', e la piattaforma non manda piu\' a /install)', $chiuso() === true);

$pulisci();
file_put_contents($env, $envSenzaDatabase);
touch($lock);
check('con tutti e due: chiusa', $chiuso() === true);

// ---------------------------------------------------------------
$pulisci();
@rmdir($radice . '/storage');
@rmdir($radice);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
