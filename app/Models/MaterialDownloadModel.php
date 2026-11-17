<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Chi ha scaricato i materiali delle lezioni (17/11, Alessandro): la tabella
 * `material_downloads`, una riga per studente e materiale con la data del
 * primo scaricamento. La scrive `LessonController::downloadMaterial`, solo
 * per gli studenti; la leggono i report del corso e dello studente.
 */
class MaterialDownloadModel
{
    /** Il primo scaricamento resta: quelli dopo non cambiano niente. */
    public static function registra(int $materialId, int $userId): void
    {
        Database::connection()
            ->prepare('INSERT IGNORE INTO material_downloads (material_id, user_id) VALUES (:m, :u)')
            ->execute(['m' => $materialId, 'u' => $userId]);
    }

    /**
     * I materiali del corso nell'ordine del corso: modulo, lezione, materiale.
     *
     * @return list<array{id: int, file_name: string, lesson_title: string}>
     */
    public static function materialiDelCorso(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT lm.id, lm.file_name, l.title AS lesson_title
             FROM lesson_materials lm
             INNER JOIN lessons l ON l.id = lm.lesson_id
             INNER JOIN modules m ON m.id = l.module_id
             WHERE m.course_id = :course_id
             ORDER BY m.position, m.id, l.position, l.id, lm.position, lm.id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * Gli scaricamenti dei materiali del corso, dal piu' vecchio.
     *
     * @return list<array{material_id: int, user_id: int, full_name: string, first_at: string}>
     */
    public static function scaricamentiDelCorso(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT md.material_id, md.user_id, u.full_name, md.first_at
             FROM material_downloads md
             INNER JOIN lesson_materials lm ON lm.id = md.material_id
             INNER JOIN lessons l ON l.id = lm.lesson_id
             INNER JOIN modules m ON m.id = l.module_id
             INNER JOIN users u ON u.id = md.user_id
             WHERE m.course_id = :course_id
             ORDER BY md.first_at, u.full_name'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * I materiali del corso con il primo scaricamento dello studente, null
     * se non l'ha scaricato.
     *
     * @return list<array{id: int, file_name: string, lesson_title: string, first_at: ?string}>
     */
    public static function delloStudenteNelCorso(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT lm.id, lm.file_name, l.title AS lesson_title, md.first_at
             FROM lesson_materials lm
             INNER JOIN lessons l ON l.id = lm.lesson_id
             INNER JOIN modules m ON m.id = l.module_id
             LEFT JOIN material_downloads md ON md.material_id = lm.id AND md.user_id = :user_id
             WHERE m.course_id = :course_id
             ORDER BY m.position, m.id, l.position, l.id, lm.position, lm.id'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);

        return $stmt->fetchAll();
    }
}
