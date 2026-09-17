<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per `groups`, `group_members` e `group_course_access`.
 */
class GroupModel
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.*, u.full_name AS tutor_name
             FROM `groups` g
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
            'SELECT g.id, g.name, g.logo_path, g.tutor_id, u.full_name AS tutor_name,
                    (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count
             FROM `groups` g
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
            'SELECT g.id, g.name, g.logo_path, g.tutor_id, u.full_name AS tutor_name,
                    (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count
             FROM `groups` g
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
     * Gruppi di cui un utente e' membro, con il tutor e quanti corsi porta con se'.
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.id, g.name, g.logo_path, g.tutor_id, u.full_name AS tutor_name, gm.joined_at,
                    (SELECT COUNT(*) FROM group_course_access gca WHERE gca.group_id = g.id) AS course_count
             FROM group_members gm
             INNER JOIN `groups` g ON g.id = gm.group_id
             LEFT JOIN users u ON u.id = g.tutor_id
             WHERE gm.user_id = :user_id
             ORDER BY g.name'
        );
        $stmt->execute(['user_id' => $userId]);

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
             INNER JOIN `groups` g ON g.id = gm.group_id
             WHERE g.tutor_id = :tutor_id'
        );
        $stmt->execute(['tutor_id' => $tutorId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    // -----------------------------------------------------------------
    // Scrittura (pannello di amministrazione)
    // -----------------------------------------------------------------

    public static function create(string $name, ?string $description, ?int $tutorId): int
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO `groups` (name, description, tutor_id) VALUES (:name, :description, :tutor_id)'
        );
        $stmt->execute(['name' => $name, 'description' => $description, 'tutor_id' => $tutorId]);

        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $name, ?string $description, ?int $tutorId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE `groups` SET name = :name, description = :description, tutor_id = :tutor_id WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'description' => $description, 'tutor_id' => $tutorId, 'id' => $id]);
    }

    /**
     * Logo: aggiornato a parte perche' il modulo dei dati non porta il file,
     * e salvare il nome del gruppo non deve cancellarne l'immagine.
     */
    public static function updateLogo(int $id, ?string $logoPath): void
    {
        $stmt = Database::connection()->prepare('UPDATE `groups` SET logo_path = :logo WHERE id = :id');
        $stmt->execute(['logo' => $logoPath, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Membri e assegnazioni corso seguono via FK ON DELETE CASCADE;
        // le iscrizioni gia' create restano (il progresso non va perso).
        $stmt = Database::connection()->prepare('DELETE FROM `groups` WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function addMember(int $groupId, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)'
        );
        $stmt->execute(['group_id' => $groupId, 'user_id' => $userId]);
    }

    public static function removeMember(int $groupId, int $userId): void
    {
        // Le iscrizioni ai corsi restano: toglierle cancellerebbe progresso e
        // tentativi quiz gia' registrati.
        $stmt = Database::connection()->prepare(
            'DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id'
        );
        $stmt->execute(['group_id' => $groupId, 'user_id' => $userId]);
    }

    public static function addCourse(int $groupId, int $courseId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO group_course_access (group_id, course_id) VALUES (:group_id, :course_id)'
        );
        $stmt->execute(['group_id' => $groupId, 'course_id' => $courseId]);
    }

    public static function removeCourse(int $groupId, int $courseId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM group_course_access WHERE group_id = :group_id AND course_id = :course_id'
        );
        $stmt->execute(['group_id' => $groupId, 'course_id' => $courseId]);
    }

    /**
     * @return int[] id degli utenti membri del gruppo
     */
    public static function memberIds(int $groupId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id FROM group_members WHERE group_id = :group_id'
        );
        $stmt->execute(['group_id' => $groupId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    /**
     * @return int[] id dei corsi assegnati al gruppo
     */
    public static function courseIds(int $groupId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT course_id FROM group_course_access WHERE group_id = :group_id'
        );
        $stmt->execute(['group_id' => $groupId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'course_id'));
    }
}
