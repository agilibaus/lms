<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * I contenuti H5P di una lezione (23/10, tabella `lesson_h5p`): uno o piu'
 * per lezione, messi nel testo con un segnaposto dell'editor o, se non ci
 * sono, dopo il testo. Le regole in `App\Core\LezioneH5P`.
 */
class LessonH5PModel
{
    /** @return list<array<string, mixed>> nell'ordine in cui sono stati aggiunti */
    public static function forLesson(int $lessonId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, lesson_id, h5p_dir, h5p_library, h5p_title, h5p_file, position, created_at
             FROM lesson_h5p WHERE lesson_id = :lesson_id ORDER BY position, id'
        );
        $stmt->execute(['lesson_id' => $lessonId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM lesson_h5p WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $riga = $stmt->fetch();

        return $riga ?: null;
    }

    /** @param array{dir: string, titolo: string, libreria: string} $contenuto */
    public static function create(int $lessonId, array $contenuto, string $file): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO lesson_h5p (lesson_id, h5p_dir, h5p_library, h5p_title, h5p_file, position)
             VALUES (:lesson_id, :dir, :libreria, :titolo, :file,
                     (SELECT p FROM (SELECT COALESCE(MAX(position), 0) + 1 AS p FROM lesson_h5p WHERE lesson_id = :l2) t))'
        );
        $stmt->execute([
            'lesson_id' => $lessonId,
            'dir' => $contenuto['dir'],
            'libreria' => $contenuto['libreria'],
            'titolo' => $contenuto['titolo'],
            'file' => $file,
            'l2' => $lessonId,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Un contenuto nuovo al posto di quello di prima, con lo stesso id: i
     * segnaposto nel testo restano validi.
     *
     * @param array{dir: string, titolo: string, libreria: string} $contenuto
     */
    public static function replace(int $id, array $contenuto, string $file): void
    {
        Database::connection()->prepare(
            'UPDATE lesson_h5p SET h5p_dir = :dir, h5p_library = :libreria, h5p_title = :titolo, h5p_file = :file WHERE id = :id'
        )->execute([
            'id' => $id,
            'dir' => $contenuto['dir'],
            'libreria' => $contenuto['libreria'],
            'titolo' => $contenuto['titolo'],
            'file' => $file,
        ]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM lesson_h5p WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Le cartelle dei contenuti di una lezione: da togliere dal disco quando
     * la lezione si elimina (le righe se ne vanno da sole, con lei).
     *
     * @return list<string>
     */
    public static function dirsForLesson(int $lessonId): array
    {
        return array_map(static fn (array $r): string => (string) $r['h5p_dir'], self::forLesson($lessonId));
    }
}
