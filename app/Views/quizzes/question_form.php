<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $question */
/** @var array $options */
/** @var array|null $quiz */
/** @var int $maxOptions */
?>
<div class="page-header">
    <a href="/quizzes/<?= (int) $question['quiz_id'] ?>/edit" class="back-link">&larr; <?= htmlspecialchars($quiz['title'] ?? 'Quiz') ?></a>
    <h1>Modifica domanda</h1>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form action="/questions/<?= (int) $question['id'] ?>" method="post" class="form" data-question-form>
    <?= Csrf::field() ?>
    <?php
    $formId = 'edit-question';
    require __DIR__ . '/_question_fields.php';
    ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva domanda</button>
        <a href="/quizzes/<?= (int) $question['quiz_id'] ?>/edit" class="btn btn-secondary">Annulla</a>
    </div>
</form>

<?php require __DIR__ . '/_question_script.php'; ?>
