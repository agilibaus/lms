<?php

declare(strict_types=1);

namespace App\Core;

use PDOException;

/**
 * File caricati che nessuna lezione usa piu'.
 *
 * Nascono perche' togliere un video dalla lezione, cambiare provider o
 * eliminare una lezione lasciano di proposito i file sul server: la
 * cancellazione e' un gesto separato e volontario. Il rovescio e' che quei
 * file diventano invisibili, perche' nessuna pagina li nomina — queste
 * funzioni servono a tenerli contati.
 *
 * Il confronto e' fra quello che c'e' sul disco e quello a cui il database
 * fa riferimento. Le immagini della lezione non hanno una tabella: sono
 * citate dentro `content_html`, quindi si cerca il nome del file nel testo.
 */
class OrphanFiles
{
    /** Cartelle sotto /storage organizzate per identificativo di lezione. */
    public const LESSON_FOLDERS = ['videos', 'materials', 'lesson-images'];

    /**
     * File scollegati di una singola lezione.
     *
     * @param array|null $lesson la riga della lezione, se gia' letta
     * @param bool $all true per contare tutti i file della lezione, non solo
     *                  gli scollegati: serve prima di eliminarla, quando sta
     *                  per diventare scollegato tutto quanto.
     * @return array{count:int, bytes:int, folders:string[]}
     */
    public static function forLesson(int $lessonId, ?array $lesson = null, bool $all = false): array
    {
        $referenced = $all ? [] : self::referencedForLesson($lessonId, $lesson);

        if ($referenced === null) {
            return ['count' => 0, 'bytes' => 0, 'folders' => []];
        }

        $count = 0;
        $bytes = 0;
        $folders = [];

        foreach (self::LESSON_FOLDERS as $folder) {
            $directory = Upload::absolutePath($folder . '/' . $lessonId);

            if (!is_dir($directory)) {
                continue;
            }

            $trovati = 0;

            foreach (glob($directory . '/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }

                if (isset($referenced[$folder . '/' . $lessonId . '/' . basename($file)])) {
                    continue;
                }

                $trovati++;
                $bytes += (int) filesize($file);
            }

            if ($trovati > 0) {
                $count += $trovati;
                $folders[] = 'storage/' . $folder . '/' . $lessonId;
            }
        }

        return ['count' => $count, 'bytes' => $bytes, 'folders' => $folders];
    }

    /**
     * Riepilogo su tutta la piattaforma, per il pannello.
     *
     * @return array{total:array{count:int, bytes:int}, folders:array<string, array{count:int, bytes:int}>}
     */
    public static function summary(): array
    {
        $referenced = self::referencedAll();

        if ($referenced === null) {
            return ['total' => ['count' => 0, 'bytes' => 0], 'folders' => []];
        }

        $folders = [];
        $totalCount = 0;
        $totalBytes = 0;

        foreach (self::LESSON_FOLDERS as $folder) {
            $root = Upload::absolutePath($folder);
            $count = 0;
            $bytes = 0;

            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $lessonId = basename($directory);

                foreach (glob($directory . '/*') ?: [] as $file) {
                    if (!is_file($file)) {
                        continue;
                    }

                    if (isset($referenced[$folder . '/' . $lessonId . '/' . basename($file)])) {
                        continue;
                    }

                    $count++;
                    $bytes += (int) filesize($file);
                }
            }

            $folders[$folder] = ['count' => $count, 'bytes' => $bytes];
            $totalCount += $count;
            $totalBytes += $bytes;
        }

        return [
            'total' => ['count' => $totalCount, 'bytes' => $totalBytes],
            'folders' => $folders,
        ];
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 1, ',', '.') . ' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' kB';
        }

        return $bytes . ' byte';
    }

    // ---------------------------------------------------------------

    /**
     * Percorsi usati da una lezione, come chiavi di un array.
     *
     * @return array<string, true>|null
     */
    private static function referencedForLesson(int $lessonId, ?array $lesson = null): ?array
    {
        $referenced = [];

        try {
            $pdo = Database::connection();

            if ($lesson === null) {
                $stmt = $pdo->prepare('SELECT video_provider, video_ref, content_html FROM lessons WHERE id = :id');
                $stmt->execute(['id' => $lessonId]);
                $lesson = $stmt->fetch() ?: null;
            }

            if ($lesson !== null && ($lesson['video_provider'] ?? '') === 'self_hosted' && !empty($lesson['video_ref'])) {
                $referenced[(string) $lesson['video_ref']] = true;
            }

            $stmt = $pdo->prepare('SELECT file_path FROM lesson_materials WHERE lesson_id = :id');
            $stmt->execute(['id' => $lessonId]);

            foreach ($stmt->fetchAll() as $row) {
                $referenced[(string) $row['file_path']] = true;
            }

            self::addImagesFromHtml($referenced, $lessonId, (string) ($lesson['content_html'] ?? ''));
        } catch (PDOException $e) {
            // Senza database non si puo' sapere cosa sia collegato: meglio non
            // dichiarare niente, invece di contare per scollegato tutto.
            return null;
        }

        return $referenced;
    }

    /**
     * @return array<string, true>|null
     */
    private static function referencedAll(): ?array
    {
        $referenced = [];

        try {
            $pdo = Database::connection();

            $rows = $pdo->query(
                "SELECT id, video_provider, video_ref, content_html FROM lessons"
            )->fetchAll();

            foreach ($rows as $row) {
                if (($row['video_provider'] ?? '') === 'self_hosted' && !empty($row['video_ref'])) {
                    $referenced[(string) $row['video_ref']] = true;
                }

                self::addImagesFromHtml($referenced, (int) $row['id'], (string) ($row['content_html'] ?? ''));
            }

            foreach ($pdo->query('SELECT file_path FROM lesson_materials')->fetchAll() as $row) {
                $referenced[(string) $row['file_path']] = true;
            }
        } catch (PDOException $e) {
            return null;
        }

        return $referenced;
    }

    /**
     * Le immagini non stanno in tabella: compaiono nel testo della lezione
     * come /lessons/{id}/images/{file}. Si cercano li'.
     *
     * @param array<string, true> $referenced
     */
    private static function addImagesFromHtml(array &$referenced, int $lessonId, string $html): void
    {
        if ($html === '') {
            return;
        }

        $pattern = '#/lessons/' . $lessonId . '/images/([A-Za-z0-9._-]+)#';

        if (preg_match_all($pattern, $html, $matches) === false) {
            return;
        }

        foreach ($matches[1] as $name) {
            $referenced['lesson-images/' . $lessonId . '/' . $name] = true;
        }
    }
}
