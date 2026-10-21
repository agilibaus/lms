<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Icona;

/**
 * Le impostazioni di un questionario H5P (22/10): titolo, soglia e il
 * contenuto, che si puo' sostituire con un altro file .h5p. Niente
 * domande: stanno nell'esercizio.
 *
 * @var array $quiz
 * @var array $module
 * @var array|null $course
 * @var int $tentativi
 * @var bool $h5pDisponibile
 */

$soglia = (int) $quiz['passing_score_pct'];
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars($course['title'] ?? 'Corso') ?></span></a>
    <h1><?= htmlspecialchars($quiz['title']) ?></h1>
    <p class="page-subtitle">
        Modulo: <?= htmlspecialchars($module['title']) ?> ·
        soglia di superamento <?= $soglia ?>% ·
        contenuto H5P
    </p>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<section class="card">
    <h2>Impostazioni del questionario</h2>

    <form action="/quizzes/<?= (int) $quiz['id'] ?>" method="post" class="form" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label for="quiz-title">Titolo del questionario</label>
        <input type="text" id="quiz-title" name="title" maxlength="200" required
               value="<?= htmlspecialchars($quiz['title']) ?>">
        <p class="form-hint">È il nome che vede lo studente quando apre il questionario.</p>

        <label for="quiz-soglia">Punteggio minimo per superarlo</label>
        <div class="campo-con-unita">
            <input type="number" id="quiz-soglia" name="passing_score_pct" min="1" max="100" step="1"
                   value="<?= $soglia ?>" aria-describedby="quiz-soglia-aiuto">
            <span aria-hidden="true">%</span>
        </div>
        <p class="form-hint" id="quiz-soglia-aiuto">
            In percentuale dei punti dell'esercizio: con <?= $soglia ?>% e un esercizio da 10 punti ne servono almeno
            <?= (int) ceil($soglia / 10) ?>. Ogni «Verifica» è un tentativo; i tentativi sono illimitati e vale il migliore.
        </p>

        <h3 class="quiz-h5p-titolo">Il contenuto</h3>
        <p class="quiz-h5p-attuale">
            <span class="quiz-h5p-file"><?= htmlspecialchars((string) $quiz['h5p_file']) ?></span>
            <span class="form-hint">tipo <?= htmlspecialchars((string) $quiz['h5p_library']) ?></span>
        </p>
        <label for="h5p">Sostituisci con un altro file .h5p</label>
        <input type="file" id="h5p" name="h5p" accept=".h5p" aria-describedby="h5p-aiuto" <?= $h5pDisponibile ? '' : 'disabled' ?>>
        <p class="form-hint" id="h5p-aiuto">
            Facoltativo. I tentativi già svolti restano<?= $tentativi > 0 ? ' (' . $tentativi . ')' : '' ?>,
            con il punteggio che avevano.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva impostazioni</button>
            <a href="/quizzes/<?= (int) $quiz['id'] ?>" class="btn btn-secondary">Prova il questionario</a>
            <a href="/courses/<?= (int) $module['course_id'] ?>" class="btn btn-secondary">Annulla</a>
        </div>
    </form>

    <form action="/quizzes/<?= (int) $quiz['id'] ?>/delete" method="post" class="danger-zone"
          onsubmit="return confirm('Eliminare il questionario, il suo contenuto e tutti i tentativi degli studenti?');">
        <?= Csrf::field() ?>
        <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?>Elimina il questionario</button>
        <span class="form-hint">
            Spariscono il contenuto H5P e tutti i tentativi già svolti dagli studenti.
            Non si torna indietro.
        </span>
    </form>
</section>
