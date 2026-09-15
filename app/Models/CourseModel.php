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

    // -----------------------------------------------------------------
    // Scrittura (pannello di amministrazione)
    // -----------------------------------------------------------------

    public static function create(
        string $title,
        string $slug,
        ?string $description,
        bool $isPublished,
        int $createdBy
    ): int {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO courses (title, slug, description, is_published, created_by)
             VALUES (:title, :slug, :description, :is_published, :created_by)'
        );
        $stmt->execute([
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'is_published' => $isPublished ? 1 : 0,
            'created_by' => $createdBy,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $title, string $slug, ?string $description, bool $isPublished): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE courses
             SET title = :title, slug = :slug, description = :description, is_published = :is_published
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'is_published' => $isPublished ? 1 : 0,
            'id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        // Moduli, lezioni, iscrizioni e certificati seguono via FK ON DELETE CASCADE.
        $stmt = Database::connection()->prepare('DELETE FROM courses WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Genera uno slug univoco a partire dal titolo (o da uno slug proposto).
     */
    public static function uniqueSlug(string $source, ?int $exceptId = null): string
    {
        $base = strtolower(trim($source));
        $base = preg_replace('/[^a-z0-9]+/u', '-', $base) ?? '';
        $base = trim($base, '-') ?: 'corso';

        $slug = $base;
        $suffix = 2;

        while (self::slugTaken($slug, $exceptId)) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    private static function slugTaken(string $slug, ?int $exceptId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM courses WHERE slug = :slug AND (:except_id IS NULL OR id <> :except_id2) LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'except_id' => $exceptId, 'except_id2' => $exceptId ?? 0]);

        return (bool) $stmt->fetchColumn();
    }
}
