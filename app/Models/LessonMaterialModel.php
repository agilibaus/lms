<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `lesson_materials` (PDF, audio e altri file scaricabili
 * collegati a una lezione). `file_path` è relativo a /storage — mai un URL pubblico.
 */
class LessonMaterialModel
{
    public static function forLesson(int $lessonId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, lesson_id, file_name, file_path, file_type, file_size_bytes, created_at
             FROM lesson_materials WHERE lesson_id = :lesson_id ORDER BY created_at'
        );
        $stmt->execute(['lesson_id' => $lessonId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM lesson_materials WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $material = $stmt->fetch();

        return $material ?: null;
    }

    public static function create(
        int $lessonId,
        string $fileName,
        string $filePath,
        string $fileType,
        int $fileSizeBytes
    ): int {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO lesson_materials (lesson_id, file_name, file_path, file_type, file_size_bytes)
             VALUES (:lesson_id, :file_name, :file_path, :file_type, :file_size_bytes)'
        );
        $stmt->execute([
            'lesson_id' => $lessonId,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'file_size_bytes' => $fileSizeBytes,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM lesson_materials WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
