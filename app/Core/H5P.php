<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Questionari H5P (22/10, Alessandro): un contenuto interattivo creato con
 * Lumi (file `.h5p`) al posto delle domande. Lo mostra il riproduttore
 * h5p-standalone (`public/assets/h5p`, MIT) dentro un iframe; il punteggio
 * che il contenuto comunica alla pagina (eventi xAPI) diventa un tentativo
 * come quelli dei questionari a domande.
 *
 * Qui le regole senza database e senza file: quali file di un archivio si
 * accettano, come si legge `h5p.json`, come si controlla un punteggio. Il
 * lavoro sui file sta in `H5PArchivio`.
 *
 * SICUREZZA, LIVELLO BASE (decisione del 22/10). Un `.h5p` e' un archivio
 * zip con dentro anche codice JavaScript: lo caricano solo admin e tutor del
 * corso, si apre in una cartella scelta da Pistacchio, e ogni file passa
 * da un elenco di estensioni ammesse (quello di H5P, piu' js e css per le
 * librerie). I file si servono da PHP, a chi puo' aprire il questionario,
 * con il tipo deciso qui e non dal browser. Il codice gira in un iframe
 * sullo stesso dominio: il livello rafforzato (un sottodominio dedicato)
 * si potra' aggiungere cambiando solo l'indirizzo dei file.
 */
final class H5P
{
    /**
     * Le estensioni ammesse e il loro tipo. Quelle dei contenuti H5P
     * (immagini, audio, video, caratteri, documenti, dati), piu' js e css
     * delle librerie. Niente html, niente php: un file con un'estensione
     * fuori elenco non viene estratto.
     */
    public const TIPI = [
        'json' => 'application/json',
        'js' => 'text/javascript',
        'css' => 'text/css',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'eot' => 'application/vnd.ms-fontobject',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'vtt' => 'text/vtt',
        'webvtt' => 'text/vtt',
        'txt' => 'text/plain',
        'md' => 'text/plain',
        'csv' => 'text/csv',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'gltf' => 'model/gltf+json',
        'glb' => 'model/gltf-binary',
    ];

