<?php

declare(strict_types=1);

/**
 * Test della lettura del .env e del messaggio di Database quando la
 * connessione fallisce (16/10), senza database.
 *
 * Env::load() legge il file una volta sola per processo: ogni caso gira in un
 * processo PHP a parte, con un .env suo in una cartella temporanea.
 *
 * Esecuzione:  php tests/env_test.php
 */

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

$cartella = sys_get_temp_dir() . '/env_test_' . getmypid();
mkdir($cartella);
$autoload = realpath(__DIR__ . '/../vendor/autoload.php');

/**
 * Esegue $codice in un processo nuovo, dopo aver caricato $contenuto come .env.
 *
 * @return string quello che il processo ha stampato
 */
$esegui = static function (string $contenuto, string $codice) use ($cartella, $autoload): string {
    file_put_contents($cartella . '/.env', $contenuto);
    $script = $cartella . '/prova.php';
    file_put_contents($script, '<?php require ' . var_export($autoload, true) . ';'
        . ' App\Core\Env::load(' . var_export($cartella . '/.env', true) . '); ' . $codice);

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null');
};

/** Il valore di una chiave, letto da un .env con quel contenuto (in JSON, per vedere anche null). */
$valore = static fn (string $contenuto, string $chiave): string
    => $esegui($contenuto, 'echo json_encode(App\Core\Env::get(' . var_export($chiave, true) . ', "(predefinito)"));');

$bom = "\xEF\xBB\xBF";

// ---------------------------------------------------------------
echo 'Il BOM' . PHP_EOL;

$v = $valore($bom . "DB_HOST=db.esempio.it\r\nDB_NAME=lms\r\n", 'DB_HOST');
check('con il BOM la prima riga si legge lo stesso', $v === '"db.esempio.it"', $v);

$v = $valore($bom . "DB_HOST=db.esempio.it\r\nDB_NAME=lms\r\n", 'DB_NAME');
check('con il BOM anche le righe dopo', $v === '"lms"', $v);

$v = $valore($bom . "# commento\nDB_HOST=db.esempio.it\n", 'DB_HOST');
check('con il BOM davanti a un commento, la riga dopo si legge', $v === '"db.esempio.it"', $v);

$v = $valore("DB_HOST=db.esempio.it\n", 'DB_HOST');
check('senza BOM, come prima', $v === '"db.esempio.it"', $v);

$v = $valore("DB_HOST=a" . $bom . "b\n", 'DB_HOST');
check('un BOM in mezzo a un valore non si tocca: non e\' un BOM', $v === json_encode("a" . $bom . "b"), $v);

// ---------------------------------------------------------------
echo PHP_EOL . 'Le virgolette' . PHP_EOL;

$v = $valore("DB_PASS=\"segreta\"\n", 'DB_PASS');
check('fra virgolette doppie: le virgolette si tolgono', $v === '"segreta"', $v);

$v = $valore("DB_PASS='segreta'\n", 'DB_PASS');
check('fra virgolette semplici: si tolgono', $v === '"segreta"', $v);

$v = $valore("MAIL_FROM_NAME=\"Pistacchio LMS\"\n", 'MAIL_FROM_NAME');
check('fra virgolette, con uno spazio dentro', $v === '"Pistacchio LMS"', $v);

$v = $valore("DB_PASS=abc\"\n", 'DB_PASS');
check('una virgoletta solo in fondo fa parte del valore', $v === json_encode('abc"'), $v);

$v = $valore("DB_PASS='abc\n", 'DB_PASS');
check('una virgoletta solo in testa fa parte del valore', $v === json_encode("'abc"), $v);

$v = $valore("DB_PASS=\"abc'\n", 'DB_PASS');
check('due virgolette diverse non sono una coppia: restano', $v === json_encode("\"abc'"), $v);

$v = $valore("DB_PASS=\"\n", 'DB_PASS');
check('una virgoletta sola resta una virgoletta', $v === json_encode('"'), $v);

$v = $valore("DB_PASS=\"\"\n", 'DB_PASS');
check('due virgolette vuote: valore vuoto', $v === '""', $v);

$v = $valore("DB_PASS=\"\"abc\"\"\n", 'DB_PASS');
check('si toglie una coppia sola', $v === json_encode('"abc"'), $v);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il resto, come prima' . PHP_EOL;

$v = $valore("DB_PASS=a=b=c\n", 'DB_PASS');
check('un valore con «=» dentro', $v === '"a=b=c"', $v);

$v = $valore("DB_PASS=\n", 'DB_PASS');
check('un valore vuoto', $v === '""', $v);

$v = $valore("  DB_HOST =  db.esempio.it  \r\n", 'DB_HOST');
check('spazi attorno a chiave e valore, e fine riga di Windows', $v === '"db.esempio.it"', $v);

$v = $valore("APP_URL=https://x.it # produzione\n", 'APP_URL');
check('un commento in coda resta nel valore (deciso il 16/10: una password puo\' contenere « #»)',
    $v === '"https:\/\/x.it # produzione"', $v);

$v = $valore("# DB_HOST=db.esempio.it\n", 'DB_HOST');
check('una riga di commento non vale', $v === '"(predefinito)"', $v);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il messaggio di Database quando la connessione fallisce' . PHP_EOL;

// Una porta su cui non ascolta nessuno: la connessione fallisce subito, con
// o senza un database acceso.
$uscita = $esegui("DB_HOST=127.0.0.1;port=1\nDB_NAME=lms\nDB_USER=nessuno\nDB_PASS=x\n", 'App\Core\Database::connection(); echo "CONNESSO";');
check('dice che la connessione e\' fallita', str_starts_with($uscita, 'Errore di connessione al database.'), json_encode($uscita));
check('e finisce con un a capo, cosi\' nei log la riga dopo non ci si attacca', str_ends_with($uscita, "\n"), json_encode($uscita));

// ---------------------------------------------------------------
@unlink($cartella . '/.env');
@unlink($cartella . '/prova.php');
@rmdir($cartella);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
