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
            'SELECT id, course_id, title, position, quiz_required
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

    public static function create(int $courseId, string $title, bool $quizRequired = false): int
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO modules (course_id, title, position, quiz_required)
             VALUES (:course_id, :title, :position, :quiz_required)'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => $title,
            'position' => self::nextPosition($courseId),
            'quiz_required' => $quizRequired ? 1 : 0,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $title, bool $quizRequired = false): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE modules SET title = :title, quiz_required = :quiz_required WHERE id = :id'
        );
        $stmt->execute(['title' => $title, 'quiz_required' => $quizRequired ? 1 : 0, 'id' => $id]);
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
    /**
     * Sposta di un posto su o giu' nell'ordine, scambiando la posizione con
     * il vicino. Stesso meccanismo dei materiali della lezione: uno solo in
     * tutta la piattaforma, cosi' si comporta sempre allo stesso modo.
     */
    public static function move(int $id, string $direction): void
    {
        $row = self::find($id);

        if ($row === null) {
            return;
        }

        $db = Database::connection();

        // Il vicino e' la riga adiacente nell'ordine di visualizzazione, non
        // quella con position +/- 1: dopo un'eliminazione le posizioni possono
        // avere dei buchi, e righe importate potrebbero condividere lo zero.
        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $stmt = $db->prepare(
            'SELECT id, position FROM modules
             WHERE course_id = :parent AND (position, id) ' . $comparison . ' (:position, :id)
             ORDER BY position ' . $order . ', id ' . $order . ' LIMIT 1'
        );
        $stmt->execute([
            'parent' => (int) $row['course_id'],
            'position' => (int) $row['position'],
            'id' => $id,
        ]);
        $neighbour = $stmt->fetch();

        if (!$neighbour) {
            return; // gia' in cima o in fondo
        }

        // Posizioni identiche (dati importati): rinumerare e' l'unico modo di
        // ottenere uno scambio visibile.
        if ((int) $neighbour['position'] === (int) $row['position']) {
            self::renumber((int) $row['course_id']);
            $row = self::find($id);
            $neighbour = self::find((int) $neighbour['id']);
        }

        $update = $db->prepare('UPDATE modules SET position = :position WHERE id = :id');
        $update->execute(['position' => (int) $neighbour['position'], 'id' => $id]);
        $update->execute(['position' => (int) $row['position'], 'id' => (int) $neighbour['id']]);
    }

    /**
     * Riassegna posizioni consecutive a partire da 0, mantenendo l'ordine attuale.
     */
    private static function renumber(int $parentId): void
    {
        $db = Database::connection();
        $update = $db->prepare('UPDATE modules SET position = :position WHERE id = :id');
        $position = 0;

        foreach (self::forCourse($parentId) as $row) {
            $update->execute(['position' => $position++, 'id' => (int) $row['id']]);
        }
    }

}
