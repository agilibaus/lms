<?php

declare(strict_types=1);

use App\Auth\Auth;

/** @var array $quiz */
/** @var array $module */
/** @var array|null $course */
/** @var array $questions */
/** @var array<int, array> $optionsByQuestion */
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
        <ol class="quiz-question-list">
            <?php foreach ($questions as $question): ?>
                <li class="quiz-question">
                    <p class="quiz-question-text"><?= htmlspecialchars($question['question_text']) ?></p>
                    <?php foreach ($optionsByQuestion[$question['id']] ?? [] as $option): ?>
                        <label class="quiz-option">
                            <input type="radio"
                                   name="answers[<?= (int) $question['id'] ?>]"
                                   value="<?= (int) $option['id'] ?>" required>
                            <span><?= htmlspecialchars($option['option_text']) ?></span>
                        </label>
                    <?php endforeach; ?>
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
