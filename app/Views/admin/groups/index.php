<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\GroupLogo;
use App\Core\Ordinamento;

/** @var array $groups */
/** @var bool $canManageAll */

$ordine = Ordinamento::daRichiesta([
    'gruppo' => ['name', Ordinamento::TESTO],
    'tutor' => ['tutor_name', Ordinamento::TESTO],
    'membri' => ['member_count', Ordinamento::NUMERO],
]);
$groups = $ordine->applica($groups);
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
    <?php /* Quattro colonne e un logo: a 320 px non ci sta. Sotto i 50 rem
             di spazio diventa un elenco di schede. */ ?>
    <div class="tabella-schede">
    <table class="data-table data-table-media" role="table">
        <thead role="rowgroup">
        <tr role="row">
            <?= $ordine->th('Gruppo', 'gruppo') ?>
            <?= $ordine->th('Tutor', 'tutor') ?>
            <?= $ordine->th('Membri', 'membri') ?>
            <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
        </tr>
        </thead>
        <tbody role="rowgroup">
        <?php foreach ($groups as $group): ?>
            <tr role="row">
                <td role="cell" data-label="Gruppo">
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
                <td role="cell" data-label="Tutor"><?= htmlspecialchars((string) ($group['tutor_name'] ?? '—')) ?></td>
                <td role="cell" data-label="Membri"><?= (int) $group['member_count'] ?></td>
                <td role="cell" class="row-actions">
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
    </div>
<?php endif; ?>
