<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `lesson_progress` (tracciamento completamento
 * singola lezione da parte di uno studente).
 */
class LessonProgressModel
{
    public static function isCompleted(int $userId, int $lessonId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM lesson_progress WHERE user_id = :user_id AND lesson_id = :lesson_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);

        return (bool) $stmt->fetchColumn();
    }

    public static function markCompleted(int $userId, int $lessonId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (:user_id, :lesson_id)'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);
    }

    /**
     * @return int[] id delle lezioni completate dall'utente, per un dato corso
     */
    public static function completedLessonIdsForCourse(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT lp.lesson_id
             FROM lesson_progress lp
             INNER JOIN lessons l ON l.id = lp.lesson_id
             INNER JOIN modules m ON m.id = l.module_id
             WHERE lp.user_id = :user_id AND m.course_id = :course_id'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'lesson_id'));
    }

    public static function countCompletedForCourse(int $userId, int $courseId): int
    {
        return count(self::completedLessonIdsForCourse($userId, $courseId));
    }
}
