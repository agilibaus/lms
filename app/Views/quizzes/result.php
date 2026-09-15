<?php

declare(strict_types=1);

/** @var array $attempt */
/** @var array $quiz */
/** @var array $module */
/** @var array|null $course */
/** @var int $questionCount */
/** @var array|null $best */

$passed = (int) $attempt['passed'] === 1;
$scorePct = (float) $attempt['score_pct'];
$correct = (int) round($scorePct / 100 * $questionCount);
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link">&larr; <?= htmlspecialchars($course['title'] ?? 'Corso') ?></a>
    <h1>Esito: <?= htmlspecialchars($quiz['title']) ?></h1>
</div>

<div class="result-panel <?= $passed ? 'result-passed' : 'result-failed' ?>">
    <p class="result-score"><?= number_format($scorePct, 0) ?>%</p>
    <p class="result-label"><?= $passed ? 'Quiz superato' : 'Quiz non superato' ?></p>
    <p class="result-detail">
        <?= $correct ?> risposte corrette su <?= (int) $questionCount ?> ·
        soglia richiesta <?= (int) $quiz['passing_score_pct'] ?>%
    </p>
</div>

<?php if ($best !== null && !$passed): ?>
    <p class="form-hint">
        Miglior risultato finora: <?= number_format($best['score_pct'], 0) ?>%
        su <?= (int) $best['attempts'] ?> tentativi. I tentativi sono illimitati.
    </p>
<?php endif; ?>

<div class="form-actions">
    <?php if (!$passed): ?>
        <a href="/quizzes/<?= (int) $quiz['id'] ?>" class="btn btn-primary">Riprova il quiz</a>
    <?php endif; ?>
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="btn btn-secondary">Torna al corso</a>
</div>
