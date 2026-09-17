<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\VideoPoster;
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

<?php
// Lezione di solo video: nient'altro da leggere o scaricare. Solo qui il
// pulsante "segna come completata" aspetta che il video venga avviato —
// altrove c'e' del testo, e bloccare il pulsante non avrebbe senso.
$soloVideo = $lesson['video_provider'] !== 'none'
    && trim(strip_tags((string) ($lesson['content_html'] ?? ''))) === ''
    && $materials === [];

$iframeDifferito = $soloVideo && VideoEmbed::isIframeProvider((string) $lesson['video_provider']);
$embed = VideoEmbed::render($lesson['video_provider'], $lesson['video_ref'], (int) $lesson['id'], $iframeDifferito);
?>
<?php if ($embed !== ''): ?>
    <div class="lesson-video<?= $soloVideo ? ' is-solo-video' : '' ?>" id="lesson-video"
         data-lesson="<?= (int) $lesson['id'] ?>"
         <?= $soloVideo ? 'data-attende-avvio' : '' ?>>
        <?php if ($iframeDifferito): ?>
            <?php /* L'iframe non e' ancora caricato: qui sopra sta la copertina
                     con il pulsante di avvio. Il clic fa due cose in una,
                     carica il video con avvio automatico e registra che lo
                     studente l'ha avviato. */ ?>
            <div class="video-start" data-avvio>
                <?= VideoPoster::svg((int) $lesson['id'], (string) $lesson['title']) ?>
                <button type="button" class="video-start-button" data-avvia>
                    <span class="video-start-icon" aria-hidden="true">&#9654;</span>
                    <span class="video-start-label">Guarda la lezione</span>
                </button>
            </div>
        <?php endif; ?>

        <?= $embed ?>

        <?php if ($iframeDifferito): ?>
            <?php /* Senza JavaScript l'iframe qui sopra resterebbe senza
                     indirizzo e il video non si vedrebbe: qui c'e' lo stesso
                     player, caricato subito e senza copertina. */ ?>
            <noscript>
                <?= VideoEmbed::render($lesson['video_provider'], $lesson['video_ref'], (int) $lesson['id']) ?>
            </noscript>
        <?php endif; ?>

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
        <?php /* Il pulsante e' abilitato nell'HTML e lo disabilita il
                 JavaScript: se il JavaScript non c'e' o qualcosa va storto,
                 lo studente puo' comunque concludere la lezione. Uno bloccato
                 e' un danno vero; uno che segna senza aver premuto play e'
                 un fastidio. */ ?>
        <form action="/lessons/<?= (int) $lesson['id'] ?>/complete" method="post"
              <?= $soloVideo ? 'data-attende-avvio-form' : '' ?>>
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary" data-completa>Segna come completata</button>
            <?php if ($soloVideo): ?>
                <span class="form-hint" data-avviso-avvio hidden>
                    Avvia il video per poter segnare la lezione come completata.
                </span>
            <?php endif; ?>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php if ($embed !== ''): ?>
    <script src="/assets/js/lesson-focus.js"></script>
<?php endif; ?>

<?php if ($embed !== '' && $soloVideo && Auth::hasRole('studente')): ?>
    <script src="/assets/js/lesson-start.js"></script>
<?php endif; ?>
