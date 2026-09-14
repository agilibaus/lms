<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `lessons`.
 */
class LessonModel
{
    public static function forModule(int $moduleId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, module_id, title, video_provider, video_ref, duration_seconds, position
             FROM lessons WHERE module_id = :module_id ORDER BY position, id'
        );
        $stmt->execute(['module_id' => $moduleId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM lessons WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $lesson = $stmt->fetch();

        return $lesson ?: null;
    }

    public static function create(
        int $moduleId,
        string $title,
        ?string $contentHtml,
        string $videoProvider,
        ?string $videoRef,
        int $durationSeconds
    ): int {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO lessons (module_id, title, content_html, video_provider, video_ref, duration_seconds, position)
             VALUES (:module_id, :title, :content_html, :video_provider, :video_ref, :duration_seconds, :position)'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'title' => $title,
            'content_html' => $contentHtml,
            'video_provider' => $videoProvider,
            'video_ref' => $videoRef,
            'duration_seconds' => $durationSeconds,
            'position' => self::nextPosition($moduleId),
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(
        int $id,
        string $title,
        ?string $contentHtml,
        string $videoProvider,
        ?string $videoRef,
        int $durationSeconds
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE lessons
             SET title = :title, content_html = :content_html, video_provider = :video_provider,
                 video_ref = :video_ref, duration_seconds = :duration_seconds
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'content_html' => $contentHtml,
            'video_provider' => $videoProvider,
            'video_ref' => $videoRef,
            'duration_seconds' => $durationSeconds,
            'id' => $id,
        ]);
    }

    /**
     * Aggiorna solo il riferimento video (usato dopo l'upload di un file self-hosted,
     * che avviene in un secondo momento rispetto alla creazione/modifica della lezione).
     */
    public static function updateVideoRef(int $id, string $videoRef): void
    {
        $stmt = Database::connection()->prepare('UPDATE lessons SET video_ref = :video_ref WHERE id = :id');
        $stmt->execute(['video_ref' => $videoRef, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Materiali collegati rimossi a cascata (FK ON DELETE CASCADE); i file fisici
        // restano orfani su disco e vanno ripuliti separatamente dal chiamante.
        $stmt = Database::connection()->prepare('DELETE FROM lessons WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Numero totale di lezioni in un corso (somma su tutti i moduli) — usato
     * per calcolare la percentuale di avanzamento di uno studente.
     */
    public static function countForCourse(int $courseId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM lessons l
             INNER JOIN modules m ON m.id = l.module_id
             WHERE m.course_id = :course_id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return (int) $stmt->fetchColumn();
    }

    private static function nextPosition(int $moduleId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next_position FROM lessons WHERE module_id = :module_id'
        );
        $stmt->execute(['module_id' => $moduleId]);

        return (int) $stmt->fetchColumn();
    }
}
