<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `quiz_questions`.
 */
class QuizQuestionModel
{
    public static function forQuiz(int $quizId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, quiz_id, question_text, question_type, position
             FROM quiz_questions WHERE quiz_id = :quiz_id ORDER BY position, id'
        );
        $stmt->execute(['quiz_id' => $quizId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM quiz_questions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $question = $stmt->fetch();

        return $question ?: null;
    }

    public static function create(int $quizId, string $text, string $type): int
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO quiz_questions (quiz_id, question_text, question_type, position)
             VALUES (:quiz_id, :question_text, :question_type, :position)'
        );
        $stmt->execute([
            'quiz_id' => $quizId,
            'question_text' => $text,
            'question_type' => $type,
            'position' => self::nextPosition($quizId),
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $text, string $type): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE quiz_questions SET question_text = :question_text, question_type = :question_type WHERE id = :id'
        );
        $stmt->execute(['question_text' => $text, 'question_type' => $type, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Le opzioni collegate seguono via FK ON DELETE CASCADE.
        $stmt = Database::connection()->prepare('DELETE FROM quiz_questions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private static function nextPosition(int $quizId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM quiz_questions WHERE quiz_id = :quiz_id'
        );
        $stmt->execute(['quiz_id' => $quizId]);

        return (int) $stmt->fetchColumn();
    }
}
