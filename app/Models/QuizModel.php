<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `quizzes` (un quiz per modulo).
 */
class QuizModel
{
    /**
     * Un questionario H5P non decide niente (29/10, Alessandro): il suo
     * punteggio lo manda il browser e si puo' falsificare, quindi non puo'
     * essere il questionario obbligatorio di un modulo ne' la verifica
     * finale. Quelli sono a domande, corretti da Pistacchio. Il pannello lo
     * fa rispettare da tutte e due le parti: niente H5P in un modulo cosi',
     * e niente casella accesa su un modulo con un H5P.
     */
    public const H5P_NON_AMMESSO = 'Il questionario obbligatorio e la verifica finale devono essere a domande, '
        . 'corrette da Pistacchio: un contenuto H5P non è ammesso.';

    /** Il modulo (riga di `modules`) puo' avere un questionario H5P. */
    public static function h5pAmmesso(array $module): bool
    {
        return empty($module['quiz_required']) && empty($module['is_final']);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM quizzes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $quiz = $stmt->fetch();

        return $quiz ?: null;
    }

    public static function forModule(int $moduleId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM quizzes WHERE module_id = :module_id ORDER BY id LIMIT 1'
        );
        $stmt->execute(['module_id' => $moduleId]);
        $quiz = $stmt->fetch();

        return $quiz ?: null;
    }

    /**
     * Quiz di tutti i moduli di un corso, con titolo del modulo e numero di domande.
     */
    public static function forCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT q.*, m.title AS module_title, m.position AS module_position,
                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count
             FROM quizzes q
             INNER JOIN modules m ON m.id = q.module_id
             WHERE m.course_id = :course_id
             ORDER BY m.position, m.id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    public static function create(int $moduleId, string $title, int $passingScorePct): int
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO quizzes (module_id, title, passing_score_pct)
             VALUES (:module_id, :title, :passing_score_pct)'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'title' => $title,
            'passing_score_pct' => $passingScorePct,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Un questionario H5P (22/10): il contenuto caricato, nella sua cartella
     * sotto storage/h5p. La cartella vecchia la toglie chi chiama, dopo.
     */
    public static function impostaH5P(int $id, string $dir, string $libreria, string $file): void
    {
        Database::connection()->prepare(
            "UPDATE quizzes SET kind = 'h5p', h5p_dir = :dir, h5p_library = :libreria, h5p_file = :file WHERE id = :id"
        )->execute([
            'dir' => $dir,
            'libreria' => mb_substr($libreria, 0, 120),
            'file' => mb_substr($file, 0, 255),
            'id' => $id,
        ]);
    }

    public static function update(int $id, string $title, int $passingScorePct): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE quizzes SET title = :title, passing_score_pct = :passing_score_pct WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'passing_score_pct' => $passingScorePct,
            'id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        // Domande, opzioni e tentativi seguono via FK ON DELETE CASCADE.
        $stmt = Database::connection()->prepare('DELETE FROM quizzes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function countQuestions(int $quizId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = :quiz_id'
        );
        $stmt->execute(['quiz_id' => $quizId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Id di tutti i quiz di un corso (usati per verificare l'idoneita' al certificato).
     *
     * @return int[]
     */
    public static function idsForCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT q.id
             FROM quizzes q
             INNER JOIN modules m ON m.id = q.module_id
             WHERE m.course_id = :course_id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
}
