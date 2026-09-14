<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\RolePermissionModel;
use App\Models\UserModel;

/**
 * Autenticazione, sessione utente e permessi per ruolo.
 * L'accesso ai dati passa sempre dai Model (nessuna query diretta qui).
 */
class Auth
{
    public const ROLES = ['admin', 'tutor', 'assistente', 'studente'];

    public static function attempt(string $email, string $password): bool
    {
        $user = UserModel::findByEmail($email);

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
            $cache[$role] = RolePermissionModel::keysForRole($role);
        }

        return in_array($permissionKey, $cache[$role], true);
    }
}
