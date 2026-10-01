<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `modules`.
 */
class ModuleModel
{
    /**
     * I moduli del corso, ciascuno con `is_available`: 1 se e' gia' aperto.
     *
     * **Il confronto con l'ora si fa qui, in SQL, non in PHP.** E' la regola
     * di §5 gia' pagata una volta con i tempi di fruizione: l'orologio di PHP
     * e quello di MySQL possono stare su fusi diversi, e qui un'ora di
     * differenza vuol dire un modulo aperto o chiuso quando non doveva.
     * `NOW()` e `available_from` vengono dallo stesso orologio per
     * costruzione.
     *
     * `available_from` NULL vuol dire «sempre aperto»: e' il comportamento di
     * tutti i moduli che esistevano prima di questa funzione.
     */
    public static function forCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, course_id, title, position, quiz_required, available_from,
                    (available_from IS NULL OR available_from <= NOW()) AS is_available
             FROM modules WHERE course_id = :course_id ORDER BY position, id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * Un solo modulo, con lo stesso `is_available` calcolato in SQL.
     *
     * `find()` resta com'era — la usano in molti punti che non c'entrano con
     * il rilascio — ma chi deve decidere se far entrare qualcuno usa questa.
     */
    public static function findWithAvailability(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT *, (available_from IS NULL OR available_from <= NOW()) AS is_available
             FROM modules WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $module = $stmt->fetch();

        return $module ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM modules WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $module = $stmt->fetch();

        return $module ?: null;
    }

    public static function create(
        int $courseId,
        string $title,
        bool $quizRequired = false,
        ?string $availableFrom = null
    ): int {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO modules (course_id, title, position, quiz_required, available_from)
             VALUES (:course_id, :title, :position, :quiz_required, :available_from)'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => $title,
            'position' => self::nextPosition($courseId),
            'quiz_required' => $quizRequired ? 1 : 0,
            'available_from' => $availableFrom,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(
        int $id,
        string $title,
        bool $quizRequired = false,
        ?string $availableFrom = null
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE modules
                SET title = :title, quiz_required = :quiz_required, available_from = :available_from
              WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'quiz_required' => $quizRequired ? 1 : 0,
            'available_from' => $availableFrom,
            'id' => $id,
        ]);
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

        // Le righe si rileggono dopo la rinumerazione: se nel frattempo una e'

        // stata eliminata, non c'e' piu' niente da scambiare.

        if ($row === null || $neighbour === null) {

            return;

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
