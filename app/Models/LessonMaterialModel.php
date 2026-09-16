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
            'SELECT id, lesson_id, file_name, file_path, file_type, file_size_bytes, position, created_at
             FROM lesson_materials WHERE lesson_id = :lesson_id ORDER BY position, id'
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
            'INSERT INTO lesson_materials (lesson_id, file_name, file_path, file_type, file_size_bytes, position)
             VALUES (:lesson_id, :file_name, :file_path, :file_type, :file_size_bytes, :position)'
        );
        $stmt->execute([
            'lesson_id' => $lessonId,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'file_size_bytes' => $fileSizeBytes,
            'position' => self::nextPosition($lessonId),
        ]);

        return (int) $db->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM lesson_materials WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Sposta un materiale di una posizione su o giu' scambiandolo con il vicino.
     *
     * @param string $direction 'up' oppure 'down'
     */
    public static function move(int $id, string $direction): void
    {
        $material = self::find($id);

        if ($material === null) {
            return;
        }

        $db = Database::connection();

        // Il vicino e' la riga adiacente nell'ordine di visualizzazione, non
        // quella con position +/- 1: dopo un'eliminazione le posizioni possono
        // avere dei buchi, e le righe importate potrebbero condividere lo zero.
        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $stmt = $db->prepare(
            'SELECT id, position FROM lesson_materials
             WHERE lesson_id = :lesson_id AND (position, id) ' . $comparison . ' (:position, :id)
             ORDER BY position ' . $order . ', id ' . $order . ' LIMIT 1'
        );
        $stmt->execute([
            'lesson_id' => (int) $material['lesson_id'],
            'position' => (int) $material['position'],
            'id' => $id,
        ]);
        $neighbour = $stmt->fetch();

        if (!$neighbour) {
            return; // gia' in cima o in fondo
        }

        // Posizioni identiche (dati importati): rinumerare e' l'unico modo di
        // ottenere uno scambio visibile.
        if ((int) $neighbour['position'] === (int) $material['position']) {
            self::renumber((int) $material['lesson_id']);
            $material = self::find($id);
            $neighbour = self::find((int) $neighbour['id']);
        }

        $update = $db->prepare('UPDATE lesson_materials SET position = :position WHERE id = :id');
        $update->execute(['position' => (int) $neighbour['position'], 'id' => $id]);
        $update->execute(['position' => (int) $material['position'], 'id' => (int) $neighbour['id']]);
    }

    private static function nextPosition(int $lessonId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM lesson_materials WHERE lesson_id = :lesson_id'
        );
        $stmt->execute(['lesson_id' => $lessonId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Riassegna posizioni consecutive a partire da 0, mantenendo l'ordine attuale.
     */
    private static function renumber(int $lessonId): void
    {
        $db = Database::connection();
        $update = $db->prepare('UPDATE lesson_materials SET position = :position WHERE id = :id');
        $position = 0;

        foreach (self::forLesson($lessonId) as $material) {
            $update->execute(['position' => $position++, 'id' => (int) $material['id']]);
        }
    }
}
