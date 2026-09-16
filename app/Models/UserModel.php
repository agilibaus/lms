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
            'SELECT id, email, full_name, role, supervising_tutor_id, is_active, email_verified_at, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Elenco utenti, ordinato per nome (per pannello admin/gestione utenti).
     * Include il nome del tutor supervisore, dove presente.
     */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT u.id, u.email, u.full_name, u.role, u.supervising_tutor_id, u.is_active, u.created_at,
                    t.full_name AS supervising_tutor_name
             FROM users u
             LEFT JOIN users t ON t.id = u.supervising_tutor_id
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
     * Assistenti assegnati a un tutor (supervising_tutor_id).
     */
    public static function assistantsForTutor(int $tutorId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, full_name, is_active
             FROM users WHERE role = \'assistente\' AND supervising_tutor_id = :tutor_id ORDER BY full_name'
        );
        $stmt->execute(['tutor_id' => $tutorId]);

        return $stmt->fetchAll();
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
        ?int $supervisingTutorId,
        bool $isActive
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET email = :email, full_name = :full_name, role = :role,
                 supervising_tutor_id = :supervising_tutor_id, is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'email' => $email,
            'full_name' => $fullName,
            'role' => $role,
            // Il tutor supervisore ha senso solo per gli assistenti.
            'supervising_tutor_id' => $role === 'assistente' ? $supervisingTutorId : null,
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
        ?int $supervisingTutorId = null,
        bool $isActive = true,
        bool $emailVerified = true
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, supervising_tutor_id, is_active, email_verified_at)
             VALUES (:email, :password_hash, :full_name, :role, :supervising_tutor_id, :is_active,
                     CASE WHEN :email_verified = 1 THEN NOW() ELSE NULL END)'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'role' => $role,
            'supervising_tutor_id' => $role === 'assistente' ? $supervisingTutorId : null,
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
