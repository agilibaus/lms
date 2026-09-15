<?php

declare(strict_types=1);

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
    <div class="alert alert-warning">Il quiz non ha ancora domande: non è somministrabile agli studenti.</div>
<?php elseif ($incompleteQuestions > 0): ?>
    <div class="alert alert-warning">
        <?= (int) $incompleteQuestions ?> domande non hanno una risposta corretta impostata.
    </div>
<?php endif; ?>

<section class="card">
    <h2>Impostazioni</h2>
    <form action="/quizzes/<?= (int) $quiz['id'] ?>" method="post" class="form form-inline">
        <input type="text" name="title" maxlength="200" required value="<?= htmlspecialchars($quiz['title']) ?>"
               aria-label="Titolo del quiz">
        <input type="number" name="passing_score_pct" min="1" max="100"
               value="<?= (int) $quiz['passing_score_pct'] ?>" aria-label="Punteggio minimo (%)">
        <button type="submit" class="btn btn-secondary">Salva</button>
    </form>

    <form action="/quizzes/<?= (int) $quiz['id'] ?>/delete" method="post" class="danger-zone"
          onsubmit="return confirm('Eliminare il quiz, le sue domande e tutti i tentativi degli studenti?');">
        <button type="submit" class="link-btn link-btn-danger">Elimina quiz</button>
    </form>
</section>

<section class="card">
    <h2>Domande</h2>

    <?php if ($questions === []): ?>
        <p class="empty-state-small">Nessuna domanda.</p>
    <?php else: ?>
        <ol class="question-list">
            <?php foreach ($questions as $question): ?>
                <li class="question-item">
                    <div class="question-item-head">
                        <span class="question-text"><?= htmlspecialchars($question['question_text']) ?></span>
                        <span class="badge"><?= $question['question_type'] === 'true_false' ? 'V/F' : 'scelta singola' ?></span>
                    </div>
                    <ul class="option-preview">
                        <?php foreach ($optionsByQuestion[$question['id']] ?? [] as $option): ?>
                            <li class="<?= (int) $option['is_correct'] === 1 ? 'is-correct' : '' ?>">
                                <?= htmlspecialchars($option['option_text']) ?>
                                <?= (int) $option['is_correct'] === 1 ? ' &check;' : '' ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="question-item-actions">
                        <a href="/questions/<?= (int) $question['id'] ?>/edit">Modifica</a>
                        <form action="/questions/<?= (int) $question['id'] ?>/delete" method="post"
                              onsubmit="return confirm('Eliminare questa domanda?');">
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
