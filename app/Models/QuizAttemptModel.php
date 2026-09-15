<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per `quiz_attempts` e `quiz_attempt_answers`.
 * I tentativi sono illimitati: vale sempre il punteggio migliore.
 */
class QuizAttemptModel
{
    /**
     * Registra un tentativo e le relative risposte in un'unica transazione.
     *
     * @param array<int, array{question_id: int, selected_option_id: int, is_correct: bool}> $answers
     */
    public static function create(int $userId, int $quizId, float $scorePct, bool $passed, array $answers): int
    {
        $db = Database::connection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                'INSERT INTO quiz_attempts (user_id, quiz_id, score_pct, passed)
                 VALUES (:user_id, :quiz_id, :score_pct, :passed)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'quiz_id' => $quizId,
                'score_pct' => $scorePct,
                'passed' => $passed ? 1 : 0,
            ]);

            $attemptId = (int) $db->lastInsertId();

            $answerStmt = $db->prepare(
                'INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_option_id, is_correct)
                 VALUES (:attempt_id, :question_id, :selected_option_id, :is_correct)'
            );

            foreach ($answers as $answer) {
                $answerStmt->execute([
                    'attempt_id' => $attemptId,
                    'question_id' => $answer['question_id'],
                    'selected_option_id' => $answer['selected_option_id'],
                    'is_correct' => $answer['is_correct'] ? 1 : 0,
                ]);
            }

            $db->commit();

            return $attemptId;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM quiz_attempts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $attempt = $stmt->fetch();

        return $attempt ?: null;
    }

    /**
     * Tutti i tentativi di un utente su un quiz, dal piu' recente.
     */
    public static function forUserAndQuiz(int $userId, int $quizId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, score_pct, passed, attempted_at
             FROM quiz_attempts
             WHERE user_id = :user_id AND quiz_id = :quiz_id
             ORDER BY attempted_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId, 'quiz_id' => $quizId]);

        return $stmt->fetchAll();
    }

    public static function hasPassed(int $userId, int $quizId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM quiz_attempts
             WHERE user_id = :user_id AND quiz_id = :quiz_id AND passed = 1 LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'quiz_id' => $quizId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Miglior punteggio (e relativo esito) di un utente su un quiz.
     *
     * @return array{score_pct: float, passed: bool, attempts: int}|null
     */
    public static function bestForUserAndQuiz(int $userId, int $quizId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT MAX(score_pct) AS score_pct, MAX(passed) AS passed, COUNT(*) AS attempts
             FROM quiz_attempts
             WHERE user_id = :user_id AND quiz_id = :quiz_id'
        );
        $stmt->execute(['user_id' => $userId, 'quiz_id' => $quizId]);
        $row = $stmt->fetch();

        if (!$row || $row['attempts'] === 0 || $row['attempts'] === '0') {
            return null;
        }

        return [
            'score_pct' => (float) $row['score_pct'],
            'passed' => (bool) $row['passed'],
            'attempts' => (int) $row['attempts'],
        ];
    }

    /**
     * Id dei quiz di un corso superati dall'utente.
     *
     * @return int[]
     */
    public static function passedQuizIdsForCourse(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT qa.quiz_id
             FROM quiz_attempts qa
             INNER JOIN quizzes q ON q.id = qa.quiz_id
             INNER JOIN modules m ON m.id = q.module_id
             WHERE qa.user_id = :user_id AND m.course_id = :course_id AND qa.passed = 1'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'quiz_id'));
    }

    /**
     * Risposte date in un tentativo, con testo della domanda e dell'opzione scelta.
     */
    public static function answersForAttempt(int $attemptId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT qaa.question_id, qaa.is_correct, qq.question_text, qo.option_text
             FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             INNER JOIN quiz_options qo ON qo.id = qaa.selected_option_id
             WHERE qaa.attempt_id = :attempt_id
             ORDER BY qq.position, qq.id'
        );
        $stmt->execute(['attempt_id' => $attemptId]);

        return $stmt->fetchAll();
    }

    /**
     * Sintesi dei tentativi di un utente su tutti i quiz di un corso (per i report).
     */
    public static function summaryForUserAndCourse(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT q.id AS quiz_id, q.title AS quiz_title, q.passing_score_pct,
                    m.title AS module_title,
                    COUNT(qa.id) AS attempts,
                    MAX(qa.score_pct) AS best_score_pct,
                    MAX(qa.passed) AS passed,
                    MAX(qa.attempted_at) AS last_attempt_at
             FROM quizzes q
             INNER JOIN modules m ON m.id = q.module_id
             LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.user_id = :user_id
             WHERE m.course_id = :course_id
             GROUP BY q.id, q.title, q.passing_score_pct, m.title, m.position, m.id
             ORDER BY m.position, m.id'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);

        return $stmt->fetchAll();
    }
}
