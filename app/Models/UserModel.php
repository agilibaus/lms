<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `users`.
 */
class UserModel
{
    /**
     * Recupera un utente (anche non attivo) a partire dall'email,
     * includendo l'hash della password — usato solo dal layer Auth.
     */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, password_hash, full_name, role, is_active, email_verified_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Recupera un utente per id, senza l'hash della password.
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, full_name, role, is_active, email_verified_at,
                    bio, phone, city, avatar_path, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Dati del profilo compilati dall'utente stesso.
     */
    public static function updateProfile(int $id, string $fullName, ?string $bio, ?string $phone, ?string $city): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET full_name = :full_name, bio = :bio, phone = :phone, city = :city WHERE id = :id'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'bio' => $bio,
            'phone' => $phone,
            'city' => $city,
            'id' => $id,
        ]);
    }

    /**
     * @param string|null $path percorso relativo a /storage, null per togliere l'immagine
     */
    public static function updateAvatar(int $id, ?string $path): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET avatar_path = :path WHERE id = :id');
        $stmt->execute(['path' => $path, 'id' => $id]);
    }

    /**
     * Elenco utenti, ordinato per nome (per pannello admin/gestione utenti).
     * Include il nome del tutor supervisore, dove presente.
     */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT u.id, u.email, u.full_name, u.role, u.is_active, u.created_at,
                    GROUP_CONCAT(t.full_name ORDER BY t.full_name SEPARATOR \', \') AS supervising_tutor_name
             FROM users u
             LEFT JOIN assistant_tutors at ON at.assistant_id = u.id
             LEFT JOIN users t ON t.id = at.tutor_id
             GROUP BY u.id
             ORDER BY u.full_name'
        )->fetchAll();
    }

    /**
     * Utenti di uno o piu' ruoli (es. elenco tutor per la tendina, studenti da iscrivere).
     *
     * @param string[] $roles
     */
    public static function byRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = Database::connection()->prepare(
            'SELECT id, email, full_name, role, is_active
             FROM users WHERE role IN (' . $placeholders . ') ORDER BY full_name'
        );
        $stmt->execute(array_values($roles));

        return $stmt->fetchAll();
    }

    /**
     * Assistenti che affiancano un tutor.
     */
    public static function assistantsForTutor(int $tutorId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.email, u.full_name, u.is_active
             FROM assistant_tutors at
             INNER JOIN users u ON u.id = at.assistant_id AND u.role = \'assistente\'
             WHERE at.tutor_id = :tutor_id
             ORDER BY u.full_name'
        );
        $stmt->execute(['tutor_id' => $tutorId]);

        return $stmt->fetchAll();
    }

    /**
     * Tutor che un assistente affianca: possono essere piu' d'uno.
     *
     * @return int[]
     */
    public static function tutorIdsForAssistant(int $assistantId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT at.tutor_id
             FROM assistant_tutors at
             INNER JOIN users t ON t.id = at.tutor_id AND t.role = \'tutor\'
             WHERE at.assistant_id = :id
             ORDER BY at.tutor_id'
        );
        $stmt->execute(['id' => $assistantId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Riscrive i tutor di un assistente. Chi non e' assistente non ne ha: se
     * l'utente cambia ruolo, i legami vecchi spariscono invece di restare
     * appesi a un utente che non affianca piu' nessuno.
     *
     * @param int[] $tutorIds
     */
    public static function setAssistantTutors(int $userId, string $role, array $tutorIds): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM assistant_tutors WHERE assistant_id = :id')->execute(['id' => $userId]);

        if ($role !== 'assistente') {
            return;
        }

        $insert = $db->prepare(
            'INSERT IGNORE INTO assistant_tutors (assistant_id, tutor_id)
             SELECT :assistant, id FROM users WHERE id = :tutor AND role = \'tutor\''
        );

        foreach (array_unique(array_map('intval', $tutorIds)) as $tutorId) {
            $insert->execute(['assistant' => $userId, 'tutor' => $tutorId]);
        }
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM users WHERE email = :email AND (:except_id IS NULL OR id <> :except_id2) LIMIT 1'
        );
        $stmt->execute([
            'email' => $email,
            'except_id' => $exceptId,
            'except_id2' => $exceptId ?? 0,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public static function update(
        int $id,
        string $email,
        string $fullName,
        string $role,
        bool $isActive
    ): void {
        // I tutor di un assistente non stanno piu' qui: vedi setAssistantTutors().
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET email = :email, full_name = :full_name, role = :role, is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'email' => $email,
            'full_name' => $fullName,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);
    }

    public static function updatePassword(int $id, string $password): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
        );
        $stmt->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $id,
        ]);
    }

    public static function setActive(int $id, bool $isActive): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
        $stmt->execute(['is_active' => $isActive ? 1 : 0, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Numero di amministratori attivi: serve a impedire di rimuovere l'ultimo admin.
     */
    public static function countActiveAdmins(?int $exceptId = null): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users
             WHERE role = \'admin\' AND is_active = 1 AND (:except_id IS NULL OR id <> :except_id2)'
        );
        $stmt->execute(['except_id' => $exceptId, 'except_id2' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Numero di corsi creati dall'utente: `courses.created_by` e' ON DELETE RESTRICT,
     * quindi un autore di corsi non puo' essere eliminato.
     */
    public static function countCoursesCreated(int $id): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM courses WHERE created_by = :id');
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Crea un nuovo utente con password hashata (bcrypt via password_hash).
     */
    public static function create(
        string $email,
        string $password,
        string $fullName,
        string $role = 'studente',
        bool $isActive = true,
        bool $emailVerified = true
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at)
             VALUES (:email, :password_hash, :full_name, :role, :is_active,
                     CASE WHEN :email_verified = 1 THEN NOW() ELSE NULL END)'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            // Un account creato dallo staff ha un indirizzo gia' noto: chiedere
            // una conferma avrebbe senso solo per chi si registra da solo.
            'email_verified' => $emailVerified ? 1 : 0,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function markEmailVerified(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET email_verified_at = NOW() WHERE id = :id AND email_verified_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Amministratori e tutor da avvisare quando arriva una richiesta di iscrizione.
     */
    public static function staffForNotifications(): array
    {
        return Database::connection()->query(
            "SELECT id, email, full_name FROM users
             WHERE role IN ('admin','tutor') AND is_active = 1
             ORDER BY full_name"
        )->fetchAll();
    }
}
