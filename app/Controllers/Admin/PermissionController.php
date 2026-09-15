<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\View;
use App\Models\RolePermissionModel;

/**
 * Matrice dei permessi per ruolo (tabella `role_permissions`).
 *
 * L'accesso e' riservato al ruolo `admin` e non a un permesso configurabile:
 * un permesso che si puo' togliere da questa stessa pagina lascerebbe la
 * piattaforma senza nessuno in grado di rimetterlo.
 */
class PermissionController extends AdminController
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin');

        View::render('admin/permissions/index', [
            'pageTitle' => 'Permessi',
            'catalog' => RolePermissionModel::catalog(),
            'matrix' => RolePermissionModel::matrix(),
            'roles' => Auth::ROLES,
            'extraKeys' => RolePermissionModel::extraKeys(),
        ]);
    }

    public function update(array $params = []): void
    {
        Auth::requireRole('admin');

        $submitted = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];
        $catalogKeys = RolePermissionModel::catalogKeys();
        $stored = RolePermissionModel::matrix();

        foreach (Auth::ROLES as $role) {
            $keys = is_array($submitted[$role] ?? null) ? array_map('strval', $submitted[$role]) : [];

            // Si salvano solo chiavi note; quelle personalizzate aggiunte a mano
            // in tabella non compaiono nella matrice e vanno conservate.
            $keys = array_values(array_intersect($keys, $catalogKeys));
            $extra = array_values(array_diff($stored[$role] ?? [], $catalogKeys));

            RolePermissionModel::setForRole($role, array_merge($keys, $extra));
        }

        // Garanzia minima: senza user.manage l'admin non potrebbe piu' gestire
        // gli utenti, e nessun altro ruolo potrebbe restituirglielo.
        $adminKeys = RolePermissionModel::keysForRole('admin');

        if (!in_array('user.manage', $adminKeys, true)) {
            RolePermissionModel::setForRole('admin', array_merge($adminKeys, ['user.manage']));
            $_SESSION['flash_error'] = 'Il permesso "user.manage" è stato mantenuto per il ruolo admin: senza, il pannello utenti sarebbe irraggiungibile.';
        }

        Auth::flushPermissionCache();

        $this->success('Permessi aggiornati.', '/admin/permissions');
    }
}
