<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Csrf;
use App\Models\RolePermissionModel;
use App\Models\UserModel;

/**
 * Autenticazione, sessione utente e permessi per ruolo.
 * L'accesso ai dati passa sempre dai Model (nessuna query diretta qui).
 */
class Auth
{
    public const ROLES = ['admin', 'tutor', 'assistente', 'studente'];

    /** @var array<string, string[]> cache dei permessi per ruolo, valida per la singola richiesta */
    private static array $permissionCache = [];

    /** Motivo dell'ultimo tentativo di accesso fallito (per un messaggio utile). */
    private static ?string $failureReason = null;

    public const FAILURE_CREDENTIALS = 'credentials';
    public const FAILURE_UNVERIFIED = 'unverified';
    public const FAILURE_INACTIVE = 'inactive';

    public static function attempt(string $email, string $password): bool
    {
        $user = UserModel::findByEmail($email);
        self::$failureReason = null;

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::$failureReason = self::FAILURE_CREDENTIALS;

            return false;
        }

        // Le credenziali sono giuste: da qui i motivi del rifiuto si possono
        // dire senza rivelare nulla a chi sta tirando a indovinare.
        if (!(bool) $user['is_active']) {
            self::$failureReason = self::FAILURE_INACTIVE;

            return false;
        }

        if (($user['email_verified_at'] ?? null) === null) {
            self::$failureReason = self::FAILURE_UNVERIFIED;

            return false;
        }

        session_regenerate_id(true);
        Csrf::rotate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_email'] = $user['email'];

        return true;
    }

    public static function failureReason(): ?string
    {
        return self::$failureReason;
    }

    public static function logout(): void
    {
        Csrf::rotate();
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

    public static function email(): ?string
    {
        return $_SESSION['user_email'] ?? null;
    }

    /**
     * Percorso dell'immagine del profilo (relativo a /storage), oppure null.
     *
     * Tenuto in sessione perche' la barra laterale lo chiede a ogni pagina:
     * viene letto dal database una volta sola, alla prima richiesta della
     * sessione, cosi' anche le sessioni aperte prima di questa funzione
     * trovano l'immagine.
     */
    public static function avatar(): ?string
    {
        if (!self::check()) {
            return null;
        }

        if (!array_key_exists('user_avatar', $_SESSION)) {
            $user = UserModel::find((int) self::id());
            $_SESSION['user_avatar'] = $user['avatar_path'] ?? null;
        }

        return $_SESSION['user_avatar'];
    }

    /**
     * Allinea la sessione dopo che l'utente ha cambiato la propria immagine.
     */
    public static function setAvatar(?string $path): void
    {
        $_SESSION['user_avatar'] = $path;
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

        $role = self::role();

        if (!isset(self::$permissionCache[$role])) {
            self::$permissionCache[$role] = RolePermissionModel::keysForRole($role);
        }

        return in_array($permissionKey, self::$permissionCache[$role], true);
    }

    /**
     * Vero se l'utente ha almeno uno dei permessi indicati.
     */
    public static function canAny(string ...$permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if (self::can($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Richiede il login e almeno uno dei permessi indicati.
     */
    public static function requirePermission(string ...$permissionKeys): void
    {
        self::requireLogin();

        if (!self::canAny(...$permissionKeys)) {
            http_response_code(403);
            exit('Accesso negato: permessi insufficienti per questa azione.');
        }
    }

    /**
     * Svuota la cache dei permessi (dopo un salvataggio dal pannello admin).
     */
    public static function flushPermissionCache(): void
    {
        self::$permissionCache = [];
    }
}
