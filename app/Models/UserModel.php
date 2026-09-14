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
            'SELECT id, email, password_hash, full_name, role, is_active
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
            'SELECT id, email, full_name, role, supervising_tutor_id, is_active, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Elenco utenti, ordinato per nome (per pannello admin/gestione utenti).
     */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT id, email, full_name, role, supervising_tutor_id, is_active, created_at
             FROM users ORDER BY full_name'
        )->fetchAll();
    }

    /**
     * Crea un nuovo utente con password hashata (bcrypt via password_hash).
     */
    public static function create(string $email, string $password, string $fullName, string $role = 'studente'): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (email, password_hash, full_name, role)
             VALUES (:email, :password_hash, :full_name, :role)'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'role' => $role,
        ]);

        return (int) Database::connection()->lastInsertId();
    }
}
