<?php

declare(strict_types=1);

/** @var array $module */
/** @var array|null $course */
/** @var array|null $quiz */

$action = $quiz === null
    ? '/modules/' . (int) $module['id'] . '/quiz'
    : '/quizzes/' . (int) $quiz['id'];
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link">&larr; <?= htmlspecialchars($course['title'] ?? 'Corso') ?></a>
    <h1><?= $quiz === null ? 'Nuovo quiz' : 'Impostazioni quiz' ?></h1>
    <p class="page-subtitle">Modulo: <?= htmlspecialchars($module['title']) ?></p>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form action="<?= $action ?>" method="post" class="form">
    <label for="title">Titolo del quiz</label>
    <input type="text" id="title" name="title" maxlength="200" required
           value="<?= htmlspecialchars($quiz['title'] ?? '') ?>">

    <label for="passing_score_pct">Punteggio minimo per superarlo (%)</label>
    <input type="number" id="passing_score_pct" name="passing_score_pct" min="1" max="100"
           value="<?= (int) ($quiz['passing_score_pct'] ?? 70) ?>">

    <p class="form-hint">
        I tentativi sono illimitati: allo studente viene conteggiato il punteggio migliore.
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $quiz === null ? 'Crea quiz' : 'Salva' ?></button>
        <a href="/courses/<?= (int) $module['course_id'] ?>" class="btn btn-secondary">Annulla</a>
    </div>
</form>
