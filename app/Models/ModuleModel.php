<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `modules`.
 */
class ModuleModel
{
    public static function forCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, course_id, title, position
             FROM modules WHERE course_id = :course_id ORDER BY position, id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM modules WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $module = $stmt->fetch();

        return $module ?: null;
    }

    public static function create(int $courseId, string $title): int
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO modules (course_id, title, position) VALUES (:course_id, :title, :position)'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => $title,
            'position' => self::nextPosition($courseId),
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $title): void
    {
        $stmt = Database::connection()->prepare('UPDATE modules SET title = :title WHERE id = :id');
        $stmt->execute(['title' => $title, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Le lezioni del modulo vengono rimosse a cascata (FK ON DELETE CASCADE).
        $stmt = Database::connection()->prepare('DELETE FROM modules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private static function nextPosition(int $courseId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next_position FROM modules WHERE course_id = :course_id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return (int) $stmt->fetchColumn();
    }
}
