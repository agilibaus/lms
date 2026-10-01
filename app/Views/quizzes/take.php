<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Controllers\QuizController;
use App\Core\Csrf;
use App\Core\QuizScoring;

/** @var array $quiz */
/** @var array $module */
/** @var array|null $course */
/** @var array $questions */
/** @var array<int, array> $optionsByQuestion */
/** @var array<int, int> $correctCountByQuestion quante risposte corrette ha ogni domanda */
/** @var array $attempts */
/** @var array|null $best */
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link">&larr; <?= htmlspecialchars($course['title'] ?? 'Corso') ?></a>
    <h1><?= htmlspecialchars($quiz['title']) ?></h1>
    <p class="page-subtitle">
        Modulo: <?= htmlspecialchars($module['title']) ?> ·
        soglia di superamento <?= (int) $quiz['passing_score_pct'] ?>% ·
        tentativi illimitati
    </p>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if ($best !== null): ?>
    <div class="alert <?= $best['passed'] ? 'alert-success' : 'alert-warning' ?>">
        Miglior risultato: <?= number_format($best['score_pct'], 0) ?>%
        (<?= $best['passed'] ? 'superato' : 'non superato' ?>) su <?= (int) $best['attempts'] ?> tentativi.
    </div>
<?php endif; ?>

<?php if ($questions === []): ?>
    <p class="empty-state">Questo quiz non ha ancora domande.</p>
<?php else: ?>
    <form action="/quizzes/<?= (int) $quiz['id'] ?>/attempts" method="post" class="quiz-form">
        <?= Csrf::field() ?>
        <ol class="quiz-question-list">
            <?php foreach ($questions as $indice => $question): ?>
                <?php
                $qid = (int) $question['id'];
                $tipo = (string) $question['question_type'];
                $opzioni = $optionsByQuestion[$qid] ?? [];
                $quante = (int) ($correctCountByQuestion[$qid] ?? 0);
                ?>
                <li class="quiz-question">
                    <?php /* `fieldset` e non solo un paragrafo: le opzioni di una
                             domanda sono un gruppo, e un lettore di schermo deve
                             annunciare la domanda prima di ogni risposta. */ ?>
                    <fieldset class="quiz-question-group">
                        <legend class="quiz-question-text">
                            <?php /* Il numero sta dentro la legenda e non nel
                                     segnalino della lista: un `fieldset` non
                                     mette il segnalino accanto alla domanda ma
                                     a meta' del blocco, perche' la legenda non
                                     fa parte del flusso normale. Qui il numero
                                     viene letto insieme alla domanda, che e'
                                     anche l'ordine in cui serve sentirlo. */ ?>
                            <span class="quiz-question-numero"><?= (int) $indice + 1 ?>.</span>
                            <?= htmlspecialchars($question['question_text']) ?>
                            <?php if ($tipo === 'multiple_choice' && $quante > 1): ?>
                                <?php /* Quante risposte aspettarsi: senza, «tutto o
                                         niente» diventa un indovinello. */ ?>
                                <span class="quiz-question-hint">Seleziona <?= $quante ?> risposte</span>
                            <?php elseif ($tipo === 'open'): ?>
                                <span class="quiz-question-hint">Risposta libera, non fa punteggio</span>
                            <?php endif; ?>
                        </legend>

                        <?php if ($tipo === 'open'): ?>
                            <label class="sr-only" for="aperta-<?= $qid ?>">
                                La tua risposta a: <?= htmlspecialchars($question['question_text']) ?>
                            </label>
                            <textarea id="aperta-<?= $qid ?>" name="open[<?= $qid ?>]" rows="4"
                                      maxlength="<?= QuizController::MAX_OPEN_CHARS ?>" required
                                      class="quiz-open-answer"></textarea>
                        <?php else: ?>
                            <?php foreach ($opzioni as $option): ?>
                                <label class="quiz-option">
                                    <?php if (QuizScoring::piuRisposte($tipo)): ?>
                                        <?php /* Niente `required` sulle caselle: il browser
                                                 lo pretenderebbe su ognuna, cioe' tutte
                                                 spuntate. Che si risponda a tutto lo
                                                 verifica il server. */ ?>
                                        <input type="checkbox"
                                               name="answers[<?= $qid ?>][]"
                                               value="<?= (int) $option['id'] ?>">
                                    <?php else: ?>
                                        <input type="radio"
                                               name="answers[<?= $qid ?>]"
                                               value="<?= (int) $option['id'] ?>" required>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($option['option_text']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if (Auth::hasRole('studente')): ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Invia risposte</button>
            </div>
        <?php else: ?>
            <p class="form-hint">Anteprima per lo staff: l'invio è riservato agli studenti iscritti.</p>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php if ($attempts !== []): ?>
    <section class="card">
        <h2>I tuoi tentativi</h2>
        <table class="data-table">
            <thead>
            <tr><th>Data</th><th>Punteggio</th><th>Esito</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($attempts as $attempt): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $attempt['attempted_at']) ?></td>
                    <td><?= number_format((float) $attempt['score_pct'], 0) ?>%</td>
                    <td><?= (int) $attempt['passed'] === 1 ? 'Superato' : 'Non superato' ?></td>
                    <td><a href="/attempts/<?= (int) $attempt['id'] ?>">Dettaglio</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
