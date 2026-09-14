<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `enrollments`.
 */
class EnrollmentModel
{
    public static function find(int $userId, int $courseId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM enrollments WHERE user_id = :user_id AND course_id = :course_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
        $enrollment = $stmt->fetch();

        return $enrollment ?: null;
    }

    /**
     * Aggiorna la percentuale di completamento; imposta completed_at la prima
     * volta che si raggiunge il 100% (senza sovrascriverlo se già valorizzato,
     * e lo azzera se il progresso torna sotto al 100%).
     */
    public static function updateProgress(int $userId, int $courseId, float $progressPct): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE enrollments
             SET progress_pct = :progress_pct,
                 completed_at = CASE WHEN :is_complete THEN COALESCE(completed_at, NOW()) ELSE NULL END
             WHERE user_id = :user_id AND course_id = :course_id'
        );
        $stmt->execute([
            'progress_pct' => $progressPct,
            'is_complete' => $progressPct >= 100 ? 1 : 0,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
    }
}
