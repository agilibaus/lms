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
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Caricamento file non riuscito (errore #' . $error . ').');
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
