<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Logo del gruppo: ritaglio quadrato, riduzione, salvataggio in
 * /storage/group-logos.
 *
 * Differenza voluta rispetto alla copertina del corso, che viene convertita
 * in JPEG: qui si salva in PNG. Un logo ha spesso lo sfondo trasparente, e
 * il JPEG lo appiattirebbe su un rettangolo bianco che si vedrebbe come una
 * toppa sopra lo sfondo caldo delle pagine.
 *
 * Quadrato e non 16:9 perche' un simbolo sta accanto a un nome, in elenco:
 * la forma e' quella dell'immagine del profilo, non quella di una copertina.
 */
class GroupLogo
{
    /** Lato dell'immagine salvata. Mostrata a 40-64 px, resta nitida al doppio. */
    private const SIZE = 256;

    public const MAX_BYTES = 4 * 1024 * 1024;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Salva il logo caricato e restituisce il percorso relativo a /storage.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @throws \RuntimeException
     */
    public static function store(array $file, int $groupId): string
    {
        $error = $file['error'];

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(Upload::errorMessage((int) $error));
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('L\'immagine supera i 4 MB.');
        }

        if (!extension_loaded('gd')) {
            $stored = Upload::store($file, 'group-logos/' . $groupId, self::EXTENSIONS, self::MAX_BYTES);

            return $stored['stored_path'];
        }

        return self::render($file['tmp_name'], $groupId);
    }

    /**
     * Ritaglia il quadrato centrale e scrive il PNG. Separato da store()
     * perche' is_uploaded_file() rende quest'ultima non collaudabile da riga
     * di comando.
     *
     * @throws \RuntimeException
     */
    public static function render(string $sourcePath, int $groupId): string
    {
        $info = @getimagesize($sourcePath);

        if ($info === false) {
            throw new \RuntimeException('Il file non è un\'immagine valida.');
        }

        $source = self::open($sourcePath, (int) $info[2]);

        if ($source === null) {
            throw new \RuntimeException('Formato immagine non supportato: usa PNG, JPG, GIF o WebP.');
        }

        $directory = Upload::absolutePath('group-logos/' . $groupId);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($source);

            throw new \RuntimeException('Impossibile creare la cartella dei loghi sul server.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $x = (int) (($width - $side) / 2);
        $y = (int) (($height - $side) / 2);

        // Non si ingrandisce un'immagine piccola: si sgranerebbe.
        $target = min(self::SIZE, $side);

        $canvas = imagecreatetruecolor($target, $target);

        // Trasparenza conservata: e' il motivo per cui qui si salva in PNG.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $source, 0, 0, $x, $y, $target, $target, $side, $side);
        imagedestroy($source);

        $relative = 'group-logos/' . $groupId . '/' . bin2hex(random_bytes(16)) . '.png';
        $saved = imagepng($canvas, Upload::absolutePath($relative), 8);
        imagedestroy($canvas);

        if (!$saved) {
            throw new \RuntimeException('Impossibile salvare l\'immagine sul server.');
        }

        return $relative;
    }

    /**
     * Elimina il file. Silenziosa: un logo gia' sparito dal disco non e' un
     * errore da mostrare a chi sta solo rinominando un gruppo.
     */
    public static function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $absolute = Upload::absolutePath($path);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * Indirizzo da mettere in <img src>, o null se il gruppo non ha logo.
     */
    public static function url(array $group): ?string
    {
        $stored = (string) ($group['logo_path'] ?? '');

        // Indirizzo neutro e non sotto /admin: questa immagine la carica anche
        // lo studente, nella pagina dei suoi gruppi.
        return $stored === '' ? null : '/gruppi/' . (int) $group['id'] . '/immagine';
    }

    /**
     * Iniziali per il riquadro mostrato quando il logo manca, come per le
     * copertine dei corsi.
     */
    public static function initials(string $name): string
    {
        return CourseCover::initials($name);
    }

    public static function hue(int $groupId): int
    {
        return ($groupId * 83) % 360;
    }

    public static function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
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
}
