<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gestione upload file (materiali didattici, video self-hosted).
 * Valida estensione/dimensione e salva fuori dal document root,
 * in una sottocartella di /storage con nome file randomizzato
 * (il nome originale resta solo come metadato in DB).
 */
class Upload
{
    private const STORAGE_ROOT = __DIR__ . '/../../storage';

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file una singola entry di $_FILES
     * @param string $subDir sottocartella dentro /storage (es. "materials/12")
     * @param string[] $allowedExtensions estensioni consentite, minuscole, senza punto
     * @return array{stored_path: string, original_name: string, extension: string, size: int}
     * @throws \RuntimeException se il file non supera la validazione
     */
    public static function store(array $file, string $subDir, array $allowedExtensions, int $maxBytes): array
    {
        $error = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::errorMessage($error));
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('File non valido.');
        }

        $size = (int) $file['size'];

        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'Il file "%s" supera la dimensione massima consentita (%d MB).',
                $file['name'],
                (int) ($maxBytes / 1024 / 1024)
            ));
        }

        $originalName = $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new \RuntimeException(sprintf(
                'Formato file non consentito per "%s" (estensioni ammesse: %s).',
                $originalName,
                implode(', ', $allowedExtensions)
            ));
        }

        $destinationDir = self::STORAGE_ROOT . '/' . trim($subDir, '/');

        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Impossibile creare la cartella di destinazione sul server.');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destinationPath = $destinationDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
            throw new \RuntimeException('Impossibile salvare il file caricato sul server.');
        }

        return [
            'stored_path' => trim($subDir, '/') . '/' . $storedName,
            'original_name' => $originalName,
            'extension' => $extension,
            'size' => $size,
        ];
    }

    /**
     * Percorso assoluto sul filesystem a partire da un path relativo salvato in DB
     * (es. "materials/12/ab12....pdf").
     */
    /**
     * Messaggio leggibile per i codici di $_FILES.
     *
     * "errore #1" e' esatto e inservibile: chi carica un video non sa cosa
     * sia upload_max_filesize, e chi amministra il server non era in ascolto.
     */
    public static function errorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => 'Il file supera il limite del server, attualmente '
                . self::humanIniLimit() . '. Per alzarlo si modificano upload_max_filesize e '
                . 'post_max_size nel php.ini, oppure si usa un provider di streaming.',
            UPLOAD_ERR_FORM_SIZE => 'Il file supera il limite indicato dal modulo.',
            UPLOAD_ERR_PARTIAL => 'Il caricamento si è interrotto a metà: riprova.',
            UPLOAD_ERR_NO_FILE => 'Nessun file selezionato.',
            UPLOAD_ERR_NO_TMP_DIR => 'Manca la cartella temporanea sul server.',
            UPLOAD_ERR_CANT_WRITE => 'Il server non è riuscito a scrivere il file sul disco.',
            UPLOAD_ERR_EXTENSION => 'Un\'estensione di PHP ha interrotto il caricamento.',
            default => 'Caricamento non riuscito (errore #' . $error . ').',
        };
    }

    /**
     * Limite effettivo per un singolo file: il piu' basso fra
     * upload_max_filesize e post_max_size, perche' basta superarne uno.
     */
    public static function iniLimitBytes(): int
    {
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));

        // post_max_size a 0 significa "nessun limite".
        if ($post === 0) {
            return $upload;
        }

        return min($upload, $post);
    }

    public static function humanIniLimit(): string
    {
        $bytes = self::iniLimitBytes();

        return $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024)) . ' MB'
            : round($bytes / 1024) . ' kB';
    }

    /**
     * Converte i valori del php.ini ("8M", "512K", "1G") in byte.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function absolutePath(string $relativePath): string
    {
        return self::STORAGE_ROOT . '/' . ltrim($relativePath, '/');
    }

    /**
     * Normalizza $_FILES['campo'] quando il campo è un array di file multipli
     * (name/type/tmp_name/error/size sono a loro volta array, come per
     * <input type="file" name="materials[]" multiple>) in una lista di
     * singole entry con la stessa forma di una entry $_FILES normale.
     *
     * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function normalizeMultiple(?array $filesField): array
    {
        if ($filesField === null || !isset($filesField['name'])) {
            return [];
        }

        if (!is_array($filesField['name'])) {
            // Campo singolo (non multiplo): normalizziamo comunque in lista di 1.
            return $filesField['error'] === UPLOAD_ERR_NO_FILE ? [] : [$filesField];
        }

        $items = [];

        foreach ($filesField['name'] as $i => $name) {
            if ($name === '' && $filesField['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $items[] = [
                'name' => $filesField['name'][$i],
                'type' => $filesField['type'][$i],
                'tmp_name' => $filesField['tmp_name'][$i],
                'error' => $filesField['error'][$i],
                'size' => $filesField['size'][$i],
            ];
        }

        return $items;
    }
}
