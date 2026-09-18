<?php

declare(strict_types=1);

use App\Core\CourseCover;
use App\Core\Csrf;

/** @var array $courses */
/** @var string $heading */
/** @var bool $isStaff */
?>
<div class="page-header">
    <h1><?= htmlspecialchars($heading) ?></h1>
</div>

<?php if (empty($courses)): ?>
    <?php if ($isStaff): ?>
        <p class="empty-state">Nessun corso disponibile al momento.</p>
    <?php else: ?>
        <p class="empty-state">
            Non sei iscritto a nessun corso. Guarda in <a href="/catalogo">Esplora corsi</a>.
        </p>
    <?php endif; ?>
<?php else: ?>
    <div class="course-grid"<?= $isStaff ? ' data-riordinabile data-csrf="' . htmlspecialchars(Csrf::token()) . '"' : '' ?>>
        <?php foreach ($courses as $courseIndex => $course): ?>
            <a href="/courses/<?= (int) $course['id'] ?>" class="course-card" data-corso="<?= (int) $course['id'] ?>">
                <?php if ($isStaff): ?>
                    <?php /* Le frecce ci sono sempre, anche con il trascinamento
                             attivo: sono la via da tastiera e quella che funziona
                             senza JavaScript. */ ?>
                    <div class="course-order">
                        <form action="/admin/courses/<?= (int) $course['id'] ?>/move" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="icon-btn" title="Sposta prima"
                                    aria-label="Sposta <?= htmlspecialchars((string) $course['title']) ?> prima"
                                    <?= $courseIndex === 0 ? 'disabled' : '' ?>>&uarr;</button>
                        </form>
                        <span class="course-drag-handle" aria-hidden="true" title="Trascina per riordinare" hidden>&#8942;&#8942;</span>
                        <form action="/admin/courses/<?= (int) $course['id'] ?>/move" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="icon-btn" title="Sposta dopo"
                                    aria-label="Sposta <?= htmlspecialchars((string) $course['title']) ?> dopo"
                                    <?= $courseIndex === count($courses) - 1 ? 'disabled' : '' ?>>&darr;</button>
                        </form>
                    </div>
                <?php endif; ?>

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

<?php if ($isStaff): ?>
    <script src="/assets/js/course-order.js"></script>
<?php endif; ?>
