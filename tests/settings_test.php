<?php

declare(strict_types=1);

/**
 * Test delle impostazioni salvate in tabella con il .env come ripiego.
 *
 * Esecuzione:  php tests/settings_test.php
 *
 * ATTENZIONE: scrive davvero nella tabella `settings` del database indicato
 * nel .env, quindi va eseguito su un'installazione di sviluppo. Le chiavi
 * usate vengono salvate all'inizio e rimesse com'erano alla fine, anche se
 * un'asserzione fallisce.
 *
 * Senza database raggiungibile il test si ferma dicendolo, invece di
 * fallire: la parte di ripiego sul .env resta comunque verificata.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\Settings;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

// ---------------------------------------------------------------
echo 'Chiavi scrivibili' . PHP_EOL;

check('una chiave di posta è scrivibile', Settings::isWritable('MAIL_HOST'));
check('una chiave di Google è scrivibile', Settings::isWritable('GOOGLE_CALENDAR_ID'));
check('una chiave estranea non lo è', !Settings::isWritable('DB_PASS'));
check('la password SMTP è marcata come segreta', Settings::isSecret('MAIL_PASSWORD'));
check('il server SMTP non è un segreto', !Settings::isSecret('MAIL_HOST'));

$rifiutata = false;

try {
    Settings::set('DB_PASS', 'tentativo');
} catch (\InvalidArgumentException $e) {
    $rifiutata = true;
} catch (\Throwable $e) {
    // Senza database arriva un'altra eccezione: il controllo sulle chiavi
    // viene comunque prima, quindi qui non ci si dovrebbe arrivare.
}

check('scrivere una chiave non consentita solleva un errore', $rifiutata);

// ---------------------------------------------------------------
try {
    Database::connection()->query('SELECT 1 FROM settings LIMIT 1');
} catch (\Throwable $e) {
    echo PHP_EOL . 'Database o tabella settings non disponibili: il resto del test non viene eseguito.' . PHP_EOL;
    echo 'Esegui la migrazione 2026_09_17_impostazioni.sql e riprova.' . PHP_EOL;
    echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
    exit($fail === 0 ? 0 : 1);
}

// Valori da rimettere a posto alla fine.
$chiavi = ['MAIL_FROM_NAME', 'MAIL_HOST', 'MAIL_PORT'];
$prima = [];

foreach ($chiavi as $chiave) {
    $prima[$chiave] = Settings::stored($chiave);
}

$ripristina = static function () use ($chiavi, $prima): void {
    foreach ($chiavi as $chiave) {
        Settings::set($chiave, $prima[$chiave]);
    }

    Settings::forget();
};

register_shutdown_function($ripristina);

// ---------------------------------------------------------------
echo PHP_EOL . 'Scrittura e lettura' . PHP_EOL;

Settings::set('MAIL_FROM_NAME', 'Nome di prova');
check('il valore appena scritto si rilegge', Settings::get('MAIL_FROM_NAME') === 'Nome di prova');
check('risulta salvato in tabella', Settings::stored('MAIL_FROM_NAME') === 'Nome di prova');
check('la provenienza è il database', Settings::source('MAIL_FROM_NAME') === 'database');

// Rilettura da zero: il valore deve arrivare dal database, non dalla cache.
Settings::forget();
check('sopravvive allo svuotamento della cache', Settings::get('MAIL_FROM_NAME') === 'Nome di prova');

// ---------------------------------------------------------------
echo PHP_EOL . 'Una scrittura non deve oscurare le altre chiavi' . PHP_EOL;

// Questa è la regressione da cui nasce il test: set() scriveva nella cache
// senza averla prima caricata, e da quel momento ogni altra lettura nella
// stessa richiesta tornava vuota, ripiegando sul .env.
Settings::set('MAIL_HOST', 'smtp.prova.it');
Settings::forget();
Settings::set('MAIL_PORT', '2525');

check('la chiave scritta prima è ancora leggibile', Settings::get('MAIL_HOST') === 'smtp.prova.it');
check('anche la seconda è al suo posto', Settings::get('MAIL_PORT') === '2525');
check('e la prima di tutte non è sparita', Settings::get('MAIL_FROM_NAME') === 'Nome di prova');

// ---------------------------------------------------------------
echo PHP_EOL . 'Svuotare restituisce il comando al .env' . PHP_EOL;

Settings::set('MAIL_HOST', null);
check('la riga viene cancellata', Settings::stored('MAIL_HOST') === null);

$dalFile = getenv('MAIL_HOST');
check(
    'il valore in vigore torna a essere quello del file (o niente)',
    Settings::get('MAIL_HOST', 'niente') === (($dalFile !== false && $dalFile !== '') ? $dalFile : (\App\Core\Env::get('MAIL_HOST', 'niente')))
);

Settings::set('MAIL_PORT', '');
check('anche la stringa vuota cancella la riga', Settings::stored('MAIL_PORT') === null);

// ---------------------------------------------------------------
echo PHP_EOL . 'Provenienza dei valori' . PHP_EOL;

// Si usa una chiave che il test controlla: le altre potrebbero essere state
// scritte dal pannello su questa installazione.
Settings::set('MAIL_FROM_NAME', 'Ancora una prova');
check('scritta dal pannello, la provenienza è il database', Settings::source('MAIL_FROM_NAME') === 'database');

Settings::set('MAIL_FROM_NAME', null);
$daFile = \App\Core\Env::get('MAIL_FROM_NAME', '');
check(
    'cancellata, la provenienza torna al file o a niente',
    Settings::source('MAIL_FROM_NAME') === (($daFile !== null && $daFile !== '') ? 'file' : 'assente')
);
check('e non risulta più salvata in tabella', Settings::stored('MAIL_FROM_NAME') === null);

// ---------------------------------------------------------------
$ripristina();

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
