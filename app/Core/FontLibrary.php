<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * I caratteri scelti dal catalogo di Google Fonts, **ospitati da noi**.
 *
 * COME FUNZIONA, e perche' cosi'. L'elenco delle famiglie viaggia con il
 * progetto (`database/google-fonts.json`, 1941 famiglie, 50 KB): serve solo
 * a riempire la tendina, e un elenco statico non chiede ne' chiave ne' rete
 * per essere letto. Quando l'admin sceglie una famiglia, **il server**
 * scarica quel singolo file una volta sola e lo mette in `storage/fonts/`.
 * Da li' in poi lo serve Pistacchio.
 *
 * **Nessun visitatore contatta mai Google.** E' la ragione di tutto
 * l'impianto: un `<link>` a `fonts.googleapis.com` farebbe arrivare a Google
 * l'indirizzo IP di chi apre la pagina di accesso, prima ancora che abbia
 * fatto accesso — pratica che nel 2022 il Landgericht di Monaco ha giudicato
 * in violazione del GDPR. Qui l'unica macchina che parla con Google e' la
 * nostra, una volta per carattere.
 *
 * SI PASSA DAL FOGLIO DI STILE DI GOOGLE E NON DAL FILE GREZZO. L'endpoint
 * `css2` restituisce un CSS che contiene l'indirizzo di un **woff2** gia'
 * compresso e ridotto all'alfabeto latino: una trentina di KB. Il `.ttf` che
 * sta su GitHub e' lo stesso carattere a 130 KB, e convertirlo in PHP non si
 * puo'. Si scarica quindi il CSS, si legge l'indirizzo e si prende il file.
 *
 * SE IL SERVER NON PUO' USCIRE — hosting condiviso con le connessioni in
 * uscita chiuse — **lo scaricamento fallisce e l'impostazione non viene
 * salvata**. E' voluto: salvare il nome di un carattere il cui file non
 * esiste vorrebbe dire pagine che chiedono un file inesistente a ogni
 * caricamento. La pagina lo dice e resta il caricamento manuale del file,
 * che funziona ovunque.
 */
class FontLibrary
{
    /** Dove finiscono i file scaricati. Fuori dal document root, come tutto. */
    private const CARTELLA = __DIR__ . '/../../storage/fonts';

    private const INDICE = __DIR__ . '/../../database/google-fonts.json';

    /** Quanto si aspetta Google prima di rinunciare, in secondi. */
    private const ATTESA = 15;

    /**
     * I pesi che si scaricano. Non tutti: una famiglia puo' averne nove, e
     * ognuno e' un file in piu' su ogni pagina. Questi tre sono quelli che
     * il foglio di stile usa davvero — testo normale, etichette e titoli in
     * semigrassetto, titoli in grassetto.
     */
    private const PESI = '400;600;700';

    /**
     * Il catalogo: nome della famiglia => famiglia generica di ripiego
     * (`sans-serif`, `serif`, `monospace`, `cursive`).
     *
     * Il generico serve a due cose: mentre il carattere si carica il testo
     * si disegna con qualcosa di simile invece che con qualcosa di diverso,
     * e se un giorno il file sparisse la pagina non resterebbe senza.
     *
     * @return array<string, string>
     */
    public static function catalogo(): array
    {
        static $catalogo = null;

        if ($catalogo === null) {
            $grezzo = @file_get_contents(self::INDICE);
            $decodificato = $grezzo === false ? null : json_decode($grezzo, true);
            $catalogo = is_array($decodificato) ? $decodificato : [];
        }

        return $catalogo;
    }

    public static function esiste(string $famiglia): bool
    {
        return isset(self::catalogo()[$famiglia]);
    }

    public static function generico(string $famiglia): string
    {
        return self::catalogo()[$famiglia] ?? 'sans-serif';
    }

