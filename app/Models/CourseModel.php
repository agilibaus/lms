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
            'SELECT id, title, slug, description, cover_image, cover_alt, is_published, enrollment_mode, position
             FROM courses ORDER BY position, id'
        )->fetchAll();
    }

    /**
     * Corsi a cui uno studente è iscritto, con percentuale di progresso.
     */
    public static function enrolledForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.title, c.slug, c.description, c.cover_image, c.cover_alt, e.progress_pct
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
        int $createdBy,
        string $enrollmentMode = 'closed'
    ): int {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO courses (title, slug, description, is_published, enrollment_mode, created_by)
             VALUES (:title, :slug, :description, :is_published, :enrollment_mode, :created_by)'
        );
        $stmt->execute([
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'is_published' => $isPublished ? 1 : 0,
            'enrollment_mode' => $enrollmentMode,
            'created_by' => $createdBy,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(
        int $id,
        string $title,
        string $slug,
        ?string $description,
        bool $isPublished,
        string $enrollmentMode = 'closed'
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE courses
             SET title = :title, slug = :slug, description = :description,
                 is_published = :is_published, enrollment_mode = :enrollment_mode
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'is_published' => $isPublished ? 1 : 0,
            'enrollment_mode' => $enrollmentMode,
            'id' => $id,
        ]);
    }

    /**
     * Copertina e testo alternativo. Separati da update() perche' il form dei
     * dati del corso non porta l'immagine: se passassero di li', salvare il
     * titolo cancellerebbe la copertina.
     */
    public static function updateCover(int $id, ?string $coverImage, ?string $coverAlt): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE courses SET cover_image = :cover_image, cover_alt = :cover_alt WHERE id = :id'
        );
        $stmt->execute([
            'cover_image' => $coverImage,
            'cover_alt' => $coverAlt,
            'id' => $id,
        ]);
    }

    /**
     * Catalogo: corsi pubblicati ad iscrizione aperta o su richiesta, esclusi
     * quelli a cui l'utente e' gia' iscritto, con lo stato dell'eventuale
     * richiesta gia' inviata.
     */
    public static function catalogForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.id, c.title, c.description, c.cover_image, c.cover_alt, c.enrollment_mode,
                    r.status AS request_status,
                    (SELECT COUNT(*) FROM lessons l
                       INNER JOIN modules m ON m.id = l.module_id
                      WHERE m.course_id = c.id) AS lesson_count
             FROM courses c
             LEFT JOIN enrollment_requests r ON r.course_id = c.id AND r.user_id = :user_id_request
             WHERE c.is_published = 1
               AND c.enrollment_mode IN ('open','request')
               AND NOT EXISTS (
                   SELECT 1 FROM enrollments e
                   WHERE e.course_id = c.id AND e.user_id = :user_id_enrolled
               )
             ORDER BY c.position, c.id"
        );
        $stmt->execute(['user_id_request' => $userId, 'user_id_enrolled' => $userId]);

        return $stmt->fetchAll();
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
    /**
     * Sposta il corso di un posto nell'ordine, scambiando la posizione con il
     * vicino. Stesso meccanismo di moduli, lezioni e materiali.
     */
    public static function move(int $id, string $direction): void
    {
        $course = self::find($id);

        if ($course === null) {
            return;
        }

        $db = Database::connection();
        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $stmt = $db->prepare(
            'SELECT id, position FROM courses
             WHERE (position, id) ' . $comparison . ' (:position, :id)
             ORDER BY position ' . $order . ', id ' . $order . ' LIMIT 1'
        );
        $stmt->execute(['position' => (int) $course['position'], 'id' => $id]);
        $neighbour = $stmt->fetch();

        if (!$neighbour) {
            return; // gia' in cima o in fondo
        }

        if ((int) $neighbour['position'] === (int) $course['position']) {
            self::renumber();
            $course = self::find($id);
            $neighbour = self::find((int) $neighbour['id']);
        }

        $update = $db->prepare('UPDATE courses SET position = :position WHERE id = :id');
        $update->execute(['position' => (int) $neighbour['position'], 'id' => $id]);
        $update->execute(['position' => (int) $course['position'], 'id' => (int) $neighbour['id']]);
    }

    /**
     * Ordine completo, come arriva dal trascinamento.
     *
     * Gli id sconosciuti vengono ignorati e i corsi non nominati restano in
     * coda nell'ordine di prima: la pagina di chi trascina potrebbe essere
     * vecchia di qualche minuto, e un corso creato nel frattempo non deve
     * sparire in fondo per caso ne' bloccare il salvataggio.
     *
     * @param int[] $ids
     */
    public static function reorder(array $ids): void
    {
        $db = Database::connection();
        $existing = [];

        foreach ($db->query('SELECT id FROM courses ORDER BY position, id')->fetchAll() as $row) {
            $existing[(int) $row['id']] = true;
        }

        $ordered = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if (isset($existing[$id]) && !in_array($id, $ordered, true)) {
                $ordered[] = $id;
                unset($existing[$id]);
            }
        }

        foreach (array_keys($existing) as $id) {
            $ordered[] = $id;
        }

        $update = $db->prepare('UPDATE courses SET position = :position WHERE id = :id');
        $position = 0;

        foreach ($ordered as $id) {
            $update->execute(['position' => $position++, 'id' => $id]);
        }
    }

    /**
     * Riassegna posizioni consecutive a partire da 0, mantenendo l'ordine attuale.
     */
    private static function renumber(): void
    {
        $db = Database::connection();
        $update = $db->prepare('UPDATE courses SET position = :position WHERE id = :id');
        $position = 0;

        foreach ($db->query('SELECT id FROM courses ORDER BY position, id')->fetchAll() as $row) {
            $update->execute(['position' => $position++, 'id' => (int) $row['id']]);
        }
    }

}
