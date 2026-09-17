<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\CourseCover;
use App\Core\Csrf;

/** @var array $course */
/** @var array $modules */
/** @var array<int, array> $lessonsByModule */
/** @var int[] $completedLessonIds */
/** @var array<int, array|null> $quizByModule */
/** @var array<int, bool> $quizPassedByModule */
/** @var int[] $lockedModuleIds */
/** @var array|null $certificate */
/** @var array|null $eligibility */

$isStaff = Auth::hasRole('admin', 'tutor');
?>
<div class="page-header">
    <a href="/" class="back-link">&larr; <?= Auth::hasRole('admin', 'tutor', 'assistente') ? 'Tutti i corsi' : 'I miei corsi' ?></a>
    <h1><?= htmlspecialchars($course['title']) ?></h1>
</div>

<?php $cover = CourseCover::url($course, false); ?>
<?php if ($cover !== null): ?>
    <div class="course-hero">
        <img src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars(CourseCover::altFor($course)) ?>">
    </div>
<?php endif; ?>


<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if (!empty($course['description'])): ?>
    <p class="course-description"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
<?php endif; ?>

<?php if ($certificate !== null && $certificate['revoked_at'] === null): ?>
    <div class="alert alert-success">
        Hai completato il corso: certificato <code><?= htmlspecialchars((string) $certificate['certificate_code']) ?></code> —
        <a href="/certificates/<?= (int) $certificate['id'] ?>/download">scarica il PDF</a>.
    </div>
<?php elseif ($eligibility !== null && !$eligibility['eligible']): ?>
    <p class="course-progress-hint">
        Per ottenere il certificato: lezioni <?= (int) $eligibility['lessons_done'] ?>/<?= (int) $eligibility['lessons_total'] ?>,
        quiz superati <?= (int) $eligibility['quizzes_passed'] ?>/<?= (int) $eligibility['quizzes_total'] ?>.
    </p>
<?php endif; ?>

<?php if ($isStaff): ?>
    <p><a href="/courses/<?= (int) $course['id'] ?>/modules/create" class="btn btn-primary">+ Nuovo modulo</a></p>
<?php endif; ?>

<?php if (empty($modules)): ?>
    <p class="empty-state">Nessun modulo ancora disponibile per questo corso.</p>
<?php else: ?>
    <div class="module-list">
        <?php foreach ($modules as $module): ?>
            <?php
            $moduleId = (int) $module['id'];
            $quiz = $quizByModule[$moduleId] ?? null;
            $isLocked = in_array($moduleId, $lockedModuleIds, true);
            ?>
            <section class="module-card <?= $isLocked ? 'module-locked' : '' ?>">
                <div class="module-card-header">
                    <h3>
                        <?= htmlspecialchars($module['title']) ?>
                        <?php if ($isLocked): ?>
                            <span class="badge badge-danger">bloccato</span>
                        <?php elseif (!empty($module['quiz_required'])): ?>
                            <span class="badge">quiz obbligatorio</span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($isStaff): ?>
                        <div class="module-card-actions">
                            <a href="/modules/<?= $moduleId ?>/lessons/create">+ Lezione</a>
                            <?php if ($quiz === null): ?>
                                <a href="/modules/<?= $moduleId ?>/quiz/create">+ Quiz</a>
                            <?php else: ?>
                                <a href="/quizzes/<?= (int) $quiz['id'] ?>/edit">Quiz</a>
                            <?php endif; ?>
                            <a href="/modules/<?= $moduleId ?>/edit">Modifica</a>
                            <form action="/modules/<?= $moduleId ?>/delete" method="post"
                                  onsubmit="return confirm('Eliminare questo modulo e tutte le sue lezioni?');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-btn">Elimina</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($isLocked): ?>
                    <p class="empty-state-small">
                        Supera il quiz del modulo precedente per sbloccare questo modulo.
                    </p>
                <?php else: ?>
                    <?php $lessons = $lessonsByModule[$moduleId] ?? []; ?>

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

                    <?php if ($quiz !== null): ?>
                        <p class="module-quiz-row">
                            <a href="/quizzes/<?= (int) $quiz['id'] ?>" class="quiz-link">
                                Quiz: <?= htmlspecialchars((string) $quiz['title']) ?>
                            </a>
                            <?php if (!empty($quizPassedByModule[$moduleId])): ?>
                                <span class="badge badge-success">superato</span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
