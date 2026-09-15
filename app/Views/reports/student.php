<?php

declare(strict_types=1);

/** @var array $student */
/** @var array $courses */
/** @var array<int, array> $quizzesByCourse */
/** @var array{attended: int, total: int} $liveAttendance */
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $student['full_name']) ?></h1>
    <p class="page-subtitle"><?= htmlspecialchars((string) $student['email']) ?> · <?= htmlspecialchars((string) $student['role']) ?></p>
    <?php if ($liveAttendance['total'] > 0): ?>
        <p class="page-subtitle">
            Sessioni live seguite: <?= (int) $liveAttendance['attended'] ?>/<?= (int) $liveAttendance['total'] ?>
        </p>
    <?php endif; ?>
    <p><a href="/reports/students/<?= (int) $student['id'] ?>/csv" class="btn btn-secondary">Esporta CSV</a></p>
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
                <table class="data-table">
                    <thead>
                    <tr><th>Quiz</th><th>Modulo</th><th>Tentativi</th><th>Miglior punteggio</th><th>Esito</th><th>Ultimo tentativo</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($quizzes as $quiz): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $quiz['quiz_title']) ?></td>
                            <td><?= htmlspecialchars((string) $quiz['module_title']) ?></td>
                            <td><?= (int) $quiz['attempts'] ?></td>
                            <td><?= $quiz['best_score_pct'] === null ? '—' : number_format((float) $quiz['best_score_pct'], 0) . '%' ?></td>
                            <td>
                                <?php if ((int) $quiz['attempts'] === 0): ?>
                                    <span class="badge">non svolto</span>
                                <?php elseif ((int) $quiz['passed'] === 1): ?>
                                    <span class="badge badge-success">superato</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">non superato</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($quiz['last_attempt_at'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
