<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Auth\Ruoli;
use App\Core\Settings;
use App\Core\View;

/**
 * Impostazioni › Ruoli (26/10, Alessandro; fino al 26/10 «Permessi», la
 * matrice della tabella `role_permissions`). Che cosa fa ogni ruolo: il
 * nucleo fisso, descritto, e i cinque interruttori di `Ruoli`, che l'admin
 * accende e spegne.
 *
 * L'accesso e' riservato al ruolo `admin`, non a un permesso: nessun
 * interruttore lo tocca.
 */
class PermissionController extends AdminController
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin');

        $stato = [];

        foreach (array_keys(Ruoli::INTERRUTTORI) as $chiave) {
            // La casella mostra la scelta salvata, non l'effetto: «Tentativi»
            // resta spuntato anche con «Report» spento, e la pagina dice che
            // vale solo con «Report» acceso.
            $stato[$chiave] = Settings::get($chiave, '1') !== '0';
        }

        View::render('admin/permissions/index', [
            'pageTitle' => 'Ruoli',
            'stato' => $stato,
        ]);
    }

    public function update(array $params = []): void
    {
        Auth::requireRole('admin');

        $scelte = is_array($_POST['ruoli'] ?? null) ? $_POST['ruoli'] : [];

        foreach (array_keys(Ruoli::INTERRUTTORI) as $chiave) {
            Settings::set($chiave, ($scelte[$chiave] ?? '0') === '1' ? '1' : '0', (int) Auth::id());
        }

        Auth::flushPermissionCache();

        $this->success('Ruoli aggiornati.', '/admin/permissions');
    }
}
