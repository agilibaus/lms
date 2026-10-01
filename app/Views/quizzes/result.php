<?php

declare(strict_types=1);

/** @var array $attempt */
/** @var array $quiz */
/** @var array $module */
/** @var array|null $course */
/** @var int $questionCount */
/** @var int $scoredCount domande che fanno punteggio (le aperte non ci sono) */
/** @var list<array{question_text: string, answer_text: string}> $openAnswers */
/** @var array|null $best */

$passed = (int) $attempt['passed'] === 1;
$scorePct = (float) $attempt['score_pct'];

// Le risposte giuste si ricavano dal punteggio, che è calcolato sulle sole
// domande valutate: il denominatore dev'essere quello, altrimenti il conto
// non torna in un quiz che contiene domande aperte.
$correct = (int) round($scorePct / 100 * $scoredCount);

// Un quiz di sole domande aperte non si supera né si fallisce: si consegna.
$soloAperte = $scoredCount === 0;
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link">&larr; <?= htmlspecialchars($course['title'] ?? 'Corso') ?></a>
    <h1>Esito: <?= htmlspecialchars($quiz['title']) ?></h1>
</div>

<?php if ($soloAperte): ?>
    <?php /* Nessun punteggio da mostrare: farebbe credere a una valutazione
             che non c'è stata. */ ?>
    <div class="result-panel result-passed">
        <p class="result-label">Quiz consegnato</p>
        <p class="result-detail">
            Questo quiz è fatto di sole domande aperte: non assegna un punteggio.
            Le risposte sono state registrate e le leggerà il tutor.
        </p>
    </div>
<?php else: ?>
    <div class="result-panel <?= $passed ? 'result-passed' : 'result-failed' ?>">
        <p class="result-score"><?= number_format($scorePct, 0) ?>%</p>
        <p class="result-label"><?= $passed ? 'Quiz superato' : 'Quiz non superato' ?></p>
        <p class="result-detail">
            <?= $correct ?> risposte corrette su <?= (int) $scoredCount ?> ·
            soglia richiesta <?= (int) $quiz['passing_score_pct'] ?>%
            <?php if ($questionCount > $scoredCount): ?>
                <br>
                <?= (int) ($questionCount - $scoredCount) ?>
                <?= $questionCount - $scoredCount === 1 ? 'domanda aperta non entra' : 'domande aperte non entrano' ?>
                nel punteggio.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php if ($openAnswers !== []): ?>
    <section class="card">
        <h2>Le risposte aperte</h2>
        <p class="form-hint">
            Non fanno punteggio: sono state registrate e le legge il tutor.
        </p>
        <dl class="risposte-aperte">
            <?php foreach ($openAnswers as $aperta): ?>
                <div>
                    <dt><?= htmlspecialchars($aperta['question_text']) ?></dt>
                    <dd><?= nl2br(htmlspecialchars($aperta['answer_text'])) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>

<?php if ($best !== null && !$passed): ?>
    <p class="form-hint">
        Miglior risultato finora: <?= number_format($best['score_pct'], 0) ?>%
        su <?= (int) $best['attempts'] ?> tentativi. I tentativi sono illimitati.
    </p>
<?php endif; ?>

<div class="form-actions">
    <?php if (!$passed && !$soloAperte): ?>
        <a href="/quizzes/<?= (int) $quiz['id'] ?>" class="btn btn-primary">Riprova il quiz</a>
    <?php endif; ?>
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="btn btn-secondary">Torna al corso</a>
</div>
