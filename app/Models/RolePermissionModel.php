<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `role_permissions`.
 * I permessi sono configurabili a runtime (nessun privilegio hardcodato nel codice).
 */
class RolePermissionModel
{
    /**
     * @return string[] elenco dei permission_key assegnati al ruolo indicato
     */
    public static function keysForRole(string $role): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT permission_key FROM role_permissions WHERE role = :role'
        );
        $stmt->execute(['role' => $role]);

        return array_column($stmt->fetchAll(), 'permission_key');
    }
}
