<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Controllers\ReportController;

/** @var array $student */
/** @var array $courses */
/** @var array<int, array> $quizzesByCourse */
/** @var array{attended: int, total: int} $liveAttendance */
/** @var array $liveSessions */
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $student['full_name']) ?></h1>
    <p class="page-subtitle"><?= htmlspecialchars((string) $student['email']) ?> · <?= htmlspecialchars(Auth::roleLabel((string) $student['role'])) ?></p>
    <?php if ($liveAttendance['total'] > 0): ?>
        <p class="page-subtitle">
            Sessioni live seguite: <?= (int) $liveAttendance['attended'] ?>/<?= (int) $liveAttendance['total'] ?>
        </p>
    <?php endif; ?>
    <?php /* Stessa tendina dell'elenco dei report: XLSX prima perche' si apre
             con un doppio clic, CSV per chi deve darlo in pasto a un programma.
             `details`/`summary` funziona senza JavaScript. */ ?>
    <p>
        <details class="dropdown">
            <summary class="btn btn-secondary">Scarica</summary>
            <div class="dropdown-menu">
                <?php if (App\Core\Xlsx::disponibile()): ?>
                    <a href="/reports/students/<?= (int) $student['id'] ?>/xlsx">XLSX</a>
                <?php endif; ?>
                <a href="/reports/students/<?= (int) $student['id'] ?>/csv">CSV</a>
            </div>
        </details>
    </p>
</div>

<?php if ($courses === []): ?>
    <p class="empty-state">Questo utente non è iscritto ad alcun corso.</p>
<?php else: ?>
    <?php foreach ($courses as $course): ?>
        <section class="card">
            <div class="card-head">
                <h2><a href="/reports/courses/<?= (int) $course['course_id'] ?>"><?= htmlspecialchars((string) $course['course_title']) ?></a></h2>
                <span>
                    <?= number_format((float) $course['progress_pct'], 0) ?>%
                    <?php if ($course['completed_at'] !== null): ?>
                        <span class="badge badge-success">completato</span>
                    <?php endif; ?>
                </span>
            </div>

            <p class="card-meta">
                Lezioni <?= (int) $course['lessons_completed'] ?>/<?= (int) $course['lessons_total'] ?> ·
                Quiz superati <?= (int) $course['quizzes_passed'] ?>/<?= (int) $course['quizzes_total'] ?> ·
                Iscritto il <?= htmlspecialchars((string) $course['enrolled_at']) ?>
                <?php if (!empty($course['certificate_code'])): ?>
                    · Certificato
                    <?php if ($course['certificate_revoked_at'] !== null): ?>
                        <span class="badge badge-danger">revocato</span>
                    <?php else: ?>
                        <code><?= htmlspecialchars((string) $course['certificate_code']) ?></code>
                    <?php endif; ?>
                <?php endif; ?>
            </p>

            <?php $quizzes = $quizzesByCourse[$course['course_id']] ?? []; ?>

            <?php if ($quizzes !== []): ?>
                <?php /* Sei colonne: sotto i 50 rem di spazio diventa un
                         elenco di schede (vedi `.tabella-schede`). */ ?>
                <div class="tabella-schede">
                <table class="data-table" role="table">
                    <thead role="rowgroup">
                    <tr role="row">
                        <th scope="col" role="columnheader">Quiz</th>
                        <th scope="col" role="columnheader">Modulo</th>
                        <th scope="col" role="columnheader">Tentativi</th>
                        <th scope="col" role="columnheader">Miglior punteggio</th>
                        <th scope="col" role="columnheader">Esito</th>
                        <th scope="col" role="columnheader">Ultimo tentativo</th>
                    </tr>
                    </thead>
                    <tbody role="rowgroup">
                    <?php foreach ($quizzes as $quiz): ?>
                        <tr role="row">
                            <td role="cell" data-label="Quiz"><?= htmlspecialchars((string) $quiz['quiz_title']) ?></td>
                            <td role="cell" data-label="Modulo"><?= htmlspecialchars((string) $quiz['module_title']) ?></td>
                            <td role="cell" data-label="Tentativi"><?= (int) $quiz['attempts'] ?></td>
                            <td role="cell" data-label="Miglior punteggio"><?= $quiz['best_score_pct'] === null ? '—' : number_format((float) $quiz['best_score_pct'], 0) . '%' ?></td>
                            <td role="cell" data-label="Esito">
                                <?php if ((int) $quiz['attempts'] === 0): ?>
                                    <span class="badge">non svolto</span>
                                <?php elseif ((int) $quiz['passed'] === 1): ?>
                                    <span class="badge badge-success">superato</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">non superato</span>
                                <?php endif; ?>
                            </td>
                            <td role="cell" data-label="Ultimo tentativo">
                                <?php /* Il collegamento all'ultimo tentativo e'
                                         l'unico modo per arrivare alle risposte
                                         aperte, che nessuno puo' correggere a
                                         macchina e qualcuno deve leggere. */ ?>
                                <?php if (!empty($quiz['last_attempt_id'])): ?>
                                    <a href="/attempts/<?= (int) $quiz['last_attempt_id'] ?>">
                                        <?= htmlspecialchars((string) ($quiz['last_attempt_at'] ?? '—')) ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($liveSessions !== []): ?>
    <section class="card">
        <h2>Incontri dal vivo</h2>
        <p class="card-meta">
            Gli incontri a cui questo studente era atteso. L’ingresso è quello registrato quando
            ha aperto la riunione da Pistacchio; quanto sia rimasto in riunione non lo sappiamo,
            perché l’uscita avviene dentro Google Meet.
        </p>

        <?php /* Sei colonne anche qui: stesso trattamento della tabella dei
                 quiz qui sopra. */ ?>
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <th scope="col" role="columnheader">Incontro</th>
                <th scope="col" role="columnheader">Quando</th>
                <th scope="col" role="columnheader">Corso o gruppo</th>
                <th scope="col" role="columnheader">Presenza</th>
                <th scope="col" role="columnheader">Ingresso</th>
                <th scope="col" role="columnheader">Ritardo</th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($liveSessions as $incontro): ?>
                <?php
                $presente = $incontro['joined_at'] !== null;
                $ritardo = ReportController::delayLabel($incontro);
                ?>
                <tr role="row">
                    <td role="cell" data-label="Incontro"><a href="/reports/live/<?= (int) $incontro['id'] ?>"><?= htmlspecialchars((string) $incontro['title']) ?></a></td>
                    <td role="cell" data-label="Quando"><?= htmlspecialchars(ReportController::dateTimeLabel((string) $incontro['starts_at'])) ?></td>
                    <td role="cell" data-label="Corso o gruppo"><?= htmlspecialchars((string) ($incontro['course_title'] ?? $incontro['group_name'] ?? '—')) ?></td>
                    <td role="cell" data-label="Presenza">
                        <?php if ($presente): ?>
                            <span class="badge badge-success">presente</span>
                        <?php else: ?>
                            <span class="badge badge-danger">assente</span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" data-label="Ingresso"><?= $presente ? htmlspecialchars(ReportController::dateTimeLabel($incontro['joined_at'])) : '—' ?></td>
                    <td role="cell" data-label="Ritardo">
                        <?= $presente && $ritardo !== '' && $ritardo !== '0'
                            ? htmlspecialchars($ritardo) . ' min'
                            : ($presente ? 'in orario' : '—') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<script src="/assets/js/dropdown.js"></script>
