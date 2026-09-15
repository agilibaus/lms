<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `quizzes` (un quiz per modulo).
 */
class QuizModel
{
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
