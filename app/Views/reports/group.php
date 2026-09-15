<?php

declare(strict_types=1);

/** @var array $group */
/** @var array $courses */
/** @var array $members */
/** @var array $rows */
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $group['name']) ?></h1>
    <p class="page-subtitle">
        Tutor: <?= htmlspecialchars((string) ($group['tutor_name'] ?? '—')) ?> ·
        <?= count($members) ?> membri · <?= count($courses) ?> corsi assegnati
    </p>
    <p><a href="/reports/groups/<?= (int) $group['id'] ?>/csv" class="btn btn-secondary">Esporta CSV</a></p>
</div>

<?php if ($courses === []): ?>
    <p class="empty-state">
        Nessun corso assegnato a questo gruppo: non c'è avanzamento da mostrare.
    </p>
<?php elseif ($rows === []): ?>
    <p class="empty-state">Questo gruppo non ha ancora membri.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
        <tr><th>Studente</th><th>Corso</th><th>Progresso</th><th>Quiz superati</th><th>Certificato</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>
                    <a href="/reports/students/<?= (int) $row['user_id'] ?>"><?= htmlspecialchars((string) $row['full_name']) ?></a>
                    <span class="cell-sub"><?= htmlspecialchars((string) $row['email']) ?></span>
                </td>
                <td><?= htmlspecialchars((string) $row['course_title']) ?></td>
                <td>
                    <?php if ($row['progress_pct'] === null): ?>
                        <span class="badge">non iscritto</span>
                    <?php else: ?>
                        <?= number_format((float) $row['progress_pct'], 0) ?>%
                        <?php if ($row['completed_at'] !== null): ?>
                            <span class="badge badge-success">completato</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?= (int) $row['quizzes_passed'] ?>/<?= (int) $row['quizzes_total'] ?></td>
                <td>
                    <?php if (empty($row['certificate_code'])): ?>
                        —
                    <?php elseif ($row['certificate_revoked_at'] !== null): ?>
                        <span class="badge badge-danger">revocato</span>
                    <?php else: ?>
                        <code><?= htmlspecialchars((string) $row['certificate_code']) ?></code>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
