<?php

declare(strict_types=1);

use App\Auth\Auth;

/** @var array $course */
/** @var array $modules */
/** @var array<int, array> $lessonsByModule */
/** @var int[] $completedLessonIds */
?>
<div class="page-header">
    <a href="/" class="back-link">&larr; Tutti i corsi</a>
    <h1><?= htmlspecialchars($course['title']) ?></h1>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if (!empty($course['description'])): ?>
    <p class="course-description"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
<?php endif; ?>

<?php if (Auth::hasRole('admin', 'tutor')): ?>
    <p><a href="/courses/<?= (int) $course['id'] ?>/modules/create" class="btn btn-primary">+ Nuovo modulo</a></p>
<?php endif; ?>

<?php if (empty($modules)): ?>
    <p class="empty-state">Nessun modulo ancora disponibile per questo corso.</p>
<?php else: ?>
    <div class="module-list">
        <?php foreach ($modules as $module): ?>
            <section class="module-card">
                <div class="module-card-header">
                    <h3><?= htmlspecialchars($module['title']) ?></h3>
                    <?php if (Auth::hasRole('admin', 'tutor')): ?>
                        <div class="module-card-actions">
                            <a href="/modules/<?= (int) $module['id'] ?>/lessons/create">+ Lezione</a>
                            <a href="/modules/<?= (int) $module['id'] ?>/edit">Modifica</a>
                            <form action="/modules/<?= (int) $module['id'] ?>/delete" method="post"
                                  onsubmit="return confirm('Eliminare questo modulo e tutte le sue lezioni?');">
                                <button type="submit" class="link-btn">Elimina</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <?php $lessons = $lessonsByModule[$module['id']] ?? []; ?>

                <?php if (empty($lessons)): ?>
                    <p class="empty-state-small">Nessuna lezione in questo modulo.</p>
                <?php else: ?>
                    <ul class="lesson-list">
                        <?php foreach ($lessons as $lesson): ?>
                            <li class="lesson-list-item">
                                <a href="/lessons/<?= (int) $lesson['id'] ?>">
                                    <?php if (in_array((int) $lesson['id'], $completedLessonIds, true)): ?>
                                        <span class="lesson-check" title="Completata">&check;</span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($lesson['title']) ?>
                                </a>
                                <?php if ($lesson['video_provider'] !== 'none'): ?>
                                    <span class="badge badge-video">video</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
