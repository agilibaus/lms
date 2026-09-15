<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per `groups`, `group_members` e `group_course_access`.
 * In questa fase e' in sola lettura: serve ai report per gruppo/coorte
 * (la gestione CRUD dei gruppi arrivera' con il pannello di amministrazione).
 */
class GroupModel
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.*, u.full_name AS tutor_name
             FROM groups g
             LEFT JOIN users u ON u.id = g.tutor_id
             WHERE g.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $group = $stmt->fetch();

        return $group ?: null;
    }

    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT g.id, g.name, g.tutor_id, u.full_name AS tutor_name,
                    (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count
             FROM groups g
             LEFT JOIN users u ON u.id = g.tutor_id
             ORDER BY g.name'
        )->fetchAll();
    }

    /**
     * Gruppi di cui un tutor e' responsabile.
     */
    public static function forTutor(int $tutorId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.id, g.name, g.tutor_id, u.full_name AS tutor_name,
                    (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count
             FROM groups g
             LEFT JOIN users u ON u.id = g.tutor_id
             WHERE g.tutor_id = :tutor_id
             ORDER BY g.name'
        );
        $stmt->execute(['tutor_id' => $tutorId]);

        return $stmt->fetchAll();
    }

    public static function members(int $groupId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.full_name, u.email, u.role, gm.joined_at
             FROM group_members gm
             INNER JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = :group_id
             ORDER BY u.full_name'
        );
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll();
    }

    /**
     * Corsi assegnati a un gruppo.
     */
    public static function courses(int $groupId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.title
             FROM group_course_access gca
             INNER JOIN courses c ON c.id = gca.course_id
             WHERE gca.group_id = :group_id
             ORDER BY c.title'
        );
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll();
    }

    /**
     * Id degli utenti che appartengono ad almeno un gruppo del tutor indicato —
     * definisce il perimetro visibile all'assistente assegnato a quel tutor.
     *
     * @return int[]
     */
    public static function memberIdsForTutor(int $tutorId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT gm.user_id
             FROM group_members gm
             INNER JOIN groups g ON g.id = gm.group_id
             WHERE g.tutor_id = :tutor_id'
        );
        $stmt->execute(['tutor_id' => $tutorId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }
}
