<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\GroupLogo;
use App\Core\Ordinamento;
use App\Core\Icona;

/** @var array $groups */
/** @var bool $canManageAll */
/** @var bool $canDelete solo l'admin elimina i gruppi (06/11) */
/** @var list<int> $pagineApribili i gruppi di cui si apre la pagina dei partecipanti (06/11) */

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
    <?php /* Crea i gruppi chi li gestisce tutti: l'admin e, dal 06/11, il
             supervisore. Elimina solo l'admin. */ ?>
    <?php if ($canManageAll): ?>
        <p><a href="/admin/groups/create" class="btn btn-primary btn-con-icona"><?= Icona::svg('plus-lg') ?>Nuovo gruppo</a></p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($groups === []): ?>
    <p class="empty-state">Nessun gruppo.</p>
<?php else: ?>
    <?php /* Quattro colonne e un logo: a 320 px non ci sta. Sopra i 50 rem
             di spazio la tabella, ordinabile. Sotto (il telefono, 30/10,
             Alessandro, variante C) un elenco a parte, `gruppi-righe`, piu'
             sotto: una riga per gruppo, come una rubrica, e i comandi nel
             menu «⋯». Lo spazio di `gruppi-elenco` decide quale si vede. */ ?>
    <?php /* Nel riquadro bianco, come i report (30/10, Alessandro): ogni elenco sta in un riquadro. */ ?>
    <div class="gruppi-elenco card">
    <div class="tabella-schede gruppi-tabella">
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
                    <?php /* La pagina del gruppo, quella che vedono i partecipanti
                             (12/10): il tutor e l'admin non ne sono membri, e
                             da qui e' l'unica strada per arrivarci. */ ?>
                    <?php if (in_array((int) $group['id'], $pagineApribili ?? [], true)): ?>
                        <a href="/gruppi/<?= (int) $group['id'] ?>">Vai al gruppo</a>
                    <?php endif; ?>
                    <a href="/reports/groups/<?= (int) $group['id'] ?>" class="comando-con-icona"><?= Icona::svg('bar-chart') ?>Report</a>
                    <?php if ($canDelete ?? false): ?>
                        <form action="/admin/groups/<?= (int) $group['id'] ?>/delete" method="post"
                              onsubmit="return confirm('Eliminare questo gruppo? Le iscrizioni ai corsi restano attive.');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?>Elimina</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php
    // Il telefono: in ordine alfabetico, senza i comandi per ordinare.
    $alfabetico = $groups;
    usort($alfabetico, static fn (array $a, array $b): int => strcmp(
        mb_strtolower((string) $a['name']),
        mb_strtolower((string) $b['name'])
    ));
    ?>
    <div class="gruppi-righe">
        <p class="card-meta gruppi-aiuto">
            <?php
            // Quello che c'e' davvero nel menu «⋯» (06/11): la pagina del
            // gruppo solo per chi la apre, l'eliminazione solo per l'admin.
            $voci = array_merge(
                ($pagineApribili ?? []) !== [] ? ['la pagina del gruppo'] : [],
                ['il report'],
                ($canDelete ?? false) ? ['l’eliminazione'] : []
            );
            $ultima = array_pop($voci);
            ?>
            Tocca un gruppo per i dettagli; «⋯» per <?= htmlspecialchars(($voci === [] ? '' : implode(', ', $voci) . ' e ') . $ultima) ?>.
        </p>
        <ul class="gruppi-lista">
            <?php foreach ($alfabetico as $group): ?>
                <?php
                $id = (int) $group['id'];
                $nome = (string) $group['name'];
                $membri = (int) $group['member_count'];
                $tutor = $group['tutor_name'] ?? null;
                $logo = GroupLogo::url($group);
                ?>
                <li class="gruppi-riga">
                    <a href="/admin/groups/<?= $id ?>/edit" class="gruppi-riga-nome">
                        <?php if ($logo !== null): ?>
                            <img src="<?= htmlspecialchars($logo) ?>" alt="" class="group-logo" loading="lazy">
                        <?php else: ?>
                            <span class="group-logo group-logo-placeholder"
                                  style="--logo-hue: <?= GroupLogo::hue($id) ?>;" aria-hidden="true">
                                <?= htmlspecialchars(GroupLogo::initials($nome)) ?>
                            </span>
                        <?php endif; ?>
                        <span>
                            <span class="gruppi-riga-titolo"><?= htmlspecialchars($nome) ?></span>
                            <span class="card-meta gruppi-riga-dati">
                                <?= $tutor === null || $tutor === '' ? 'Senza tutor' : 'Tutor: ' . htmlspecialchars((string) $tutor) ?>
                                · <?= $membri === 1 ? '1 membro' : $membri . ' membri' ?>
                            </span>
                        </span>
                    </a>
                    <details class="dropdown gruppi-azioni">
                        <summary class="btn btn-secondary" aria-label="Altre azioni per <?= htmlspecialchars($nome) ?>"><span aria-hidden="true">⋯</span></summary>
                        <div class="dropdown-menu dropdown-menu-destra">
                            <?php if (in_array((int) $id, $pagineApribili ?? [], true)): ?>
                                <a href="/gruppi/<?= $id ?>" class="comando-con-icona"><?= Icona::svg('box-arrow-up-right') ?>Pagina del gruppo</a>
                            <?php endif; ?>
                            <a href="/reports/groups/<?= $id ?>" class="comando-con-icona"><?= Icona::svg('bar-chart') ?>Report</a>
                            <?php if ($canDelete ?? false): ?>
                                <form action="/admin/groups/<?= $id ?>/delete" method="post"
                                      onsubmit="return confirm('Eliminare questo gruppo? Le iscrizioni ai corsi restano attive.');">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?>Elimina</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    </div>
    <script src="/assets/js/dropdown.js"></script>
<?php endif; ?>
