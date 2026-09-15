<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $users */
/** @var bool $canManageAll */
?>
<div class="page-header">
    <h1>Utenti</h1>
    <p class="page-subtitle">
        <?= $canManageAll
            ? 'Creazione utenti, ruoli, tutor di riferimento e password.'
            : 'Gli assistenti assegnati a te.' ?>
    </p>
    <p><a href="/admin/users/create" class="btn btn-primary">+ Nuovo utente</a></p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($users === []): ?>
    <p class="empty-state">Nessun utente da mostrare.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
        <tr><th>Nome</th><th>Email</th><th>Ruolo</th><th>Tutor</th><th>Stato</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars((string) $user['full_name']) ?></td>
                <td><?= htmlspecialchars((string) $user['email']) ?></td>
                <td><?= htmlspecialchars((string) ($user['role'] ?? 'assistente')) ?></td>
                <td><?= htmlspecialchars((string) ($user['supervising_tutor_name'] ?? '—')) ?></td>
                <td>
                    <?php if ((int) $user['is_active'] === 1): ?>
                        <span class="badge badge-success">attivo</span>
                    <?php else: ?>
                        <span class="badge badge-danger">disattivato</span>
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <a href="/admin/users/<?= (int) $user['id'] ?>/edit">Modifica</a>
                    <form action="/admin/users/<?= (int) $user['id'] ?>/delete" method="post"
                          onsubmit="return confirm('Eliminare questo utente? Iscrizioni, progressi, tentativi quiz e certificati verranno rimossi. In alternativa puoi disattivarlo.');">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-btn link-btn-danger">Elimina</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
