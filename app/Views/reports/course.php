<?php

declare(strict_types=1);

use App\Auth\Auth;

/** @var array $course */
/** @var array $rows */
/** @var array{lessons: int, quizzes: int} $totals */
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $course['title']) ?></h1>
    <p class="page-subtitle">
        <?= (int) $totals['lessons'] ?> lezioni · <?= (int) $totals['quizzes'] ?> quiz ·
        <?= count($rows) ?> iscritti
    </p>
    <p><a href="/reports/courses/<?= (int) $course['id'] ?>/csv" class="btn btn-secondary">Esporta CSV</a></p>
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
    <table class="data-table">
        <thead>
        <tr>
            <th>Studente</th>
            <th>Progresso</th>
            <th>Lezioni</th>
            <th>Quiz superati</th>
            <th>Certificato</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>
                    <a href="/reports/students/<?= (int) $row['user_id'] ?>"><?= htmlspecialchars((string) $row['full_name']) ?></a>
                    <span class="cell-sub"><?= htmlspecialchars((string) $row['email']) ?></span>
                </td>
                <td>
                    <?= number_format((float) $row['progress_pct'], 0) ?>%
                    <?php if ($row['completed_at'] !== null): ?>
                        <span class="badge badge-success">completato</span>
                    <?php endif; ?>
                </td>
                <td><?= (int) $row['lessons_completed'] ?>/<?= (int) $totals['lessons'] ?></td>
                <td><?= (int) $row['quizzes_passed'] ?>/<?= (int) $totals['quizzes'] ?></td>
                <td>
                    <?php if (empty($row['certificate_code'])): ?>
                        —
                    <?php elseif ($row['certificate_revoked_at'] !== null): ?>
                        <span class="badge badge-danger">revocato</span>
                    <?php else: ?>
                        <code><?= htmlspecialchars((string) $row['certificate_code']) ?></code>
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <?php if (Auth::hasRole('admin', 'tutor') && empty($row['certificate_code'])): ?>
                        <form action="/certificates/issue" method="post">
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
<?php endif; ?>
