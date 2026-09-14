<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `courses` (e relative iscrizioni).
 */
class CourseModel
{
    /**
     * Tutti i corsi, pubblicati e in bozza — per lo staff (admin/tutor/assistente).
     */
    public static function allForStaff(): array
    {
        return Database::connection()->query(
            'SELECT id, title, slug, description, cover_image, is_published
             FROM courses ORDER BY created_at DESC'
        )->fetchAll();
    }

    /**
     * Corsi a cui uno studente è iscritto, con percentuale di progresso.
     */
    public static function enrolledForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.title, c.slug, c.description, c.cover_image, e.progress_pct
             FROM courses c
             INNER JOIN enrollments e ON e.course_id = c.id
             WHERE e.user_id = :user_id
             ORDER BY e.enrolled_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM courses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $course = $stmt->fetch();

        return $course ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM courses WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $course = $stmt->fetch();

        return $course ?: null;
    }
}
