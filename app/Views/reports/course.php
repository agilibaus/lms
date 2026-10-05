<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Ordinamento;

/** @var array $course */
/** @var array $rows */
/** @var array{lessons: int, quizzes: int} $totals */

// «Lezioni» e «Quiz superati» mostrano «3/12»: si ordinano sul numero di
// sinistra, perche' il denominatore e' uguale per tutta la tabella.
$ordine = Ordinamento::daRichiesta([
    'studente' => ['full_name', Ordinamento::TESTO],
    'progresso' => ['progress_pct', Ordinamento::NUMERO],
    'lezioni' => ['lessons_completed', Ordinamento::NUMERO],
    'quiz' => ['quizzes_passed', Ordinamento::NUMERO],
    'certificato' => ['certificate_code', Ordinamento::TESTO],
]);
$rows = $ordine->applica($rows);
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $course['title']) ?></h1>
    <p class="page-subtitle">
        <?= (int) $totals['lessons'] ?> lezioni · <?= (int) $totals['quizzes'] ?> questionari ·
        <?= count($rows) ?> iscritti
    </p>
    <?php /* Stessa tendina dell'elenco dei report: XLSX prima perche' si apre
             con un doppio clic, CSV per chi deve darlo in pasto a un programma.
             `details`/`summary` funziona senza JavaScript. */ ?>
    <p>
        <details class="dropdown">
            <summary class="btn btn-secondary">Scarica</summary>
            <div class="dropdown-menu">
                <?php if (App\Core\Xlsx::disponibile()): ?>
                    <a href="/reports/courses/<?= (int) $course['id'] ?>/xlsx">XLSX</a>
                <?php endif; ?>
                <a href="/reports/courses/<?= (int) $course['id'] ?>/csv">CSV</a>
            </div>
        </details>
    </p>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if ($rows === []): ?>
    <p class="empty-state">Nessuno studente iscritto a questo corso.</p>
<?php else: ?>
    <?php /* Sei colonne su un telefono non ci stanno: sotto i 50 rem di
             spazio la tabella diventa un elenco di schede. I `role` espliciti
             servono perché cambiando il `display` la tabella perderebbe le
             proprie semantiche. */ ?>
    <div class="tabella-schede">
    <table class="data-table" role="table">
        <thead role="rowgroup">
        <tr role="row">
            <?= $ordine->th('Studente', 'studente') ?>
            <?= $ordine->th('Progresso', 'progresso') ?>
            <?= $ordine->th('Lezioni', 'lezioni') ?>
            <?= $ordine->th('Questionari superati', 'quiz') ?>
            <?= $ordine->th('Certificato', 'certificato') ?>
            <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
        </tr>
        </thead>
        <tbody role="rowgroup">
        <?php foreach ($rows as $row): ?>
            <tr role="row">
                <td role="cell" data-label="Studente">
                    <a href="/reports/students/<?= (int) $row['user_id'] ?>"><?= htmlspecialchars((string) $row['full_name']) ?></a>
                    <span class="cell-sub"><?= htmlspecialchars((string) $row['email']) ?></span>
                </td>
                <td role="cell" data-label="Progresso">
                    <?= number_format((float) $row['progress_pct'], 0) ?>%
                    <?php if ($row['completed_at'] !== null): ?>
                        <span class="badge badge-success">completato</span>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Lezioni"><?= (int) $row['lessons_completed'] ?>/<?= (int) $totals['lessons'] ?></td>
                <td role="cell" data-label="Questionari superati"><?= (int) $row['quizzes_passed'] ?>/<?= (int) $totals['quizzes'] ?></td>
                <td role="cell" data-label="Certificato">
                    <?php if (empty($row['certificate_code'])): ?>
                        —
                    <?php elseif ($row['certificate_revoked_at'] !== null): ?>
                        <span class="badge badge-danger">revocato</span>
                    <?php else: ?>
                        <code><?= htmlspecialchars((string) $row['certificate_code']) ?></code>
                    <?php endif; ?>
                </td>
                <td role="cell" class="row-actions">
                    <?php if (Auth::can('certificate.issue') && empty($row['certificate_code'])): ?>
                        <form action="/certificates/issue" method="post">
    <?= Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                            <input type="hidden" name="redirect_to" value="/reports/courses/<?= (int) $course['id'] ?>">
                            <button type="submit" class="link-btn">Emetti certificato</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<script src="/assets/js/dropdown.js"></script>
