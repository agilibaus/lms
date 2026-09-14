<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\VideoEmbed;

/** @var array $lesson */
/** @var array $module */
/** @var array $course */
/** @var array $materials */
/** @var bool $completed */
?>
<div class="page-header">
    <a href="/courses/<?= (int) $course['id'] ?>" class="back-link">
        &larr; <?= htmlspecialchars($course['title']) ?> &mdash; <?= htmlspecialchars($module['title']) ?>
    </a>
    <h1><?= htmlspecialchars($lesson['title']) ?></h1>
</div>

<?php if (Auth::hasRole('admin', 'tutor')): ?>
    <p><a href="/lessons/<?= (int) $lesson['id'] ?>/edit" class="btn btn-primary">Modifica lezione</a></p>
<?php endif; ?>

<?php $embed = VideoEmbed::render($lesson['video_provider'], $lesson['video_ref'], (int) $lesson['id']); ?>
<?php if ($embed !== ''): ?>
    <?= $embed ?>
<?php endif; ?>

<?php if (!empty($lesson['content_html'])): ?>
    <div class="lesson-content"><?= $lesson['content_html'] ?></div>
<?php endif; ?>

<?php if (!empty($materials)): ?>
    <section class="materials-section">
        <h3>Materiali</h3>
        <ul class="material-list">
            <?php foreach ($materials as $material): ?>
                <li>
                    <a href="/materials/<?= (int) $material['id'] ?>/download"><?= htmlspecialchars($material['file_name']) ?></a>
                    <span class="material-size"><?= round($material['file_size_bytes'] / 1024) ?>&nbsp;KB</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (Auth::hasRole('studente')): ?>
    <?php if ($completed): ?>
        <p class="badge badge-muted">&check; Lezione completata</p>
    <?php else: ?>
        <form action="/lessons/<?= (int) $lesson['id'] ?>/complete" method="post">
            <button type="submit" class="btn btn-primary">Segna come completata</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
