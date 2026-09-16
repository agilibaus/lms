<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Descrive un file agli occhi di chi lo legge: famiglia (per l'icona),
 * etichetta leggibile e dimensione in unità comprensibili.
 *
 * Sta qui e non nella view perché serve sia all'elenco dei materiali della
 * lezione sia alla pagina di modifica.
 */
class FileType
{
    /** Estensione => [famiglia, etichetta]. */
    private const TYPES = [
        'pdf' => ['pdf', 'PDF'],
        'doc' => ['doc', 'Documento Word'],
        'docx' => ['doc', 'Documento Word'],
        'odt' => ['doc', 'Documento di testo'],
        'txt' => ['doc', 'Testo'],
        'rtf' => ['doc', 'Testo formattato'],
        'ppt' => ['slides', 'Presentazione'],
        'pptx' => ['slides', 'Presentazione'],
        'odp' => ['slides', 'Presentazione'],
        'xls' => ['sheet', 'Foglio di calcolo'],
        'xlsx' => ['sheet', 'Foglio di calcolo'],
        'ods' => ['sheet', 'Foglio di calcolo'],
        'csv' => ['sheet', 'Tabella CSV'],
        'mp3' => ['audio', 'Audio'],
        'wav' => ['audio', 'Audio'],
        'm4a' => ['audio', 'Audio'],
        'ogg' => ['audio', 'Audio'],
        'mp4' => ['video', 'Video'],
        'webm' => ['video', 'Video'],
        'mov' => ['video', 'Video'],
        'm4v' => ['video', 'Video'],
        'jpg' => ['image', 'Immagine'],
        'jpeg' => ['image', 'Immagine'],
        'png' => ['image', 'Immagine'],
        'gif' => ['image', 'Immagine'],
        'webp' => ['image', 'Immagine'],
        'svg' => ['image', 'Immagine'],
        'zip' => ['archive', 'Archivio'],
        'rar' => ['archive', 'Archivio'],
        '7z' => ['archive', 'Archivio'],
    ];

    /**
     * Famiglia del file, usata come classe CSS: pdf, doc, slides, sheet,
     * audio, video, image, archive, generic.
     */
    public static function family(?string $extension): string
    {
        return self::TYPES[strtolower((string) $extension)][0] ?? 'generic';
    }

    /**
     * Descrizione per chi legge ("PDF", "Presentazione"...). Per le estensioni
     * sconosciute torna l'estensione stessa in maiuscolo.
     */
    public static function label(?string $extension): string
    {
        $extension = strtolower((string) $extension);

        return self::TYPES[$extension][1] ?? ($extension === '' ? 'File' : strtoupper($extension));
    }

    /**
     * Sigla breve mostrata dentro l'icona: al massimo quattro caratteri.
     */
    public static function badge(?string $extension): string
    {
        $extension = strtoupper((string) $extension);

        return $extension === '' ? 'FILE' : substr($extension, 0, 4);
    }

    /**
     * Dimensione leggibile: 812 byte, 47 KB, 3,2 MB.
     *
     * Le unità sono quelle che l'utente vede indicate dal sistema operativo
     * (base 1024) e il separatore decimale è la virgola, come in italiano.
     */
    public static function humanSize(?int $bytes): string
    {
        $bytes = max(0, (int) $bytes);

        if ($bytes < 1024) {
            return $bytes . ' byte';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        // Sotto i 10 si tiene un decimale: "1,4 MB" dice più di "1 MB".
        $decimals = $value < 10 ? 1 : 0;

        return number_format($value, $decimals, ',', '.') . ' ' . $units[$unit];
    }
}
