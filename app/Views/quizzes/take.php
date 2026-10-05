<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\CourseRights;
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

<?php /* Lo stesso pulsante che la pagina della lezione ha da sempre, e che
         qui mancava: da un contenuto si arriva a modificarlo. Senza, l'unica
         via per modificare o eliminare un quiz era un collegamento
         nell'intestazione del modulo, e chi partiva dal quiz non la trovava.
         Il permesso e' quello del corso, come per la lezione. */ ?>
<?php if ($course !== null && CourseRights::canEdit((int) $course['id'])): ?>
    <p><a href="/quizzes/<?= (int) $quiz['id'] ?>/edit" class="btn btn-primary">Modifica questionario</a></p>
<?php endif; ?>

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
    <p class="empty-state">Questo questionario non ha ancora domande.</p>
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
                            <?php /* Il contatore sta **prima** del campo nel
                                     documento, perche' li' sta anche sullo
                                     schermo — sopra, a destra — e perche' un
                                     lettore di schermo lo annuncia come
                                     descrizione del campo quando ci si entra,
                                     grazie ad `aria-describedby`.

                                     Niente `aria-live`: un contatore che parla
                                     a ogni tasto e' esattamente il caso che le
                                     linee guida classificano come «passivo»,
                                     da leggere solo andandoci sopra. Qui
                                     annunciarlo di continuo coprirebbe quello
                                     che la persona sta scrivendo.

                                     Senza JavaScript resta scritto «3000
                                     caratteri rimasti», che a campo vuoto e'
                                     vero: degrada in una dichiarazione del
                                     limite, e il limite lo fa comunque
                                     rispettare `maxlength`. */ ?>
                            <div class="quiz-open-wrap">
                                <span class="quiz-open-count" id="resta-<?= $qid ?>"
                                      data-max="<?= QuizController::MAX_OPEN_CHARS ?>">
                                    <?= QuizController::MAX_OPEN_CHARS ?> caratteri rimasti
                                </span>
                                <textarea id="aperta-<?= $qid ?>" name="open[<?= $qid ?>]" rows="4"
                                          maxlength="<?= QuizController::MAX_OPEN_CHARS ?>" required
                                          aria-describedby="resta-<?= $qid ?>"
                                          class="quiz-open-answer"></textarea>
                            </div>
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

    <?php /* In fondo e non in testa: lo script cerca i campi, quindi deve
             trovarli gia' nel documento. Caricato solo se il quiz ha delle
             domande, cioe' dove c'e' il modulo. */ ?>
    <script src="/assets/js/quiz-open-count.js"></script>
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
