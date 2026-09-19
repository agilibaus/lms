<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Prepara la copertina del corso: ritaglio 16:9, due misure, salvataggio
 * in /storage/course-covers.
 *
 * Le due misure servono perche' la stessa immagine compare nella testata del
 * corso (grande, una per pagina) e nelle card di elenco e catalogo (piccola,
 * molte per pagina): far scaricare il file da 1280px per una miniatura da
 * 300px sarebbe sprecato su una connessione lenta.
 *
 * Il rapporto 16:9 non e' una scelta arbitraria: e' quello gia' fissato da
 * `.course-card-cover` in style.css. Ritagliare qui invece che con object-fit
 * evita di trasferire pixel che il browser poi butta via.
 */
class CourseCover
{
    /** Misura per la testata della pagina corso. */
    private const WIDE_WIDTH = 1280;

    /** Misura per le card di elenco e catalogo. */
    private const CARD_WIDTH = 640;

    /** Denominatore del rapporto: larghezza * 9 / 16. */
    private const RATIO_W = 16;
    private const RATIO_H = 9;

    /** Limite sul file caricato. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Lunghezza massima del testo alternativo (colonna VARCHAR(255)). */
    public const MAX_ALT_LENGTH = 255;

    /**
     * Salva la copertina caricata e restituisce il percorso della misura grande.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file entry di $_FILES
     * @return string percorso relativo a /storage
     * @throws \RuntimeException se il file non e' un'immagine utilizzabile
     */
    public static function store(array $file, int $courseId): string
    {
        $error = $file['error'];

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Caricamento dell\'immagine non riuscito.');
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('L\'immagine supera gli 8 MB.');
        }

        // Senza GD non si ridimensiona: si salva l'originale come unica misura,
        // come fa AvatarImage. La card usera' lo stesso file (vedi cardPath()).
        if (!extension_loaded('gd')) {
            $stored = Upload::store($file, 'course-covers/' . $courseId, self::EXTENSIONS, self::MAX_BYTES);

            return $stored['stored_path'];
        }

        return self::render($file['tmp_name'], $courseId);
    }

    /**
     * Ritaglia, riduce e scrive le due misure. Separato da store() perche'
     * is_uploaded_file() rende quest'ultima non collaudabile da riga di comando.
     *
     * @return string percorso relativo a /storage della misura grande
     * @throws \RuntimeException
     */
    public static function render(string $sourcePath, int $courseId): string
    {
        $info = @getimagesize($sourcePath);

        if ($info === false) {
            throw new \RuntimeException('Il file non è un\'immagine valida.');
        }

        $source = self::open($sourcePath, (int) $info[2]);

        if ($source === null) {
            throw new \RuntimeException('Formato immagine non supportato: usa JPG, PNG, GIF o WebP.');
        }

        $directory = Upload::absolutePath('course-covers/' . $courseId);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($source);

            throw new \RuntimeException('Impossibile creare la cartella delle copertine sul server.');
        }

        $stem = bin2hex(random_bytes(16));
        $wide = 'course-covers/' . $courseId . '/' . $stem . '.jpg';
        $card = self::cardPath($wide);

        try {
            self::write($source, $wide, self::WIDE_WIDTH);
            self::write($source, $card, self::CARD_WIDTH);
        } catch (\RuntimeException $e) {
            imagedestroy($source);
            // Se la seconda scrittura fallisce la prima resterebbe orfana.
            self::delete($wide);

            throw $e;
        }

        imagedestroy($source);

        return $wide;
    }

    /**
     * Percorso della misura piccola a partire da quella grande.
     *
     * Se il file non esiste (copertina salvata senza GD) si ripiega sulla
     * grande: meglio un'immagine pesante che un riquadro rotto.
     */
    public static function cardPath(string $widePath): string
    {
        $extension = pathinfo($widePath, PATHINFO_EXTENSION);
        $withoutExtension = $extension === ''
            ? $widePath
            : substr($widePath, 0, -(strlen($extension) + 1));

        return $withoutExtension . '-card.' . ($extension === '' ? 'jpg' : $extension);
    }

    /**
     * Percorso effettivamente servibile per la misura richiesta.
     */
    public static function pathFor(string $widePath, bool $small): ?string
    {
        if ($small) {
            $card = self::cardPath($widePath);

            if (is_file(Upload::absolutePath($card))) {
                return $card;
            }
        }

        return is_file(Upload::absolutePath($widePath)) ? $widePath : null;
    }

    /**
     * Elimina entrambe le misure. Silenziosa: una copertina gia' sparita dal
     * disco non e' un errore da mostrare a chi sta solo salvando il corso.
     */
    public static function delete(?string $widePath): void
    {
        if ($widePath === null || $widePath === '' || self::isExternalUrl($widePath)) {
            return;
        }

        foreach ([$widePath, self::cardPath($widePath)] as $path) {
            $absolute = Upload::absolutePath($path);

            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    /**
     * La colonna `cover_image` esisteva gia' nello schema e conteneva un URL
     * da stampare tal quale in <img src>. Nessuna installazione l'ha mai
     * valorizzata dall'interfaccia, ma se qualcuno l'avesse fatto a mano il
     * valore continua a funzionare invece di diventare un riquadro vuoto.
     */
    public static function isExternalUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * Indirizzo da mettere in <img src> per un corso.
     *
     * @param array $course riga di `courses`
     */
    public static function url(array $course, bool $small = true): ?string
    {
        $stored = (string) ($course['cover_image'] ?? '');

        if ($stored === '') {
            return null;
        }

        if (self::isExternalUrl($stored)) {
            return $stored;
        }

        return '/corsi/' . (int) $course['id'] . '/copertina' . ($small ? '/piccola' : '');
    }

    /**
     * Testo alternativo da usare nella vista: quello scritto da chi gestisce il
     * corso, altrimenti il titolo. Mai vuoto, perche' un'immagine senza
     * alternativa testuale sparisce per chi usa uno screen reader.
     */
    public static function altFor(array $course): string
    {
        $alt = trim((string) ($course['cover_alt'] ?? ''));

        return $alt !== '' ? $alt : (string) ($course['title'] ?? '');
    }

    /**
     * Normalizza il testo alternativo arrivato dal form.
     */
    public static function normalizeAlt(?string $alt): ?string
    {
        $alt = trim((string) $alt);

        if ($alt === '') {
            return null;
        }

        // Le a capo in un attributo alt non servono a niente.
        $alt = preg_replace('/\s+/u', ' ', $alt) ?? $alt;

        return mb_substr($alt, 0, self::MAX_ALT_LENGTH);
    }

    /**
     * Iniziali per il riquadro mostrato quando la copertina manca: meglio di un
     * rettangolo vuoto in un elenco dove qualche corso ha l'immagine e qualcuno no.
     */
    public static function initials(string $title): string
    {
        // Si spezza solo sugli spazi: "Auto-aiuto" e' una parola sola, e la sua
        // iniziale e' una.
        $words = preg_split('/\s+/u', trim($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Fuori i numeri e i simboli: "2026 Meditazione Guidata" -> "MG".
        $words = array_values(array_filter(
            $words,
            static fn (string $word): bool => preg_match('/^\p{L}/u', $word) === 1
        ));

        // Articoli e preposizioni ("il", "di", "e") non dicono niente del corso,
        // ma se il titolo e' fatto solo di quelle si usano comunque: meglio "IE"
        // di un punto interrogativo.
        $meaningful = array_values(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) > 2
        ));

        $initials = '';

        foreach ($meaningful !== [] ? $meaningful : $words as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));

            if (mb_strlen($initials) === 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : '?';
    }

    /**
     * Tinta stabile derivata dall'identificativo, cosi' due corsi vicini
     * nell'elenco non finiscono quasi sempre dello stesso colore.
     */
    public static function hue(int $courseId): int
    {
        return ($courseId * 137) % 360;
    }

    /**
     * Tipo MIME da restituire quando l'immagine viene servita.
     */
    public static function mimeFor(string $relativePath): string
    {
        return match (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    // ---------------------------------------------------------------

    private static function open(string $path, int $type): ?\GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }

    /**
     * Ritaglia la fascia centrale 16:9 e la scrive alla larghezza richiesta.
     *
     * @throws \RuntimeException
     */
    private static function write(\GdImage $source, string $relativePath, int $targetWidth): void
    {
        $width = imagesx($source);
        $height = imagesy($source);

        // Fascia 16:9 piu' grande che ci sta nell'originale, centrata.
        $cropWidth = $width;
        $cropHeight = (int) round($width * self::RATIO_H / self::RATIO_W);

        if ($cropHeight > $height) {
            $cropHeight = $height;
            $cropWidth = (int) round($height * self::RATIO_W / self::RATIO_H);
        }

        $x = (int) (($width - $cropWidth) / 2);
        $y = (int) (($height - $cropHeight) / 2);

        // Un'immagine gia' piccola non viene ingrandita: si sgranerebbe.
        $finalWidth = min($targetWidth, $cropWidth);
        $finalHeight = (int) round($finalWidth * self::RATIO_H / self::RATIO_W);

        $canvas = imagecreatetruecolor($finalWidth, $finalHeight);

        // Il JPEG non ha trasparenza: il fondo bianco evita che le zone
        // trasparenti di un PNG diventino nere.
        imagefilledrectangle($canvas, 0, 0, $finalWidth, $finalHeight, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, $x, $y, $finalWidth, $finalHeight, $cropWidth, $cropHeight);

        $saved = imagejpeg($canvas, Upload::absolutePath($relativePath), 85);
        imagedestroy($canvas);

        if (!$saved) {
            throw new \RuntimeException('Impossibile salvare l\'immagine sul server.');
        }
    }
}