    /**
     * Estensioni che in un archivio H5P non hanno ragione di esserci, e che
     * sul server o nel browser potrebbero essere eseguite: l'archivio si
     * rifiuta intero. Gli altri file fuori elenco si saltano e basta (un
     * `.DS_Store`, un `Thumbs.db`).
     */
    public const VIETATE = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
        'html', 'htm', 'xhtml', 'shtml', 'xht', 'htaccess', 'htpasswd', 'ini',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'dll', 'so', 'jar', 'swf',
    ];

    /** Un archivio con piu' file di cosi' non e' un esercizio: e' un errore o un abuso. */
    public const MASSIMO_FILE = 3000;

    /** Il peso massimo dei file estratti: 300 MB (un Interactive Video con il video dentro). */
    public const MASSIMO_BYTE = 300 * 1024 * 1024;

    /** Quanto puo' valere al massimo un esercizio, in punti: oltre e' un dato inventato. */
    public const MASSIMO_PUNTI = 10000;

    /**
     * I tipi di contenuto che non danno un punteggio (22/10): come
     * questionario non verrebbero mai superati, e con loro il modulo non
     * risulterebbe mai completato. Al caricamento si rifiutano con il loro
     * nome. Sono quelli che si guardano o si esplorano; i tipi che possono
     * avere domande dentro (Course Presentation, Interactive Video,
     * Interactive Book, Column) restano ammessi: se le domande ci sono,
     * il punteggio arriva.
     */
    public const SENZA_PUNTEGGIO = [
        'H5P.Accordion' => 'Accordion',
        'H5P.Timeline' => 'Timeline',
        'H5P.Dialogcards' => 'Dialog Cards',
        'H5P.ImageHotspots' => 'Image Hotspots',
        'H5P.Agamotto' => 'Agamotto',
        'H5P.ImageSlider' => 'Image Slider',
        'H5P.Collage' => 'Collage',
        'H5P.Chart' => 'Chart',
        'H5P.ImageJuxtaposition' => 'Before After Image',
        'H5P.GuessTheAnswer' => 'Guess the Answer',
        'H5P.Image' => 'Image',
        'H5P.AdvancedText' => 'Text',
        'H5P.Text' => 'Text',
        'H5P.Audio' => 'Audio',
        'H5P.Video' => 'Video',
        'H5P.Table' => 'Table',
        'H5P.Link' => 'Link',
        'H5P.IFrameEmbed' => 'Iframe Embedder',
        'H5P.TwitterUserFeed' => 'Twitter User Feed',
        'H5P.DocumentationTool' => 'Documentation Tool',
        'H5P.AudioRecorder' => 'Audio Recorder',
        'H5P.KewArCode' => 'KewAr Code',
        'H5P.InfoWall' => 'Information Wall',
        'H5P.ThreeImage' => 'Virtual Tour (360)',
    ];

    public const SALTA = 'salta';
    public const RIFIUTA = 'rifiuta';

    /**
     * Che cosa fare di una voce dell'archivio: il percorso pulito, SALTA o
     * RIFIUTA. Le cartelle (che finiscono con «/») si saltano: nascono da
     * sole con i file.
     *
     * Rifiutate: percorsi assoluti o con «..», barre rovesce, caratteri di
     * controllo, estensioni VIETATE. Saltate: cartelle, file nascosti (un
     * segmento che comincia con il punto, come `.htaccess` dentro una
     * libreria: e' anche fra le vietate, ma un nascosto qualunque basta
     * saltarlo), estensioni fuori elenco.
     */
    public static function voce(string $nome): string
    {
        if ($nome === '' || str_ends_with($nome, '/')) {
            return self::SALTA;
        }

        if (str_starts_with($nome, '/') || str_contains($nome, '\\') || preg_match('/[\x00-\x1F\x7F]/', $nome) === 1
            || preg_match('/^[A-Za-z]:/', $nome) === 1) {
            return self::RIFIUTA;
        }

        $segmenti = explode('/', $nome);

        foreach ($segmenti as $s) {
            if ($s === '' || $s === '.' || $s === '..') {
                return self::RIFIUTA;
            }
        }

        $ultimo = end($segmenti);
        $estensione = strtolower(pathinfo($ultimo, PATHINFO_EXTENSION));
        $nomeSenzaPunto = strtolower(ltrim($ultimo, '.'));

        if (in_array($estensione, self::VIETATE, true) || in_array($nomeSenzaPunto, self::VIETATE, true)) {
            return self::RIFIUTA;
        }

        foreach ($segmenti as $s) {
            if (str_starts_with($s, '.')) {
                return self::SALTA;
            }
        }

        if (!isset(self::TIPI[$estensione])) {
            return self::SALTA;
        }

        // Codice solo nelle librerie: nella cartella `content`, quella dei
        // dati dell'esercizio, H5P stesso non lo ammette.
        if ($segmenti[0] === 'content' && in_array($estensione, ['js', 'css'], true)) {
            return self::SALTA;
        }

        return implode('/', $segmenti);
    }

    /**
     * Il titolo e il tipo di contenuto da `h5p.json`; null se il file non e'
     * quello di un contenuto H5P.
     *
     * @return array{titolo: string, libreria: string, principale: string}|null
     */
    public static function descrizione(string $json): ?array
    {
        $dati = json_decode($json, true);

        if (!is_array($dati) || !is_string($dati['mainLibrary'] ?? null)
            || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $dati['mainLibrary']) !== 1) {
            return null;
        }

        $versione = '';

        foreach (is_array($dati['preloadedDependencies'] ?? null) ? $dati['preloadedDependencies'] : [] as $d) {
            if (is_array($d) && ($d['machineName'] ?? null) === $dati['mainLibrary']) {
                $versione = ' ' . (int) ($d['majorVersion'] ?? 0) . '.' . (int) ($d['minorVersion'] ?? 0);
            }
        }

        $titolo = is_string($dati['title'] ?? null) ? trim($dati['title']) : '';

        return [
            'titolo' => mb_substr($titolo, 0, 200),
            'libreria' => $dati['mainLibrary'] . $versione,
            'principale' => $dati['mainLibrary'],
        ];
    }

    /**
     * Il nome del tipo, se e' uno di quelli che non danno punteggio; null
     * altrimenti (anche per un tipo che non conosciamo: meglio provarlo che
     * rifiutare un esercizio con le domande).
     */
    public static function senzaPunteggio(string $principale): ?string
    {
        return self::SENZA_PUNTEGGIO[$principale] ?? null;
    }

    /**
     * Il punteggio che arriva dalla pagina, controllato: due numeri, il
     * massimo positivo e ragionevole, il punteggio fra zero e il massimo.
     * Null se uno solo di questi non torna: un tentativo non si salva con
     * un punteggio che non ha senso.
     *
     * @return array{raw: float, max: float, pct: float}|null
     */
    public static function punteggio(mixed $raw, mixed $max): ?array
    {
        if (!is_numeric($raw) || !is_numeric($max)) {
            return null;
        }

        $raw = (float) $raw;
        $max = (float) $max;

        if (!is_finite($raw) || !is_finite($max) || $max <= 0 || $max > self::MASSIMO_PUNTI || $raw < 0 || $raw > $max) {
            return null;
        }

        return ['raw' => round($raw, 2), 'max' => round($max, 2), 'pct' => round($raw / $max * 100, 2)];
    }

    /** Il tipo di un file da servire, dall'estensione; null se non e' fra le ammesse. */
    public static function tipo(string $percorso): ?string
    {
        return self::TIPI[strtolower(pathinfo($percorso, PATHINFO_EXTENSION))] ?? null;
    }

    /** «5 su 5», «3,5 su 10»: il punteggio come lo dice l'esercizio. */
    public static function punti(float $raw, float $max): string
    {
        $scrivi = static fn (float $n): string => $n == (int) $n ? (string) (int) $n : str_replace('.', ',', (string) round($n, 2));

        return $scrivi($raw) . ' su ' . $scrivi($max);
    }
}
