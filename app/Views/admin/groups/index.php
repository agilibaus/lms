<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\GroupLogo;

/** @var array $groups */
/** @var bool $canManageAll */
?>
<div class="page-header">
    <h1>Gruppi</h1>
    <p class="page-subtitle">
        <?= $canManageAll
            ? 'Classi e coorti: membri e corsi assegnati all’intero gruppo. Clicca sul nome del gruppo per visualizzare i dettagli.'
            : 'I gruppi di cui sei tutor. Clicca sul nome del gruppo per visualizzare i dettagli.' ?>
    </p>
    <p><a href="/admin/groups/create" class="btn btn-primary">+ Nuovo gruppo</a></p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($groups === []): ?>
    <p class="empty-state">Nessun gruppo.</p>
<?php else: ?>
    <table class="data-table data-table-media">
        <thead>
        <tr><th>Gruppo</th><th>Tutor</th><th>Membri</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
            <tr>
                <td>
                    <a href="/admin/groups/<?= (int) $group['id'] ?>/edit" class="group-name">
                        <?php $logo = GroupLogo::url($group); ?>
                        <?php if ($logo !== null): ?>
                            <img src="<?= htmlspecialchars($logo) ?>" alt="" class="group-logo" loading="lazy">
                        <?php else: ?>
                            <span class="group-logo group-logo-placeholder"
                                  style="--logo-hue: <?= GroupLogo::hue((int) $group['id']) ?>;" aria-hidden="true">
                                <?= htmlspecialchars(GroupLogo::initials((string) $group['name'])) ?>
                            </span>
                        <?php endif; ?>
                        <?= htmlspecialchars((string) $group['name']) ?>
                    </a>
                </td>
                <td><?= htmlspecialchars((string) ($group['tutor_name'] ?? '—')) ?></td>
                <td><?= (int) $group['member_count'] ?></td>
                <td class="row-actions">
                    <a href="/reports/groups/<?= (int) $group['id'] ?>">Report</a>
                    <form action="/admin/groups/<?= (int) $group['id'] ?>/delete" method="post"
                          onsubmit="return confirm('Eliminare questo gruppo? Le iscrizioni ai corsi restano attive.');">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-btn link-btn-danger">Elimina</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
