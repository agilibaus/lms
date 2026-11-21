<?php

declare(strict_types=1);

/**
 * L'anteprima dei caratteri nel pannello (21/11, Alessandro), senza rete:
 * `FontLibrary::anteprima()`, la pulizia dopo 12 ore e il passaggio
 * dall'anteprima alla cartella vera al Salva.
 *
 * Esecuzione:  php tests/caratteri_anteprima_test.php
 *
 * Mette da parte `storage/fonts` e lo rimette alla fine. Come file di
 * carattere usa quello di Albert Sans del progetto, con altri nomi. Il
 * contenitore di sviluppo non raggiunge Google: dove servirebbe, il test
 * verifica che la richiesta fallisca con un'eccezione, non che riesca.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\FontLibrary;

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

$cartella = __DIR__ . '/../storage/fonts';
$parcheggio = __DIR__ . '/../storage/fonts.prova-anteprima';
$albert = __DIR__ . '/../public/assets/fonts/albert-sans-latin.woff2';
$cera = is_dir($cartella);

$svuota = static function (string $dir) use (&$svuota): void {
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        is_dir("$dir/$n") ? $svuota("$dir/$n") : unlink("$dir/$n");
    }
    is_dir($dir) && rmdir($dir);
};

if ($cera) {
    rename($cartella, $parcheggio);
}
register_shutdown_function(static function () use ($cartella, $parcheggio, $cera, $svuota): void {
    $svuota($cartella);
    if ($cera) {
        rename($parcheggio, $cartella);
    }
});

mkdir($cartella . '/anteprime', 0775, true);
$anteprima = static function (string $famiglia, int $ore = 0) use ($albert): void {
    copy($albert, FontLibrary::percorsoAnteprima($famiglia));
    touch(FontLibrary::percorsoAnteprima($famiglia), time() - $ore * 3600);
};

echo 'Le anteprime e il tempo' . PHP_EOL;
$anteprima('Lora');
$anteprima('Comfortaa', 13);
$anteprima('Roboto', 11);
check('un\'anteprima di 11 ore è ancora buona, una di 13 no',
    FontLibrary::anteprimaFresca('Roboto') && !FontLibrary::anteprimaFresca('Comfortaa'));
FontLibrary::pulisciAnteprime();
check('la pulizia cancella solo quelle con più di 12 ore',
    !is_file(FontLibrary::percorsoAnteprima('Comfortaa')) && is_file(FontLibrary::percorsoAnteprima('Lora'))
        && is_file(FontLibrary::percorsoAnteprima('Roboto')));

echo PHP_EOL . 'Il file da mostrare' . PHP_EOL;
check('un\'anteprima già pronta si usa, senza chiederla a Google',
    FontLibrary::anteprima('Lora') === FontLibrary::percorsoAnteprima('Lora'));
copy($albert, FontLibrary::percorso('Oswald'));
check('un carattere già sul server si mostra dal suo file', FontLibrary::anteprima('Oswald') === FontLibrary::percorso('Oswald'));
$errore = '';
try {
    FontLibrary::anteprima('Merriweather');
} catch (RuntimeException $e) {
    $errore = $e->getMessage();
}
check('un carattere nuovo si chiede a Google (qui la rete è chiusa: eccezione, nessun file)',
    $errore !== '' && !is_file(FontLibrary::percorsoAnteprima('Merriweather')), $errore);
$errore = '';
try {
    FontLibrary::anteprima('Carattere Inventato');
} catch (RuntimeException $e) {
    $errore = $e->getMessage();
}
check('una famiglia fuori catalogo si rifiuta', str_contains($errore, 'non è nel catalogo'), $errore);
$anteprima('Comfortaa', 13);
FontLibrary::anteprima('Lora');
check('chiedere un\'anteprima toglie quelle scadute', !is_file(FontLibrary::percorsoAnteprima('Comfortaa')));

echo PHP_EOL . 'Al Salva' . PHP_EOL;
FontLibrary::scarica('Lora');
check('il carattere passa dall\'anteprima alla cartella vera',
    FontLibrary::presente('Lora') && !is_file(FontLibrary::percorsoAnteprima('Lora')));
$anteprima('Comfortaa', 13);
$errore = '';
try {
    FontLibrary::scarica('Comfortaa');
} catch (RuntimeException $e) {
    $errore = $e->getMessage();
}
check('un\'anteprima scaduta non si usa: si richiede a Google', $errore !== '' && !FontLibrary::presente('Comfortaa'), $errore);

echo PHP_EOL . 'Le anteprime restano fuori da quello che c\'è sul server' . PHP_EOL;
$anteprima('Roboto');
check('né fra i caratteri sul server né fra i file non riconosciuti',
    FontLibrary::scaricati() === ['Lora', 'Oswald'] && FontLibrary::nonRiconosciuti() === [],
    json_encode([FontLibrary::scaricati(), FontLibrary::nonRiconosciuti()]));

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
