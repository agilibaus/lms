<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\QuizScoring;

/** @var array $quiz */
/** @var array $module */
/** @var array|null $course */
/** @var array $questions */
/** @var array<int, array> $optionsByQuestion */
/** @var int $incompleteQuestions */
/** @var int $maxOptions */
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link">&larr; <?= htmlspecialchars($course['title'] ?? 'Corso') ?></a>
    <h1><?= htmlspecialchars($quiz['title']) ?></h1>
    <p class="page-subtitle">
        Modulo: <?= htmlspecialchars($module['title']) ?> ·
        soglia di superamento <?= (int) $quiz['passing_score_pct'] ?>% ·
        <?= count($questions) ?> domande
    </p>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if ($questions === []): ?>
    <div class="alert alert-warning">Il questionario non ha ancora domande: non è somministrabile agli studenti.</div>
<?php elseif ($incompleteQuestions > 0): ?>
    <div class="alert alert-warning">
        <?= (int) $incompleteQuestions ?> domande non hanno una risposta corretta impostata.
    </div>
<?php endif; ?>

<?php
/*
 * I due campi avevano solo un'etichetta per i lettori di schermo: all'occhio
 * erano un riquadro di testo e un numero nudo, e il numero non diceva di che
 * cosa fosse la percentuale. Adesso ciascuno ha la propria etichetta scritta,
 * e la soglia dice in parole che cosa comporta **su questo quiz**.
 */
$soglia = (int) $quiz['passing_score_pct'];
$valutate = QuizScoring::conteggioValutate($questions);

// Quante risposte giuste servono davvero con la soglia di adesso. E' il
// numero che chi costruisce il quiz ha in testa, e che finora doveva
// calcolarsi da solo.
$minimeGiuste = $valutate > 0 ? (int) ceil($soglia / 100 * $valutate) : 0;
?>
<section class="card">
    <h2>Impostazioni del questionario</h2>

    <form action="/quizzes/<?= (int) $quiz['id'] ?>" method="post" class="form">
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
            <?php if ($valutate === 0): ?>
                Questo questionario non ha ancora domande che fanno punteggio, quindi la soglia non è
                ancora usata. Le domande aperte non entrano nel calcolo.
            <?php else: ?>
                Su <?= $valutate ?>
                <?= $valutate === 1 ? 'domanda che fa punteggio' : 'domande che fanno punteggio' ?>,
                con questa soglia servono almeno <strong><?= $minimeGiuste ?></strong>
                <?= $minimeGiuste === 1 ? 'risposta giusta' : 'risposte giuste' ?>.
                <?php $aperte = count($questions) - $valutate; ?>
                <?php if ($aperte > 0): ?>
                    <?= $aperte === 1
                        ? 'La domanda aperta non entra nel conto.'
                        : 'Le ' . $aperte . ' domande aperte non entrano nel conto.' ?>
                <?php endif; ?>
            <?php endif; ?>
            I tentativi sono illimitati e vale il punteggio migliore.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva impostazioni</button>
        </div>
    </form>

    <form action="/quizzes/<?= (int) $quiz['id'] ?>/delete" method="post" class="danger-zone"
          onsubmit="return confirm('Eliminare il questionario, le sue domande e tutti i tentativi degli studenti?');">
        <?= Csrf::field() ?>
        <button type="submit" class="link-btn link-btn-danger">Elimina il questionario</button>
        <span class="form-hint">
            Spariscono le domande e tutti i tentativi già svolti dagli studenti.
            Non si torna indietro.
        </span>
    </form>
</section>

<section class="card">
    <h2>Domande</h2>

    <?php if ($questions === []): ?>
        <p class="empty-state-small">Nessuna domanda.</p>
    <?php else: ?>
        <ol class="question-list">
            <?php foreach ($questions as $indice => $question): ?>
                <?php $tipo = (string) $question['question_type']; ?>
                <li class="question-item">
                    <div class="question-item-head">
                        <span class="question-text"><?= htmlspecialchars($question['question_text']) ?></span>
                        <span class="badge"><?= htmlspecialchars(QuizScoring::etichetta($tipo)) ?></span>
                    </div>

                    <?php if ($tipo === 'open'): ?>
                        <p class="hint">La risposta la scrive lo studente. Non fa punteggio.</p>
                    <?php else: ?>
                        <ul class="option-preview">
                            <?php foreach ($optionsByQuestion[$question['id']] ?? [] as $option): ?>
                                <li class="<?= (int) $option['is_correct'] === 1 ? 'is-correct' : '' ?>">
                                    <?= htmlspecialchars($option['option_text']) ?>
                                    <?= (int) $option['is_correct'] === 1 ? ' &check;' : '' ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="question-item-actions">
                        <?php /* Frecce come nelle lezioni e nei materiali: due
                                 moduli, nessun trascinamento, funziona senza
                                 JavaScript. Il primo e l'ultimo hanno la
                                 freccia disabilitata invece che assente, così
                                 la fila di comandi non si sposta di riga in
                                 riga. */ ?>
                        <form action="/questions/<?= (int) $question['id'] ?>/move" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="icon-btn" title="Sposta su"
                                    aria-label="Sposta su la domanda: <?= htmlspecialchars($question['question_text']) ?>"
                                    <?= $indice === 0 ? 'disabled' : '' ?>>&uarr;</button>
                        </form>
                        <form action="/questions/<?= (int) $question['id'] ?>/move" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="icon-btn" title="Sposta giù"
                                    aria-label="Sposta giù la domanda: <?= htmlspecialchars($question['question_text']) ?>"
                                    <?= $indice === count($questions) - 1 ? 'disabled' : '' ?>>&darr;</button>
                        </form>
                        <a href="/questions/<?= (int) $question['id'] ?>/edit">Modifica</a>
                        <form action="/questions/<?= (int) $question['id'] ?>/delete" method="post"
                              onsubmit="return confirm('Eliminare questa domanda?');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn">Elimina</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Nuova domanda</h2>
    <form action="/quizzes/<?= (int) $quiz['id'] ?>/questions" method="post" class="form" data-question-form>
        <?= Csrf::field() ?>
        <?php
        $formId = 'new-question';
        $question = null;
        $options = [];
        require __DIR__ . '/_question_fields.php';
        ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Aggiungi domanda</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/_question_script.php'; ?>
