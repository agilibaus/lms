<?php

declare(strict_types=1);

/**
 * Test del catalogo dei caratteri (§8.5, patch 0084).
 *
 * Esecuzione:  php tests/caratteri_test.php
 *
 * Non tocca il database e **non esce su internet**: verifica l'indice, il
 * nome dei file, il riconoscimento del woff2 e la lettura del foglio di
 * stile di Google a partire da risposte scritte qui dentro.
 *
 * QUELLO CHE QUESTO FILE NON PUO' PROVARE, e che va detto invece di
 * lasciarlo credere: **lo scaricamento vero da Google**. Il contenitore in
 * cui gira lo sviluppo blocca `fonts.googleapis.com`, quindi il percorso
 * felice — chiedo il CSS, leggo l'indirizzo, scarico il woff2 — si puo'
 * esercitare solo fino al punto in cui parte la richiesta. Qui si provano
 * tutti i pezzi attorno: che l'indirizzo chiesto a Google sia quello
 * giusto, che dalla sua risposta si estragga il file giusto, che un errore
 * di rete diventi un messaggio comprensibile, e che il ripiego manuale
 * funzioni fino in fondo. Il primo giro contro Google vero lo fa Elena, in
 * un clic, dal suo Laragon.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\FontLibrary;

$ok = 0;
$fail = 0;

function check(string $label, bool $condizione, string $dettaglio = ''): void
{
    global $ok, $fail;
    $condizione ? $ok++ : $fail++;
    echo ($condizione ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condizione && $dettaglio !== '') {
        echo '         · ' . $dettaglio . PHP_EOL;
    }
}

// ---------------------------------------------------------------
echo PHP_EOL . "L'indice del catalogo" . PHP_EOL;

$catalogo = FontLibrary::catalogo();

check('il catalogo si legge', $catalogo !== [], 'manca o non si apre database/google-fonts.json');
check('contiene piu di mille famiglie', count($catalogo) > 1000, (string) count($catalogo));

foreach (['Albert Sans', 'Roboto', 'Lora', 'Playfair Display', 'JetBrains Mono', 'Inter'] as $famiglia) {
    check("c'e' {$famiglia}", FontLibrary::esiste($famiglia));
}

check('una famiglia inventata non c\'e\'', !FontLibrary::esiste('Carattere Che Non Esiste'));

// Il generico non e' decorazione: durante l'attesa, e se il file sparisse,
// e' lui a disegnare il testo. Un serif sostituito da un sans si vede.
$generici = ['sans-serif', 'serif', 'monospace', 'cursive'];

foreach (['Lora' => 'serif', 'Playfair Display' => 'serif', 'JetBrains Mono' => 'monospace',
          'Roboto' => 'sans-serif', 'Dancing Script' => 'cursive'] as $famiglia => $atteso) {
    check(
        "{$famiglia} ripiega su {$atteso}",
        FontLibrary::generico($famiglia) === $atteso,
        'ottenuto: ' . FontLibrary::generico($famiglia)
    );
}

$fuoriElenco = [];

foreach ($catalogo as $famiglia => $generico) {
    if (!in_array($generico, $generici, true)) {
        $fuoriElenco[] = $famiglia . ' => ' . $generico;
    }
}

check(
    'ogni famiglia ha un generico valido',
    $fuoriElenco === [],
    implode(', ', array_slice($fuoriElenco, 0, 5))
);

check(
    'una famiglia sconosciuta ripiega comunque su qualcosa',
    FontLibrary::generico('Carattere Che Non Esiste') === 'sans-serif'
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il nome del file' . PHP_EOL;

check('Albert Sans', FontLibrary::nomeFile('Albert Sans') === 'albert-sans.woff2');
check('Playfair Display', FontLibrary::nomeFile('Playfair Display') === 'playfair-display.woff2');
check('IBM Plex Sans', FontLibrary::nomeFile('IBM Plex Sans') === 'ibm-plex-sans.woff2');
check('Press Start 2P', FontLibrary::nomeFile('Press Start 2P') === 'press-start-2p.woff2');

// La regola dev'essere **una sola**: due nomi diversi per la stessa
// famiglia vorrebbero dire scaricarla due volte e servirne una a caso.
check(
    'lo stesso nome da' . "'" . ' sempre lo stesso file',
    FontLibrary::nomeFile('Albert Sans') === FontLibrary::nomeFile('Albert Sans')
);
check(
    'maiuscole e spazi non cambiano il risultato',
    FontLibrary::nomeFile('Albert  Sans') === FontLibrary::nomeFile('Albert Sans')
);

// Il nome finisce in un percorso: niente che possa uscirne.
foreach (['../../etc/passwd', 'a/b', 'a\\b', '..', 'nome con / dentro'] as $cattivo) {
    $prodotto = FontLibrary::nomeFile($cattivo);
    check(
        'da «' . $cattivo . '» esce un nome innocuo: ' . $prodotto,
        !str_contains($prodotto, '/') && !str_contains($prodotto, '\\') && !str_contains($prodotto, '..')
    );
}

// ---------------------------------------------------------------
echo PHP_EOL . 'Che cosa si chiede a Google, e che cosa se ne ricava' . PHP_EOL;

$indirizzo = FontLibrary::indirizzoCss('Playfair Display');

check('si chiede a fonts.googleapis.com', str_starts_with($indirizzo, 'https://fonts.googleapis.com/css2?'));
check('lo spazio nel nome e codificato', str_contains($indirizzo, 'Playfair%20Display'), $indirizzo);
check('si chiedono tre pesi', str_contains($indirizzo, 'wght@400;600;700'), $indirizzo);

// Una risposta come quella vera di Google, scritta qui: cosi' la lettura si
// prova senza rete.
$cssVero = <<<'CSS'
/* cyrillic */
@font-face {
  font-family: 'Lora';
  font-style: normal;
  font-weight: 400;
  src: url(https://fonts.gstatic.com/s/lora/v35/cirillico.woff2) format('woff2');
  unicode-range: U+0301, U+0400-045F;
}
/* latin */
@font-face {
  font-family: 'Lora';
  font-style: normal;
  font-weight: 400;
  src: url(https://fonts.gstatic.com/s/lora/v35/latino.woff2) format('woff2');
  unicode-range: U+0000-00FF;
}
CSS;

check(
    'dal foglio di stile si estrae il primo woff2',
    FontLibrary::primoWoff2($cssVero) === 'https://fonts.gstatic.com/s/lora/v35/cirillico.woff2',
    (string) FontLibrary::primoWoff2($cssVero)
);
check('da un foglio senza woff2 non si estrae niente', FontLibrary::primoWoff2('body{color:red}') === null);
check(
    'non si prende un indirizzo di un altro sito',
    FontLibrary::primoWoff2('@font-face{src:url(https://esempio.it/rubato.woff2)}') === null,
    'un CSS intercettato potrebbe far scaricare qualunque cosa'
);
check(
    'un .ttf non viene scambiato per un woff2',
    FontLibrary::primoWoff2('@font-face{src:url(https://fonts.gstatic.com/s/lora/v35/x.ttf)}') === null
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il ripiego: il file caricato a mano' . PHP_EOL;

$woff2 = __DIR__ . '/../public/assets/fonts/albert-sans-latin.woff2';

if (!is_file($woff2)) {
    echo '  --   manca il file di Albert Sans: prove sul caricamento saltate' . PHP_EOL;
} else {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'font');
    copy($woff2, $tmp);

    $gia = FontLibrary::presente('Albert Sans');

    try {
        FontLibrary::accettaCaricato('Albert Sans', ['tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK]);
        check('un woff2 vero viene accettato', FontLibrary::presente('Albert Sans'));
    } catch (Throwable $e) {
        check('un woff2 vero viene accettato', false, $e->getMessage());
    }

    // Un file che non e' un carattere: si guarda il CONTENUTO, non il nome,
    // perche' il nome lo decide chi carica.
    $finto = (string) tempnam(sys_get_temp_dir(), 'font');
    file_put_contents($finto, str_repeat('questo non e un carattere', 100));

    try {
        FontLibrary::accettaCaricato('Lora', ['tmp_name' => $finto, 'size' => filesize($finto), 'error' => UPLOAD_ERR_OK]);
        check('un file qualunque viene respinto', false, 'accettato: il controllo della firma non ha funzionato');
    } catch (Throwable $e) {
        check('un file qualunque viene respinto', str_contains($e->getMessage(), 'woff2'), $e->getMessage());
    }

    try {
        FontLibrary::accettaCaricato('Famiglia Inventata', ['tmp_name' => $tmp, 'size' => 1, 'error' => UPLOAD_ERR_OK]);
        check('una famiglia fuori catalogo viene respinta', false);
    } catch (Throwable $e) {
        check('una famiglia fuori catalogo viene respinta', str_contains($e->getMessage(), 'catalogo'));
    }

    unlink($tmp);
    unlink($finto);

    // Si lascia il disco com'era: un test non deve cambiare la
    // configurazione dell'installazione su cui gira.
    if (!$gia && FontLibrary::presente('Albert Sans')) {
        unlink(FontLibrary::percorso('Albert Sans'));
    }

    check('il test non lascia niente dietro di se', $gia === FontLibrary::presente('Albert Sans'));
}

// ---------------------------------------------------------------
echo PHP_EOL . 'Quando lo scaricamento non riesce: i messaggi' . PHP_EOL;

// Senza rete: si prova la funzione che scrive il messaggio, su tutti i casi.
// Fino al 06/10 questa parte chiedeva davvero a Google e controllava il
// messaggio ottenuto, quindi il suo esito dipendeva dalla rete di chi la
// eseguiva: verde nei contenitori che rifiutavano la connessione, rosso in
// quello il cui proxy rispondeva 403, mai eseguita dove Google risponde.
// Ed e' cosi' che il messaggio per il 403 era rimasto senza una strada.

$host = 'fonts.googleapis.com';
$casi = [
    'connessione rifiutata' => FontLibrary::messaggioErrore($host, 'Failed to connect', 0, 'text/css'),
    'risposta 403' => FontLibrary::messaggioErrore($host, '', 403, 'text/css'),
    'risposta 407' => FontLibrary::messaggioErrore($host, '', 407, 'text/css'),
    'risposta 429' => FontLibrary::messaggioErrore($host, '', 429, 'text/css'),
    'risposta 503' => FontLibrary::messaggioErrore($host, '', 503, 'text/css'),
    'risposta 404' => FontLibrary::messaggioErrore($host, '', 404, 'text/css'),
];

foreach ($casi as $caso => $messaggio) {
    check(
        $caso . ': dice che cosa fare',
        str_contains($messaggio, 'a mano'),
        'un messaggio che dice solo «non riuscito» lascia chi lo legge senza una strada'
    );
    check($caso . ': nomina il server', str_contains($messaggio, $host), $messaggio);
}

check(
    'connessione rifiutata: dice che il server non e\' uscito, e perche\' succede',
    str_contains($casi['connessione rifiutata'], 'non è riuscito a raggiungere')
        && str_contains($casi['connessione rifiutata'], 'Failed to connect')
        && str_contains($casi['connessione rifiutata'], 'connessioni in uscita'),
    $casi['connessione rifiutata']
);

foreach (['risposta 403', 'risposta 407', 'risposta 404'] as $caso) {
    check(
        $caso . ': dice che e\' stata rifiutata, il codice e di chiedere al fornitore',
        str_contains($casi[$caso], 'rifiutata')
            && str_contains($casi[$caso], 'codice ' . substr($caso, -3))
            && str_contains($casi[$caso], 'fornitore'),
        $casi[$caso]
    );
}

foreach (['risposta 429', 'risposta 503'] as $caso) {
    check(
        $caso . ': dice che passa da solo e di riprovare, non di chiamare il fornitore',
        str_contains($casi[$caso], 'Riprova')
            && str_contains($casi[$caso], 'codice ' . substr($caso, -3))
            && !str_contains($casi[$caso], 'fornitore'),
        $casi[$caso]
    );
}

// ---------------------------------------------------------------
echo PHP_EOL . 'Lo scaricamento vero, solo come informazione' . PHP_EOL;

// Qui si chiede davvero a Google, ma **non si verifica niente**: l'esito
// dipende dalla rete di chi esegue il test, non dal codice. Serve a dire, a
// chi lo lancia dal proprio PC, se lo scaricamento funziona davvero — che
// dal contenitore di sviluppo non si puo' sapere.
$gia = FontLibrary::presente('Lora');

try {
    FontLibrary::scarica('Lora');

    $scaricato = (string) file_get_contents(FontLibrary::percorso('Lora'));
    $buono = substr($scaricato, 0, 4) === 'wOF2';

    // L'unica verifica di questa parte: se un file e' arrivato, deve essere
    // un carattere. Un file sbagliato sul disco e' un difetto del codice, non
    // della rete.
    check('qui Google risponde: il file scaricato e un woff2', $buono, 'primi quattro byte: ' . substr($scaricato, 0, 4));

    // **Il file si toglie se non era gia' li' OPPURE se non e' valido.**
    // La prima stesura lo toglieva solo nel primo caso, e durante una prova
    // di mutazione ha lasciato sul disco un file che non era un carattere:
    // il giro successivo lo ha trovato, lo ha creduto buono perche' c'era, e
    // ha fallito senza che il codice avesse piu' niente che non andava. Un
    // test che sporca l'installazione mente al giro dopo.
    if (!$gia || !$buono) {
        unlink(FontLibrary::percorso('Lora'));
    }
} catch (RuntimeException $e) {
    echo '  --   da qui Google non si raggiunge, e il pannello direbbe:' . PHP_EOL;
    echo '         · ' . $e->getMessage() . PHP_EOL;
}

try {
    FontLibrary::scarica('Famiglia Inventata');
    check('una famiglia fuori catalogo non si scarica', false);
} catch (RuntimeException $e) {
    check('una famiglia fuori catalogo non si scarica', str_contains($e->getMessage(), 'catalogo'));
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
