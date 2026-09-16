<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Prepara l'immagine del profilo: la ritaglia quadrata, la riduce e la salva
 * in /storage/avatars.
 *
 * Il ritaglio quadrato avviene qui e non via CSS perché l'immagine viene
 * mostrata piccola e tonda in ogni pagina: tenere sul server il file originale
 * da 4 MB della fotocamera significherebbe farlo scaricare ogni volta.
 */
class AvatarImage
{
    /** Lato dell'immagine salvata, in pixel. */
    private const SIZE = 512;

    /** Limite sul file caricato. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file entry di $_FILES
     * @return string percorso relativo a /storage
     * @throws \RuntimeException se il file non è un'immagine utilizzabile
     */
    public static function store(array $file, int $userId): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new \RuntimeException('Caricamento dell\'immagine non riuscito.');
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('L\'immagine supera gli 8 MB.');
        }

        $info = @getimagesize($file['tmp_name']);

        if ($info === false) {
            throw new \RuntimeException('Il file non è un\'immagine valida.');
        }

        // Senza GD non si ridimensiona: meglio salvare l'originale che rifiutare
        // il caricamento, ma solo entro i formati e le dimensioni previste.
        if (!extension_loaded('gd')) {
            $stored = Upload::store($file, 'avatars/' . $userId, self::EXTENSIONS, self::MAX_BYTES);

            return $stored['stored_path'];
        }

        $source = self::open($file['tmp_name'], (int) $info[2]);

        if ($source === null) {
            throw new \RuntimeException('Formato immagine non supportato: usa JPG, PNG, GIF o WebP.');
        }

        $square = self::cropSquare($source);
        imagedestroy($source);

        $directory = Upload::absolutePath('avatars/' . $userId);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($square);

            throw new \RuntimeException('Impossibile creare la cartella delle immagini sul server.');
        }

        $relative = 'avatars/' . $userId . '/' . bin2hex(random_bytes(16)) . '.jpg';
        $saved = imagejpeg($square, Upload::absolutePath($relative), 85);
        imagedestroy($square);

        if (!$saved) {
            throw new \RuntimeException('Impossibile salvare l\'immagine sul server.');
        }

        return $relative;
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
     * Ritaglia il quadrato centrale e lo riduce al lato previsto.
     */
    private static function cropSquare(\GdImage $source): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $x = (int) (($width - $side) / 2);
        $y = (int) (($height - $side) / 2);

        // Un'immagine già piccola non viene ingrandita: si sgranerebbe.
        $target = min(self::SIZE, $side);
        $square = imagecreatetruecolor($target, $target);

        // Il JPEG non ha trasparenza: il fondo bianco evita che le zone
        // trasparenti di un PNG diventino nere.
        imagefilledrectangle($square, 0, 0, $target, $target, imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $source, 0, 0, $x, $y, $target, $target, $side, $side);

        return $square;
    }
}
