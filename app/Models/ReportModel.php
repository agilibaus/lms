<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Query aggregate di sola lettura per i report (corso, studente, gruppo).
 * Tutte le percentuali di avanzamento restano quelle calcolate da
 * `enrollments.progress_pct`; qui si aggiungono i conteggi di dettaglio.
 */
class ReportModel
{
    /**
     * Elenco corsi con numero di iscritti, completati e certificati emessi.
     */
    public static function coursesOverview(): array
    {
        return Database::connection()->query(
            'SELECT c.id, c.title, c.is_published,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled_count,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.completed_at IS NOT NULL) AS completed_count,
                    (SELECT COUNT(*) FROM certificates ce WHERE ce.course_id = c.id AND ce.revoked_at IS NULL) AS certificate_count
             FROM courses c
             ORDER BY c.title'
        )->fetchAll();
    }

    /**
     * Dettaglio di un corso: una riga per iscritto.
     */
    public static function courseDetail(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id AS user_id, u.full_name, u.email,
                    e.enrolled_at, e.progress_pct, e.completed_at,
                    (SELECT COUNT(*)
                       FROM lesson_progress lp
                       INNER JOIN lessons l ON l.id = lp.lesson_id
                       INNER JOIN modules m ON m.id = l.module_id
                      WHERE m.course_id = :course_id_lessons AND lp.user_id = u.id) AS lessons_completed,
                    (SELECT COUNT(DISTINCT qa.quiz_id)
                       FROM quiz_attempts qa
                       INNER JOIN quizzes q ON q.id = qa.quiz_id
                       INNER JOIN modules m ON m.id = q.module_id
                      WHERE m.course_id = :course_id_quizzes AND qa.user_id = u.id AND qa.passed = 1) AS quizzes_passed,
                    cert.certificate_code, cert.issued_at AS certificate_issued_at, cert.revoked_at AS certificate_revoked_at
             FROM enrollments e
             INNER JOIN users u ON u.id = e.user_id
             LEFT JOIN certificates cert ON cert.user_id = u.id AND cert.course_id = e.course_id
             WHERE e.course_id = :course_id
             ORDER BY u.full_name'
        );
        $stmt->execute([
            'course_id_lessons' => $courseId,
            'course_id_quizzes' => $courseId,
            'course_id' => $courseId,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Totali di un corso (lezioni e quiz), per rendere leggibili i conteggi.
     *
     * @return array{lessons: int, quizzes: int}
     */
    public static function courseTotals(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM lessons l
                   INNER JOIN modules m ON m.id = l.module_id
                  WHERE m.course_id = :course_id_lessons) AS lessons,
                (SELECT COUNT(*) FROM quizzes q
                   INNER JOIN modules m ON m.id = q.module_id
                  WHERE m.course_id = :course_id_quizzes) AS quizzes'
        );
        $stmt->execute(['course_id_lessons' => $courseId, 'course_id_quizzes' => $courseId]);
        $row = $stmt->fetch();

        return [
            'lessons' => (int) ($row['lessons'] ?? 0),
            'quizzes' => (int) ($row['quizzes'] ?? 0),
        ];
    }

    /**
     * Elenco studenti con numero di iscrizioni e certificati.
     */
    public static function studentsOverview(): array
    {
        return Database::connection()->query(
            "SELECT u.id, u.full_name, u.email, u.is_active,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) AS enrolled_count,
                    (SELECT COUNT(*) FROM certificates c WHERE c.user_id = u.id AND c.revoked_at IS NULL) AS certificate_count
             FROM users u
             WHERE u.role = 'studente'
             ORDER BY u.full_name"
        )->fetchAll();
    }

    /**
     * Dettaglio di uno studente: una riga per corso a cui e' iscritto.
     */
    public static function studentDetail(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id AS course_id, c.title AS course_title,
                    e.enrolled_at, e.progress_pct, e.completed_at,
                    (SELECT COUNT(*) FROM lessons l
                       INNER JOIN modules m ON m.id = l.module_id
                      WHERE m.course_id = c.id) AS lessons_total,
                    (SELECT COUNT(*) FROM lesson_progress lp
                       INNER JOIN lessons l ON l.id = lp.lesson_id
                       INNER JOIN modules m ON m.id = l.module_id
                      WHERE m.course_id = c.id AND lp.user_id = e.user_id) AS lessons_completed,
                    (SELECT COUNT(*) FROM quizzes q
                       INNER JOIN modules m ON m.id = q.module_id
                      WHERE m.course_id = c.id) AS quizzes_total,
                    (SELECT COUNT(DISTINCT qa.quiz_id) FROM quiz_attempts qa
                       INNER JOIN quizzes q ON q.id = qa.quiz_id
                       INNER JOIN modules m ON m.id = q.module_id
                      WHERE m.course_id = c.id AND qa.user_id = e.user_id AND qa.passed = 1) AS quizzes_passed,
                    cert.certificate_code, cert.issued_at AS certificate_issued_at, cert.revoked_at AS certificate_revoked_at
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN certificates cert ON cert.user_id = e.user_id AND cert.course_id = e.course_id
             WHERE e.user_id = :user_id
             ORDER BY c.title'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Dettaglio di un gruppo: una riga per membro e corso assegnato al gruppo.
     */
    public static function groupDetail(int $groupId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id AS user_id, u.full_name, u.email,
                    c.id AS course_id, c.title AS course_title,
                    e.progress_pct, e.completed_at,
                    (SELECT COUNT(DISTINCT qa.quiz_id) FROM quiz_attempts qa
                       INNER JOIN quizzes q ON q.id = qa.quiz_id
                       INNER JOIN modules m ON m.id = q.module_id
                      WHERE m.course_id = c.id AND qa.user_id = u.id AND qa.passed = 1) AS quizzes_passed,
                    (SELECT COUNT(*) FROM quizzes q
                       INNER JOIN modules m ON m.id = q.module_id
                      WHERE m.course_id = c.id) AS quizzes_total,
                    cert.certificate_code, cert.revoked_at AS certificate_revoked_at
             FROM group_members gm
             INNER JOIN users u ON u.id = gm.user_id
             INNER JOIN group_course_access gca ON gca.group_id = gm.group_id
             INNER JOIN courses c ON c.id = gca.course_id
             LEFT JOIN enrollments e ON e.user_id = u.id AND e.course_id = c.id
             LEFT JOIN certificates cert ON cert.user_id = u.id AND cert.course_id = c.id
             WHERE gm.group_id = :group_id
             ORDER BY u.full_name, c.title'
        );
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll();
    }
}
