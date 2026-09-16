<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Richieste di iscrizione ai corsi con `enrollment_mode = 'request'`.
 *
 * Tabella separata dalle iscrizioni, e non uno stato dentro `enrollments`,
 * perché tutti i controlli di accesso guardano l'esistenza della riga in
 * `enrollments`: una richiesta in attesa non deve, per errore, aprire il corso.
 */
class EnrollmentRequestModel
{
    public static function find(int $userId, int $courseId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM enrollment_requests WHERE user_id = :user_id AND course_id = :course_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.full_name, u.email, c.title AS course_title
             FROM enrollment_requests r
             INNER JOIN users u ON u.id = r.user_id
             INNER JOIN courses c ON c.id = r.course_id
             WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Crea la richiesta, oppure la riporta in attesa se era stata rifiutata
     * (uno studente può ripresentarsi).
     */
    public static function create(int $userId, int $courseId, ?string $message): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO enrollment_requests (user_id, course_id, message)
             VALUES (:user_id, :course_id, :message)
             ON DUPLICATE KEY UPDATE
                status = \'pending\', message = VALUES(message),
                requested_at = NOW(), decided_at = NULL, decided_by = NULL'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId, 'message' => $message]);
    }

    public static function decide(int $id, string $status, int $decidedBy): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE enrollment_requests
             SET status = :status, decided_at = NOW(), decided_by = :decided_by
             WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'decided_by' => $decidedBy, 'id' => $id]);
    }

    /**
     * Richieste in attesa di un corso.
     */
    public static function pendingForCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.full_name, u.email
             FROM enrollment_requests r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.course_id = :course_id AND r.status = \'pending\'
             ORDER BY r.requested_at'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * Tutte le richieste in attesa (pannello di amministrazione).
     */
    public static function allPending(): array
    {
        return Database::connection()->query(
            "SELECT r.*, u.full_name, u.email, c.title AS course_title
             FROM enrollment_requests r
             INNER JOIN users u ON u.id = r.user_id
             INNER JOIN courses c ON c.id = r.course_id
             WHERE r.status = 'pending'
             ORDER BY r.requested_at"
        )->fetchAll();
    }

    public static function countPending(): int
    {
        return (int) Database::connection()
            ->query("SELECT COUNT(*) FROM enrollment_requests WHERE status = 'pending'")
            ->fetchColumn();
    }
}
