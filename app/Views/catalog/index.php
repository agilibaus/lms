<?php

declare(strict_types=1);

use App\Core\CourseCover;
use App\Core\Csrf;

/** @var array $courses */
?>
<div class="page-header">
    <h1>Esplora corsi</h1>
    <p class="page-subtitle">I corsi a cui puoi iscriverti. Quelli che segui già sono nella pagina Corsi.</p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if ($courses === []): ?>
    <p class="empty-state">
        Al momento non ci sono corsi disponibili per l'iscrizione autonoma.
        Se aspetti di essere iscritto a un corso riservato, ci penserà chi lo gestisce.
    </p>
<?php else: ?>
    <div class="catalog-list">
        <?php foreach ($courses as $course): ?>
            <?php
            $id = (int) $course['id'];
            $pending = ($course['request_status'] ?? null) === 'pending';
            $rejected = ($course['request_status'] ?? null) === 'rejected';
            ?>
            <section class="card catalog-card">
                <?php $cover = CourseCover::url($course); ?>
                <div class="catalog-card-cover">
                    <?php if ($cover !== null): ?>
                        <img src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars(CourseCover::altFor($course)) ?>" loading="lazy">
                    <?php else: ?>
                        <?php require __DIR__ . '/../courses/_cover_placeholder.php'; ?>
                    <?php endif; ?>
                </div>

                <div class="card-head">
                    <h2><?= htmlspecialchars((string) $course['title']) ?></h2>
                    <span class="badge <?= $course['enrollment_mode'] === 'open' ? 'badge-success' : '' ?>">
                        <?= $course['enrollment_mode'] === 'open' ? 'iscrizione libera' : 'su richiesta' ?>
                    </span>
                </div>

                <?php if (!empty($course['description'])): ?>
                    <p class="card-meta"><?= nl2br(htmlspecialchars((string) $course['description'])) ?></p>
                <?php endif; ?>

                <p class="card-meta"><?= (int) $course['lesson_count'] ?> lezioni</p>

                <?php if ($pending): ?>
                    <p class="form-hint">Richiesta inviata: sarà valutata da un tutor.</p>
                <?php else: ?>
                    <?php if ($rejected): ?>
                        <p class="form-hint">Una tua richiesta precedente non è stata accolta. Puoi riprovare.</p>
                    <?php endif; ?>

                    <form action="/catalogo/<?= $id ?>/iscrizione" method="post" class="form">
                        <?= Csrf::field() ?>

                        <?php if ($course['enrollment_mode'] === 'request'): ?>
                            <label for="message-<?= $id ?>">Due righe su di te (facoltativo)</label>
                            <textarea id="message-<?= $id ?>" name="message" rows="2" maxlength="500"
                                      placeholder="Perché ti interessa questo corso"></textarea>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?= $course['enrollment_mode'] === 'open' ? 'Iscriviti' : 'Chiedi di iscriverti' ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
