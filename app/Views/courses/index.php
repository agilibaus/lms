<?php

declare(strict_types=1);

use App\Core\CourseCover;

/** @var array $courses */
?>
<div class="page-header">
    <h1>Corsi</h1>
</div>

<?php if (empty($courses)): ?>
    <p class="empty-state">Nessun corso disponibile al momento.</p>
<?php else: ?>
    <div class="course-grid">
        <?php foreach ($courses as $course): ?>
            <a href="/courses/<?= (int) $course['id'] ?>" class="course-card">
                <div class="course-card-cover">
                    <?php $cover = CourseCover::url($course); ?>
                    <?php if ($cover !== null): ?>
                        <img src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars(CourseCover::altFor($course)) ?>" loading="lazy">
                    <?php else: ?>
                        <?php require __DIR__ . '/_cover_placeholder.php'; ?>
                    <?php endif; ?>
                </div>
                <div class="course-card-body">
                    <h3><?= htmlspecialchars($course['title']) ?></h3>
                    <?php if (!empty($course['description'])): ?>
                        <p class="course-card-excerpt">
                            <?= htmlspecialchars(mb_strimwidth($course['description'], 0, 90, '…')) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (isset($course['progress_pct'])): ?>
                        <div class="progress-bar">
                            <div class="progress-bar-fill" style="width: <?= (float) $course['progress_pct'] ?>%"></div>
                        </div>
                        <span class="progress-label"><?= (float) $course['progress_pct'] ?>% completato</span>
                    <?php elseif (!$course['is_published']): ?>
                        <span class="badge badge-muted">Bozza</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
