<?php

declare(strict_types=1);

use App\Controllers\Admin\TutorWelcomeController;
use App\Core\Csrf;
use App\Core\TutorWelcome;

/**
 * Il benvenuto dei tutor all'inizio del corso (07/10). Uno per ciascun
 * tutor dei gruppi a cui il corso e' assegnato: lo studente sente quello
 * del proprio tutor. Lo carica solo l'admin (permesso `course.welcome`).
 *
 * Un form per tutor, separato dagli altri: porta due file, e un errore su
 * un tutor non deve far perdere quello che si stava scrivendo per un altro.
 *
 * @var array $course
 * @var list<array<string, mixed>> $welcomes
 */

$courseId = (int) $course['id'];
$maxAudioMb = (int) (TutorWelcome::AUDIO_MAX_BYTES / 1024 / 1024);
?>
<section class="card" id="benvenuti">
    <h2>Benvenuto dei tutor</h2>
    <p class="card-meta">
        Una foto a mezzo busto e un breve audio in cima alla pagina del corso. Ogni studente sente
        il tutor del proprio gruppo; chi non è in un gruppo con un tutor non vede niente. Completo
        nelle prime <?= TutorWelcome::VISITE_COMPLETE ?> visite, poi ridotto a una riga; prima,
        se lo studente l'ha già ascoltato fino in fondo.
    </p>

    <?php if ($welcomes === []): ?>
        <p class="empty-state">
            Questo corso non è assegnato a nessun gruppo con un tutor: non c'è nessuno a cui dare il
            benvenuto. Assegna il corso a un gruppo dalla pagina del gruppo.
        </p>
    <?php endif; ?>

    <?php foreach ($welcomes as $w): ?>
        <?php
        $tutorId = (int) $w['tutor_id'];
        $c = 'benv-' . $tutorId;
        $esiste = $w['welcome_id'] !== null;
        ?>
        <div class="tutor-benvenuto-admin">
            <h3><?= htmlspecialchars((string) $w['tutor_name']) ?></h3>
            <p class="card-meta">Tutor di: <?= htmlspecialchars((string) $w['groups']) ?></p>

            <?php if ($esiste): ?>
                <div class="tutor-benvenuto-anteprima">
                    <img class="tutor-benvenuto-anteprima-foto" src="/benvenuti/<?= (int) $w['welcome_id'] ?>/foto" alt="">
                    <audio controls preload="none" src="/benvenuti/<?= (int) $w['welcome_id'] ?>/audio"
                           aria-label="Benvenuto di <?= htmlspecialchars((string) $w['tutor_name'], ENT_QUOTES) ?>"></audio>
                </div>
            <?php else: ?>
                <p class="empty-state">Nessun benvenuto: i suoi studenti non vedono niente in cima al corso.</p>
            <?php endif; ?>

            <form action="/admin/courses/<?= $courseId ?>/benvenuti/<?= $tutorId ?>" method="post"
                  class="form" enctype="multipart/form-data">
                <?= Csrf::field() ?>

                <label for="<?= $c ?>-foto"><?= $esiste ? 'Sostituisci la foto' : 'Foto a mezzo busto' ?></label>
                <input type="file" id="<?= $c ?>-foto" name="photo" accept="image/jpeg,image/png,image/webp"
                       <?= $esiste ? '' : 'required' ?>>
                <p class="form-hint">JPG, PNG o WebP, fino a 8 MB. Verticale: si vede la parte alta, viso e spalle.</p>

                <label for="<?= $c ?>-audio"><?= $esiste ? 'Sostituisci l\'audio' : 'Audio' ?></label>
                <input type="file" id="<?= $c ?>-audio" name="audio" accept=".mp3,.m4a,audio/mpeg,audio/mp4"
                       <?= $esiste ? '' : 'required' ?>>
                <p class="form-hint">MP3 o M4A, fino a <?= $maxAudioMb ?> MB: un minuto o due.</p>

                <label for="<?= $c ?>-testo">Testo del benvenuto</label>
                <textarea id="<?= $c ?>-testo" name="transcript" rows="5" required
                          maxlength="<?= TutorWelcomeController::TRANSCRIPT_MAX_CHARS ?>"><?= htmlspecialchars((string) ($w['transcript'] ?? '')) ?></textarea>
                <p class="form-hint">
                    Quello che il tutor dice nell'audio. Obbligatorio: lo legge chi non sente, o chi in
                    quel momento non può ascoltare.
                </p>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $esiste ? 'Salva benvenuto' : 'Carica benvenuto' ?></button>
                </div>
            </form>

            <?php if ($esiste): ?>
                <form action="/admin/courses/<?= $courseId ?>/benvenuti/<?= $tutorId ?>/elimina" method="post"
                      onsubmit="return confirm('Rimuovere il benvenuto di questo tutor? Foto e audio vengono cancellati.');">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-btn link-btn-danger">Rimuovi benvenuto</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