    /**
     * Il nome del file di una famiglia. Deterministico, e **non ricavato dal
     * nome cosi' com'e'**: "Playfair Display" diventa `playfair-display`.
     * Senza una regola unica, lo stesso carattere finirebbe scritto in due
     * modi e scaricato due volte.
     */
    public static function nomeFile(string $famiglia): string
    {
        $pulito = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $famiglia));

        return trim($pulito, '-') . '.woff2';
    }

    public static function percorso(string $famiglia): string
    {
        return self::CARTELLA . '/' . self::nomeFile($famiglia);
    }

    /** Il file di questa famiglia e' gia' sul nostro server? */
    public static function presente(string $famiglia): bool
    {
        return self::esiste($famiglia) && is_file(self::percorso($famiglia));
    }

    /** L'indirizzo con cui la pagina lo chiede a noi. */
    public static function url(string $famiglia): string
    {
        return '/assets/fonts/catalogo/' . self::nomeFile($famiglia);
    }

    /**
     * Scarica il carattere, se non c'e' gia'.
     *
     * Solleva un'eccezione con il motivo in chiaro quando non ci riesce: chi
     * ha premuto Salva deve sapere **perche'**, non vedere un "non riuscito".
     *
     * @throws RuntimeException
     */
    public static function scarica(string $famiglia): void
    {
        if (!self::esiste($famiglia)) {
            throw new RuntimeException('La famiglia "' . $famiglia . '" non è nel catalogo.');
        }

        if (self::presente($famiglia)) {
            return;
        }

        if (!is_dir(self::CARTELLA) && !mkdir(self::CARTELLA, 0775, true) && !is_dir(self::CARTELLA)) {
            throw new RuntimeException(
                'Non riesco a creare la cartella storage/fonts: controlla i permessi di scrittura.'
            );
        }

        $css = self::chiedi(self::indirizzoCss($famiglia), 'text/css');

        // Il CSS di Google elenca piu' blocchi @font-face, uno per
        // sottoinsieme di caratteri (latino, cirillico, greco...). Si prende
        // il **latino base**, che e' quello che serve qui: prenderli tutti
        // vorrebbe dire scaricare alfabeti che nessuna pagina mostrera' mai.
        $indirizzo = self::primoWoff2($css);

        if ($indirizzo === null) {
            throw new RuntimeException(
                'Google ha risposto, ma nella risposta non c\'è un file woff2 per "' . $famiglia . '". '
                . 'Può succedere con famiglie molto particolari: carica il file a mano.'
            );
        }

        $font = self::chiedi($indirizzo, 'font/woff2');

        if (strlen($font) < 1000) {
            throw new RuntimeException('Il file scaricato è troppo piccolo per essere un carattere.');
        }

        if (file_put_contents(self::percorso($famiglia), $font) === false) {
            throw new RuntimeException('Non riesco a scrivere in storage/fonts: controlla i permessi.');
        }
    }

    /**
     * Accetta un file caricato a mano. E' il ripiego per i server che non
     * possono uscire su internet, e per questo **non deve dipendere da
     * niente di esterno**.
     *
     * @param array{tmp_name: string, size: int, error: int} $file
     * @throws RuntimeException
     */
    public static function accettaCaricato(string $famiglia, array $file): void
    {
        if (!self::esiste($famiglia)) {
            throw new RuntimeException('La famiglia "' . $famiglia . '" non è nel catalogo.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Il caricamento del file non è riuscito.');
        }

        $contenuto = (string) file_get_contents($file['tmp_name']);

        // La firma di un woff2: i primi quattro byte sono "wOF2". Si guarda
        // il contenuto e non l'estensione, perche' l'estensione la decide
        // chi carica.
        if (substr($contenuto, 0, 4) !== 'wOF2') {
            throw new RuntimeException(
                'Il file non è un woff2. Dalla pagina del carattere su fonts.google.com, '
                . 'scarica la famiglia e converti il .ttf in woff2, oppure usa il file woff2 '
                . 'indicato nel foglio di stile di Google.'
            );
        }

        if (!is_dir(self::CARTELLA) && !mkdir(self::CARTELLA, 0775, true) && !is_dir(self::CARTELLA)) {
            throw new RuntimeException('Non riesco a creare la cartella storage/fonts.');
        }

        if (file_put_contents(self::percorso($famiglia), $contenuto) === false) {
            throw new RuntimeException('Non riesco a scrivere in storage/fonts: controlla i permessi.');
        }
    }

    /**
     * L'indirizzo del foglio di stile di Google per questa famiglia.
     *
     * `display=swap` non serve a noi — il CSS lo riscriviamo — ma tenerlo
     * rende l'indirizzo identico a quello che si otterrebbe dal sito, il che
     * aiuta chi un giorno vorra' verificarlo a mano.
     */
    public static function indirizzoCss(string $famiglia): string
    {
        return 'https://fonts.googleapis.com/css2?family='
            . rawurlencode($famiglia) . ':wght@' . self::PESI
            . '&display=swap';
    }

    /** Il primo indirizzo woff2 che compare nel CSS, o null. */
    public static function primoWoff2(string $css): ?string
    {
        if (preg_match('#url\((https://fonts\.gstatic\.com/[^)]+\.woff2)\)#', $css, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Una richiesta HTTP, con un messaggio comprensibile quando non va.
     *
     * Lo `User-Agent` non e' un vezzo: senza, Google restituisce un CSS che
     * punta a file **ttf** invece che woff2, perche' crede di parlare con un
     * browser vecchio. Con questo, risponde con i woff2.
     *
     * @throws RuntimeException
     */
    private static function chiedi(string $url, string $atteso): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Manca l\'estensione cURL di PHP: il server non può scaricare il carattere.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::ATTESA,
            CURLOPT_CONNECTTIMEOUT => self::ATTESA,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);

        $corpo = curl_exec($ch);
        $stato = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errore = curl_error($ch);
        curl_close($ch);

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($corpo === false || $errore !== '') {
            throw new RuntimeException(self::messaggioErrore($host, $errore, 0, $atteso));
        }

        if ($stato !== 200) {
            throw new RuntimeException(self::messaggioErrore($host, '', $stato, $atteso));
        }

        return (string) $corpo;
    }

    /**
     * Il messaggio per chi non e' riuscito a scaricare un carattere.
     *
     * Ogni messaggio dice anche **che cosa fare**: lo legge chi amministra
     * Pistacchio nel pannello Aspetto, subito sopra il modulo per caricare il
     * file a mano, e un messaggio che dice solo «non riuscito» lo lascia
     * senza una strada. Tre casi, decisi con Elena il 06/10:
     *
     *   1. **Nessuna connessione** (`$erroreRete` non vuoto): l'hosting
     *      chiude le connessioni in uscita.
     *   2. **Google non risponde adesso** (429, o un errore 5xx): troppe
     *      richieste o un guasto loro. Passa da solo: si puo' riprovare.
     *   3. **Ogni altra risposta che non e' 200**, e in pratica 401, 403 e
     *      407: in mezzo c'e' un firewall o un proxy dell'hosting che
     *      risponde al posto di Google. E' quello che succede nel
     *      contenitore di sviluppo, dove fino al 06/10 il messaggio diceva
     *      solo «ha risposto 403 invece di 200», e nessun test lo vedeva
     *      perche' i contenitori precedenti rifiutavano la connessione.
     *
     * Funzione a se', senza rete, perche' si provi su tutti i casi senza
     * dipendere dalla rete di chi esegue il test (`caratteri_test.php`).
     */
    public static function messaggioErrore(string $host, string $erroreRete, int $stato, string $atteso): string
    {
        $aMano = 'carica il file del carattere a mano, qui sotto.';

        if ($erroreRete !== '') {
            return 'Il server non è riuscito a raggiungere ' . $host . ': ' . $erroreRete . '. '
                . 'Su molti hosting condivisi le connessioni in uscita sono chiuse: in quel caso ' . $aMano;
        }

        if ($stato === 429 || $stato >= 500) {
            return $host . ' in questo momento non risponde (codice ' . $stato . ', mentre chiedevo '
                . $atteso . '): di solito passa da solo. Riprova fra qualche minuto, oppure ' . $aMano;
        }

        return 'La richiesta a ' . $host . ' è stata rifiutata (codice ' . $stato . ', mentre chiedevo '
            . $atteso . '). Di solito vuol dire che l\'hosting filtra le connessioni in uscita e '
            . 'risponde al posto di Google: chiedi al fornitore di aprirle, oppure ' . $aMano;
    }
}
