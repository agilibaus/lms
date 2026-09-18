<?php

declare(strict_types=1);

use App\Controllers\ReportController;

/** @var array $session */
/** @var array $rows */
/** @var bool $restricted */

$id = (int) $session['id'];
$inizio = new DateTimeImmutable((string) $session['starts_at']);
$fine = new DateTimeImmutable((string) $session['ends_at']);
$presenti = count(array_filter($rows, static fn (array $r): bool => $r['joined_at'] !== null));
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $session['title']) ?></h1>
    <p class="page-subtitle">
        <?= htmlspecialchars($inizio->format('d/m/Y H:i')) ?>–<?= htmlspecialchars($fine->format('H:i')) ?>
        <?php if (!empty($session['course_title'])): ?>
            · <?= htmlspecialchars((string) $session['course_title']) ?> / <?= htmlspecialchars((string) $session['module_title']) ?>
        <?php endif; ?>
        <?php if (!empty($session['group_name'])): ?>
            · Gruppo <?= htmlspecialchars((string) $session['group_name']) ?>
        <?php endif; ?>
    </p>
    <p><a href="/reports/live/<?= $id ?>/csv" class="btn btn-secondary">Esporta CSV</a></p>
</div>

<section class="card">
    <h2>Presenze: <?= $presenti ?> su <?= count($rows) ?> attesi</h2>
    <p class="card-meta">
        L’ingresso viene registrato quando qualcuno apre la riunione da Pistacchio, dalla pagina
        dell’incontro o dalla lezione. Quanto ciascuno sia poi rimasto in riunione non lo sappiamo:
        l’uscita avviene dentro Google Meet, che non ce lo comunica.
        Le presenze si correggono dalla <a href="/live/<?= $id ?>">pagina dell’incontro</a>.
    </p>

    <?php if ($rows === []): ?>
        <p class="empty-state-small">
            <?= $restricted
                ? 'Nessuno dei partecipanti a questo incontro è fra gli studenti dei gruppi che segui.'
                : 'Nessun partecipante atteso: il modulo o il gruppo collegati non hanno iscritti.' ?>
        </p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Partecipante</th><th>Presenza</th><th>Ingresso</th><th>Ritardo</th><th>Origine</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $presente = $row['joined_at'] !== null; ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) $row['full_name']) ?>
                        <span class="cell-sub"><?= htmlspecialchars((string) $row['email']) ?></span>
                    </td>
                    <td>
                        <?php if ($presente): ?>
                            <span class="badge badge-success">presente</span>
                        <?php else: ?>
                            <span class="badge badge-danger">assente</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $presente ? htmlspecialchars(ReportController::dateTimeLabel($row['joined_at'])) : '—' ?></td>
                    <td>
                        <?php $ritardo = ReportController::delayLabel($row); ?>
                        <?= $presente && $ritardo !== '' && $ritardo !== '0'
                            ? htmlspecialchars($ritardo) . ' min'
                            : ($presente ? 'in orario' : '—') ?>
                    </td>
                    <td><?= $presente ? htmlspecialchars(ReportController::sourceLabel($row['source'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
