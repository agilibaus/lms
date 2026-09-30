<?php

declare(strict_types=1);

/**
 * Test della firma degli indirizzi di Bunny Stream.
 *
 * Esecuzione:  php tests/bunny_token_test.php
 *
 * Il calcolo del token si verifica **senza toccare Bunny**: è un hash di tre
 * valori noti, e il risultato atteso si calcola qui accanto con la stessa
 * formula della documentazione. È l'unica parte di questo lavoro che si può
 * collaudare dal container (pistacchio-lms.md Sezione 7.1); che poi Bunny
 * accetti davvero quel token si prova solo nell'ambiente di Elena, con un
 * video vero.
 *
 * L'ultima parte richiede il database, perché legge le impostazioni: le
 * chiavi `BUNNY_*` vengono svuotate all'inizio e rimesse com'erano alla fine,
 * altrimenti il test cambierebbe la configurazione dell'installazione
 * (trappola già incontrata con `live_session_mail_test.php`, Sezione 5).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\BunnyToken;
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

echo PHP_EOL . '--- Calcolo del token' . PHP_EOL;

$chiave = 'chiave-di-prova-123';
$video = 'abcd1234-5678-90ef-ghij-klmnopqrstuv';
$scadenza = 1767225600;

$atteso = hash('sha256', $chiave . $video . $scadenza);

check('e\' SHA256 di chiave + video + scadenza', BunnyToken::sign($video, $scadenza, $chiave) === $atteso);
check('e\' esadecimale di 64 caratteri', (bool) preg_match('/^[0-9a-f]{64}$/', BunnyToken::sign($video, $scadenza, $chiave)));

// Se uno solo dei tre valori cambia, il token deve cambiare: e' cio' che
// impedisce di riusare la firma di un video per un altro, o dopo la scadenza.
check('cambia col video', BunnyToken::sign('altro-video', $scadenza, $chiave) !== $atteso);
check('cambia con la scadenza', BunnyToken::sign($video, $scadenza + 1, $chiave) !== $atteso);
check('cambia con la chiave', BunnyToken::sign($video, $scadenza, 'chiave-diversa') !== $atteso);

check(
    'lo stesso ingresso da' . ' sempre lo stesso token',
    BunnyToken::sign($video, $scadenza, $chiave) === BunnyToken::sign($video, $scadenza, $chiave)
);

echo PHP_EOL . '--- Impostazioni e indirizzo (richiede il database)' . PHP_EOL;

try {
    Database::connection();
} catch (Throwable $e) {
    echo '  Database non raggiungibile: questa parte non gira.' . PHP_EOL;
    echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
    exit($fail === 0 ? 0 : 1);
}

/** @var array<string, string|null> $originali */
$originali = [];

foreach (Settings::BUNNY_KEYS as $key) {
    $originali[$key] = Settings::stored($key);
    Settings::set($key, null);
}

Settings::forget();

try {
    // --- senza chiave: indirizzo nudo, come prima della firma -------
    Settings::set('BUNNY_LIBRARY_ID', '12345');
    Settings::forget();

    check('senza chiave la firma e\' spenta', !BunnyToken::isConfigured());
    check(
        'senza chiave l\'indirizzo non porta token',
        BunnyToken::embedUrl($video) === 'https://iframe.mediadelivery.net/embed/12345/' . rawurlencode($video)
    );

    // --- con chiave: indirizzo firmato ------------------------------
    Settings::set('BUNNY_TOKEN_KEY', $chiave);
    Settings::forget();

    check('con chiave e libreria la firma e\' accesa', BunnyToken::isConfigured());

    $adesso = 1767225600;
    $url = BunnyToken::embedUrl($video, $adesso);
    $attesoExpires = $adesso + BunnyToken::DEFAULT_TTL_HOURS * 3600;

    check('l\'indirizzo porta la scadenza calcolata dalla durata', str_contains($url, 'expires=' . $attesoExpires));
    check(
        'il token nell\'indirizzo e\' quello della scadenza',
        str_contains($url, 'token=' . hash('sha256', $chiave . $video . $attesoExpires))
    );
    check('l\'indirizzo resta quello della libreria giusta', str_starts_with($url, 'https://iframe.mediadelivery.net/embed/12345/'));

    // La chiave non deve mai finire nell'indirizzo: e' l'unico modo in cui
    // potrebbe arrivare al browser.
    check('la chiave non compare nell\'indirizzo', !str_contains($url, $chiave));

    // --- durata ------------------------------------------------------
    check('senza valore vale il predefinito', BunnyToken::ttlHours() === BunnyToken::DEFAULT_TTL_HOURS);

    Settings::set('BUNNY_TOKEN_TTL_HOURS', '3');
    Settings::forget();
    check('una durata valida viene usata', BunnyToken::ttlHours() === 3);

    $url3 = BunnyToken::embedUrl($video, $adesso);
    check('e la scadenza la segue', str_contains($url3, 'expires=' . ($adesso + 3 * 3600)));

    // Valori fuori scala: non devono produrre token gia' scaduti o eterni.
    foreach (['0', '-5', '99999', 'tre'] as $sbagliato) {
        Settings::set('BUNNY_TOKEN_TTL_HOURS', $sbagliato);
        Settings::forget();
        check('durata «' . $sbagliato . '» ripiega sul predefinito', BunnyToken::ttlHours() === BunnyToken::DEFAULT_TTL_HOURS);
    }

    // --- libreria mancante -------------------------------------------
    // Svuotare la chiave in tabella non basta a togliere la libreria: se il
    // .env ne ha una, quella vale ancora — ed e' il ripiego voluto (Sezione 4).
    // Il controllo ha senso solo dove il file non la porta.
    Settings::set('BUNNY_LIBRARY_ID', null);
    Settings::forget();

    if (BunnyToken::libraryId() === '') {
        check('senza libreria la firma e\' spenta anche con la chiave', !BunnyToken::isConfigured());
    } else {
        // `libraryId()` qui non e' vuoto per costruzione: siamo nel ramo in
        // cui il .env la fornisce. Resta da verificare che la firma la usi.
        check('la libreria del .env vale quando la tabella e\' vuota', BunnyToken::isConfigured());
    }
} finally {
    foreach ($originali as $key => $valore) {
        Settings::set($key, $valore);
    }

    Settings::forget();
}

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
