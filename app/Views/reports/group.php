<?php

declare(strict_types=1);

use App\Core\Ordinamento;

/** @var array $group */
/** @var array $courses */
/** @var array $members */
/** @var array $rows */

$ordine = Ordinamento::daRichiesta([
    'studente' => ['full_name', Ordinamento::TESTO],
    'corso' => ['course_title', Ordinamento::TESTO],
    // Chi non e' iscritto a quel corso non ha una percentuale: quelle
    // righe finiscono in fondo in tutti e due i versi.
    'progresso' => ['progress_pct', Ordinamento::NUMERO],
    'quiz' => ['quizzes_passed', Ordinamento::NUMERO],
    'certificato' => ['certificate_code', Ordinamento::TESTO],
]);
$rows = $ordine->applica($rows);
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $group['name']) ?></h1>
    <p class="page-subtitle">
        Tutor: <?= htmlspecialchars((string) ($group['tutor_name'] ?? '—')) ?> ·
        <?= count($members) ?> membri · <?= count($courses) ?> corsi assegnati
    </p>
    <?php /* Stessa tendina dell'elenco dei report: XLSX prima perche' si apre
             con un doppio clic, CSV per chi deve darlo in pasto a un programma.
             `details`/`summary` funziona senza JavaScript. */ ?>
    <p>
        <details class="dropdown">
            <summary class="btn btn-secondary">Scarica</summary>
            <div class="dropdown-menu">
                <?php if (App\Core\Xlsx::disponibile()): ?>
                    <a href="/reports/groups/<?= (int) $group['id'] ?>/xlsx">XLSX</a>
                <?php endif; ?>
                <a href="/reports/groups/<?= (int) $group['id'] ?>/csv">CSV</a>
            </div>
        </details>
    </p>
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
        <tr>
            <?= $ordine->th('Studente', 'studente') ?>
            <?= $ordine->th('Corso', 'corso') ?>
            <?= $ordine->th('Progresso', 'progresso') ?>
            <?= $ordine->th('Quiz superati', 'quiz') ?>
            <?= $ordine->th('Certificato', 'certificato') ?>
        </tr>
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

<script src="/assets/js/dropdown.js"></script>
