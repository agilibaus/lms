<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\FileType;
use App\Core\VideoEmbed;

/** @var array $lesson */
/** @var array $module */
/** @var array $course */
/** @var array $materials */
/** @var bool $completed */
/** @var array $liveSessions */
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
    <div class="lesson-video" id="lesson-video">
        <?= $embed ?>

        <?php /* Nascosti nell'HTML: senza JavaScript restano nascosti e la
                 pagina e' quella di sempre, invece di mostrare un pulsante
                 che non fa niente. */ ?>
        <p class="lesson-video-actions">
            <button type="button" class="btn btn-secondary" data-focus-enter hidden>
                Senza distrazioni
            </button>
        </p>

        <button type="button" class="btn lesson-focus-exit" data-focus-exit hidden>
            &times; Torna alla lezione
        </button>
    </div>
<?php endif; ?>

<?php if (!empty($liveSessions)): ?>
    <section class="live-sessions-box">
        <h3>Incontri dal vivo di questo modulo</h3>

        <ul class="live-session-list">
            <?php foreach ($liveSessions as $session): ?>
                <?php
                $inizio = new DateTimeImmutable((string) $session['starts_at']);
                $fine = new DateTimeImmutable((string) $session['ends_at']);
                // Calcolato dal database, non qui: server e MySQL possono
                // stare su fusi diversi.
                $apribile = (bool) $session['joinable'];
                $iniziato = (bool) $session['started'];
                $link = (string) ($session['meet_link'] ?? '');
                ?>
                <li>
                    <span class="live-session-info">
                        <span class="live-session-title"><?= htmlspecialchars((string) $session['title']) ?></span>
                        <span class="live-session-when">
                            <?= htmlspecialchars($inizio->format('d/m/Y')) ?>,
                            <?= htmlspecialchars($inizio->format('H:i')) ?>–<?= htmlspecialchars($fine->format('H:i')) ?>
                            <?= $iniziato ? ' · in corso' : ($apribile ? ' · si può entrare' : '') ?>
                        </span>
                    </span>

                    <?php if ($link === ''): ?>
                        <span class="live-session-note">Link non ancora disponibile</span>
                    <?php elseif ($apribile): ?>
                        <?php /* Nessun target="_blank": la riunione si apre in questa
                                 scheda, cosi' il tasto Indietro riporta alla lezione su
                                 qualunque dispositivo. Da una scheda nuova si tornerebbe
                                 solo dal selettore delle schede, che su telefono e'
                                 scomodo; e se il telefono dirotta il link sull'app Meet,
                                 la lezione resta dov'era nel browser. */ ?>
                        <a class="btn btn-primary live-session-join"
                           href="<?= htmlspecialchars($link) ?>">Entra nella riunione</a>
                    <?php else: ?>
                        <span class="live-session-note">Si entra da 15 minuti prima</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (!empty($lesson['content_html'])): ?>
    <div class="lesson-content"><?= $lesson['content_html'] ?></div>
<?php endif; ?>

<?php if (!empty($materials)): ?>
    <section class="materials-section">
        <h3>Materiali</h3>
        <ul class="material-list">
            <?php foreach ($materials as $material): ?>
                <?php $extension = pathinfo((string) $material['file_name'], PATHINFO_EXTENSION); ?>
                <li>
                    <span class="file-icon file-icon-<?= FileType::family($extension) ?>" aria-hidden="true">
                        <?= htmlspecialchars(FileType::badge($extension)) ?>
                    </span>
                    <span class="material-info">
                        <a class="material-name" href="/materials/<?= (int) $material['id'] ?>/download">
                            <?= htmlspecialchars($material['file_name']) ?>
                        </a>
                        <span class="material-meta">
                            <?= htmlspecialchars(FileType::label($extension)) ?> ·
                            <?= FileType::humanSize((int) $material['file_size_bytes']) ?>
                        </span>
                    </span>
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
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary">Segna come completata</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php if ($embed !== ''): ?>
    <script src="/assets/js/lesson-focus.js"></script>
<?php endif; ?>
