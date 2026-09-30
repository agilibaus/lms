<?php

declare(strict_types=1);

use App\Models\RolePermissionModel;
use App\Auth\Auth;
use App\Core\Csrf;

/** @var array<string, array<string, string>> $catalog */
/** @var array<string, string[]> $matrix */
/** @var string[] $roles */
/** @var string[] $extraKeys */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link">← Impostazioni</a>
    <h1>Permessi</h1>
    <p class="page-subtitle">
        I privilegi vivono nella tabella <code>role_permissions</code>: si cambiano da qui, senza toccare il codice.
        Questa pagina resta sempre riservata al ruolo <code>admin</code>.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/permissions" method="post">
    <?= Csrf::field() ?>

    <?php foreach ($catalog as $group => $permissions): ?>
        <section class="card">
            <h2><?= htmlspecialchars($group) ?></h2>
            <div class="table-scroll">
                <table class="data-table permission-matrix">
                    <thead>
                    <tr>
                        <th>Permesso</th>
                        <?php foreach ($roles as $role): ?>
                            <th><?= htmlspecialchars(Auth::roleLabel($role)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($permissions as $key => $label): ?>
                        <tr>
                            <td>
                                <code><?= htmlspecialchars($key) ?></code>
                                <span class="cell-sub"><?= htmlspecialchars($label) ?></span>
                            </td>
                            <?php foreach ($roles as $role): ?>
                                <td class="permission-cell">
                                    <?php if (RolePermissionModel::isForbidden($role, $key)): ?>
                                        <?php /* Vietato per regola: non e' una scelta della matrice. */ ?>
                                        <input type="checkbox" disabled
                                               aria-label="<?= htmlspecialchars($key . ' per ' . Auth::roleLabel($role) . ': non assegnabile') ?>"
                                               title="Non assegnabile: gli assistenti li gestisce solo l'admin">
                                    <?php else: ?>
                                    <input type="checkbox"
                                           name="permissions[<?= htmlspecialchars($role) ?>][]"
                                           value="<?= htmlspecialchars($key) ?>"
                                           aria-label="<?= htmlspecialchars($key . ' per ' . Auth::roleLabel($role)) ?>"
                                        <?= in_array($key, $matrix[$role] ?? [], true) ? 'checked' : '' ?>>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($extraKeys !== []): ?>
        <div class="alert alert-warning">
            In tabella ci sono permessi non previsti dal catalogo dell'applicazione
            (<?= htmlspecialchars(implode(', ', $extraKeys)) ?>): restano invariati e non vengono toccati da questo salvataggio.
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva permessi</button>
    </div>
</form>
