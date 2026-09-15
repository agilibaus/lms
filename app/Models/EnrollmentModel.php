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

    /**
     * Iscrive un utente a un corso (idempotente: una seconda chiamata non
     * azzera il progresso gia' registrato).
     */
    public static function enroll(int $userId, int $courseId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (:user_id, :course_id)'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
    }

    /**
     * Iscrive piu' utenti a piu' corsi (usata quando un corso viene assegnato
     * a un gruppo o quando un utente entra in un gruppo).
     *
     * @param int[] $userIds
     * @param int[] $courseIds
     * @return int numero di iscrizioni effettivamente create
     */
    public static function enrollMany(array $userIds, array $courseIds): int
    {
        if ($userIds === [] || $courseIds === []) {
            return 0;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (:user_id, :course_id)'
        );

        $created = 0;

        foreach ($userIds as $userId) {
            foreach ($courseIds as $courseId) {
                $stmt->execute(['user_id' => (int) $userId, 'course_id' => (int) $courseId]);
                $created += $stmt->rowCount();
            }
        }

        return $created;
    }

    /**
     * Rimuove un'iscrizione: cancella anche progresso e certificato del corso
     * (FK ON DELETE CASCADE), quindi va confermata esplicitamente nel pannello.
     */
    public static function remove(int $userId, int $courseId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM enrollments WHERE user_id = :user_id AND course_id = :course_id'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
    }

    /**
     * Iscritti a un corso, con dati utente (pannello di gestione corso).
     */
    public static function forCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id AS user_id, u.full_name, u.email, u.role, u.is_active,
                    e.enrolled_at, e.progress_pct, e.completed_at
             FROM enrollments e
             INNER JOIN users u ON u.id = e.user_id
             WHERE e.course_id = :course_id
             ORDER BY u.full_name'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }
}
