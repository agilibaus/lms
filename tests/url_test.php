<?php

declare(strict_types=1);

/**
 * Test di `App\Core\Url` (29/10): l'indirizzo dei collegamenti nelle email.
 *
 * Con APP_URL si usa APP_URL. Senza, l'host della richiesta solo se e' un
 * indirizzo di sviluppo sul proprio computer: un host qualunque lo sceglie
 * chi fa la richiesta, e il link di reimpostazione della password finirebbe
 * sul suo sito (verifica di sicurezza, punto 7).
 *
 * Esecuzione:  php tests/url_test.php
 *
 * Ogni caso gira in un processo nuovo con un .env suo, come env_test.php:
 * il .env si legge una volta sola per processo.
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

$cartella = sys_get_temp_dir() . '/url_test_' . getmypid();
@mkdir($cartella);
$autoload = realpath(__DIR__ . '/../vendor/autoload.php');

/** Il link che uscirebbe con questo APP_URL e questo host della richiesta. */
$link = static function (string $appUrl, string $host) use ($cartella, $autoload): string {
    file_put_contents($cartella . '/.env', 'APP_URL=' . $appUrl . "\n");
    $script = $cartella . '/prova.php';
    file_put_contents($script, '<?php require ' . var_export($autoload, true) . ';'
        . ' App\Core\Env::load(' . var_export($cartella . '/.env', true) . ');'
        . ' $_SERVER["HTTP_HOST"] = ' . var_export($host, true) . ';'
        . ' echo App\Core\Url::to("/password/reimposta/abc");');

    return trim((string) shell_exec('APP_URL= ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null'));
};

try {
    echo 'Con APP_URL' . PHP_EOL;
    $l = $link('https://corsi.esempio.it/', 'cattivo.example');
    check('si usa APP_URL, non l\'host della richiesta', $l === 'https://corsi.esempio.it/password/reimposta/abc', $l);

    echo PHP_EOL . 'Senza APP_URL' . PHP_EOL;
    $l = $link('', 'cattivo.example');
    check('un host qualunque non finisce nel link', !str_contains($l, 'cattivo') && $l === 'http://localhost/password/reimposta/abc', $l);
    $l = $link('', 'corsi.esempio.it:443');
    check('nemmeno uno che sembra vero', !str_contains($l, 'esempio'), $l);
    $l = $link('', 'localhost.cattivo.example');
    check('nemmeno uno che comincia con «localhost»', !str_contains($l, 'cattivo'), $l);
    $l = $link('', 'cattivo.example/x.test');
    check('nemmeno uno che finisce in «.test» dopo una barra', !str_contains($l, 'cattivo'), $l);
    $l = $link('', '127.0.0.1:8123');
    check('in sviluppo sul proprio computer: l\'host della richiesta, con la porta', $l === 'http://127.0.0.1:8123/password/reimposta/abc', $l);
    $l = $link('', 'lms.test');
    check('anche un nome di Laragon in «.test»', $l === 'http://lms.test/password/reimposta/abc', $l);
    $l = $link('', 'localhost:8000');
    check('e «localhost» con la porta', $l === 'http://localhost:8000/password/reimposta/abc', $l);
} finally {
    @unlink($cartella . '/.env');
    @unlink($cartella . '/prova.php');
    @rmdir($cartella);
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
