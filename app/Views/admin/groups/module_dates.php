<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * «Date dei moduli» di un gruppo in un corso (26/10, Alessandro, conflitto
 * 3; mockup approvato). Una riga per modulo: la data del corso e quella del
 * gruppo, da scrivere. Campo vuoto = il gruppo segue la data del corso.
 *
 * @var array<string, mixed> $group
 * @var array<string, mixed> $course
 * @var list<array<string, mixed>> $modules
 * @var array<int, string> $dates chiave: id del modulo
 */

$groupId = (int) $group['id'];
$courseId = (int) $course['id'];
// Dalla tabella "2026-11-15 09:00:00" al campo "2026-11-15T09:00".
$campo = static fn (?string $d): string => $d === null ? '' : str_replace(' ', 'T', substr($d, 0, 16));
?>
<div class="page-header">
    <a href="/admin/groups/<?= $groupId ?>/edit" class="back-link"><span class="back-link-testo"><?= htmlspecialchars((string) $group['name']) ?></span></a>
    <h1>Date dei moduli</h1>
    <p class="page-subtitle"><?= htmlspecialchars((string) $course['title']) ?> · gruppo <?= htmlspecialchars((string) $group['name']) ?></p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<section class="card">
    <form action="/admin/groups/<?= $groupId ?>/courses/<?= $courseId ?>/date" method="post" class="form">
        <?= Csrf::field() ?>
        <p class="form-hint">
            Lascia vuoto per seguire l’avanzamento del corso. Con una data, per gli studenti di questo gruppo il
            modulo si apre quel giorno, e ricevono l’email dell’apertura.
        </p>

        <?php if ($modules === []): ?>
            <p class="empty-state-small">Il corso non ha ancora moduli.</p>
        <?php else: ?>
            <div class="tabella-schede">
            <table class="data-table" role="table">
                <thead role="rowgroup">
                <tr role="row">
                    <th scope="col" role="columnheader">Modulo</th>
                    <th scope="col" role="columnheader">Data del corso</th>
                    <th scope="col" role="columnheader">Data del gruppo</th>
                </tr>
                </thead>
                <tbody role="rowgroup">
                <?php foreach ($modules as $m): ?>
                    <?php
                    $moduleId = (int) $m['id'];
                    $delCorso = $m['available_from'] === null
                        ? 'aperto da subito'
                        : date('j/n/Y \a\l\l\e G:i', strtotime((string) $m['available_from']));
                    ?>
                    <tr role="row">
                        <td role="cell" data-label="Modulo"><?= htmlspecialchars((string) $m['title']) ?></td>
                        <td role="cell" data-label="Data del corso"><?= htmlspecialchars($delCorso) ?></td>
                        <td role="cell" data-label="Data del gruppo">
                            <input type="datetime-local" name="date[<?= $moduleId ?>]"
                                   value="<?= htmlspecialchars($campo($dates[$moduleId] ?? null)) ?>"
                                   aria-label="Data del gruppo per <?= htmlspecialchars((string) $m['title']) ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
            <a href="/admin/groups/<?= $groupId ?>/edit" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</section>
