<?php

declare(strict_types=1);

use App\Controllers\Admin\TutorWelcomeController;
use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\TutorWelcome;

/**
 * Il benvenuto dei tutor all'inizio del corso (07/10). Uno per ciascun
 * tutor dei gruppi a cui il corso e' assegnato: lo studente sente quello
 * del proprio tutor. Il tutor vede e carica solo il proprio
 * (`course.welcome_own`); l'admin quello di tutti (`course.welcome`).
 *
 * Un form per tutor, separato dagli altri: porta due file, e un errore su
 * un tutor non deve far perdere quello che si stava scrivendo per un altro.
 *
 * @var array $course
 * @var list<array<string, mixed>> $welcomes
 */

$courseId = (int) $course['id'];
$maxAudioMb = (int) (TutorWelcome::AUDIO_MAX_BYTES / 1024 / 1024);
$tutti = Auth::can('course.welcome');

// Un campo di testo con il limite e il contatore sopra l'angolo in alto a
// destra (10/10): lo schema della presentazione del profilo, con le stesse
// classi e lo stesso script. Il numero di partenza lo scrive il server,
// contando il testo gia' scritto con gli a capo come li conta il campo.
$campoContato = static function (string $id, string $etichetta, string $nome, int $massimo, string $valore, int $righe): string {
    $restano = max(0, $massimo - mb_strlen(str_replace("\r\n", "\n", $valore)));

    return '<div class="quiz-open-wrap campo-contato">'
        . '<div class="campo-contato-testa">'
        . '<label for="' . $id . '">' . htmlspecialchars($etichetta) . '</label>'
        . '<span class="quiz-open-count" id="' . $id . '-resta" data-max="' . $massimo . '">'
        . $restano . ($restano === 1 ? ' carattere rimasto' : ' caratteri rimasti') . '</span>'
        . '</div>'
        . '<textarea id="' . $id . '" name="' . $nome . '" rows="' . $righe . '" required maxlength="' . $massimo . '"'
        . ' class="quiz-open-answer" aria-describedby="' . $id . '-resta">' . htmlspecialchars($valore) . '</textarea>'
        . '</div>';
};
?>
<section class="card" id="benvenuti">
    <h2><?= $tutti ? 'Benvenuto dei tutor' : 'Il tuo benvenuto' ?></h2>
    <p class="card-meta">
        La foto del profilo del tutor e un breve audio in cima alla pagina del corso, con l'email per
        gli studenti e il link al gruppo WhatsApp. Ogni studente vede il tutor del proprio gruppo. Ogni
        corso deve averne uno. Completo nelle prime <?= TutorWelcome::VISITE_COMPLETE ?> visite, poi
        ridotto a una riga; prima, se lo studente l'ha già ascoltato fino in fondo.
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
            <p class="card-meta">
                Tutor di: <?= htmlspecialchars((string) $w['groups']) ?>.
                Email per gli studenti:
                <?= $w['contact_email'] !== null && $w['contact_email'] !== ''
                    ? htmlspecialchars((string) $w['contact_email'])
                    : 'nessuna (si imposta nel profilo del tutor)' ?>.
                Il link WhatsApp si imposta nella pagina di ciascun gruppo.
            </p>

            <?php /* La foto e' quella del profilo (09/10): qui si vede, non si
                     carica. Si cambia dal profilo del tutor o, per l'admin, da
                     «Modifica utente». */ ?>
            <p class="card-meta">
                La foto è quella del profilo<?= $tutti ? ' del tutor: si cambia dal suo profilo o da «Modifica utente».' : ': si cambia dal tuo profilo.' ?>
            </p>

            <?php if ($esiste): ?>
                <div class="tutor-benvenuto-anteprima">
                    <?php if (!empty($w['avatar_path'])): ?>
                        <img class="tutor-benvenuto-anteprima-foto" src="/utenti/<?= $tutorId ?>/immagine" alt="">
                    <?php endif; ?>
                    <audio controls preload="none" src="/benvenuti/<?= (int) $w['welcome_id'] ?>/audio"
                           aria-label="Benvenuto di <?= htmlspecialchars((string) $w['tutor_name'], ENT_QUOTES) ?>"></audio>
                </div>
            <?php else: ?>
                <p class="empty-state">Manca il benvenuto: gli studenti di questo tutor non vedono niente in cima al corso.</p>
            <?php endif; ?>

            <form action="/admin/courses/<?= $courseId ?>/benvenuti/<?= $tutorId ?>" method="post"
                  class="form" enctype="multipart/form-data">
                <?= Csrf::field() ?>

                <label for="<?= $c ?>-audio"><?= $esiste ? 'Sostituisci l\'audio' : 'Audio' ?></label>
                <input type="file" id="<?= $c ?>-audio" name="audio" accept=".mp3,.m4a,audio/mpeg,audio/mp4"
                       <?= $esiste ? '' : 'required' ?>>
                <p class="form-hint">MP3 o M4A, fino a <?= $maxAudioMb ?> MB: un minuto o due.</p>

                <?= $campoContato($c . '-testo', 'Testo del benvenuto', 'transcript',
                    TutorWelcomeController::TRANSCRIPT_MAX_CHARS, (string) ($w['transcript'] ?? ''), 5) ?>
                <p class="form-hint">
                    Quello che il tutor dice nell'audio. Obbligatorio: lo legge chi non sente, o chi in
                    quel momento non può ascoltare.
                </p>
                <?php /* La trascrizione dell'audio (09/10, Elena): con 7-8 tutor e un
                         minuto di audio ciascuno, un servizio da usare a mano costa meno
                         di un collegamento automatico. Si apre in una scheda nuova, per
                         non perdere il modulo, e senza dire a TurboScribe da quale
                         pagina di Pistacchio si arriva (`noreferrer`). Il testo va
                         riletto: la trascrizione sbaglia nomi propri e punteggiatura. */ ?>
                <p class="form-hint">
                    Per ottenerlo puoi trascrivere l'audio con
                    <a href="https://turboscribe.ai/it/" target="_blank" rel="noopener noreferrer">TurboScribe<span class="sr-only"> (si apre in una nuova scheda)</span></a>,
                    poi incollare qui il testo e rileggerlo.
                </p>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $esiste ? 'Salva benvenuto' : 'Carica benvenuto' ?></button>
                </div>
            </form>

            <?php if ($esiste): ?>
                <form action="/admin/courses/<?= $courseId ?>/benvenuti/<?= $tutorId ?>/elimina" method="post"
                      onsubmit="return confirm('Rimuovere il benvenuto di questo tutor? L’audio viene cancellato; la foto del profilo resta.');">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-btn link-btn-danger">Rimuovi benvenuto</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<script src="/assets/js/quiz-open-count.js"></script>
