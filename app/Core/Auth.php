<?php

declare(strict_types=1);

namespace App\Core;

class Auth
{
    public const ROLES = ['admin', 'tutor', 'assistente', 'studente'];

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, password_hash, full_name, role, is_active
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !(bool) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    public static function name(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }

    public static function hasRole(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();

        if (!self::hasRole(...$roles)) {
            http_response_code(403);
            exit('Accesso negato: permessi insufficienti per questa pagina.');
        }
    }

    /**
     * Verifica un permesso puntuale letto da role_permissions
     * (es. 'course.edit', 'quiz.grade_assigned').
     * Cache statica per richiesta: una sola query per ruolo.
     */
    public static function can(string $permissionKey): bool
    {
        if (!self::check()) {
            return false;
        }

        static $cache = [];
        $role = self::role();

        if (!isset($cache[$role])) {
            $stmt = Database::connection()->prepare(
                'SELECT permission_key FROM role_permissions WHERE role = :role'
            );
            $stmt->execute(['role' => $role]);
            $cache[$role] = array_column($stmt->fetchAll(), 'permission_key');
        }

        return in_array($permissionKey, $cache[$role], true);
    }
}
