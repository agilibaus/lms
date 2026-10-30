<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * «Nuova verifica finale» (30/10, Alessandro): il modulo, il suo
 * questionario e la prima domanda, salvati insieme. I due titoli sono gia'
 * scritti e si possono cambiare; obiettivo no, la data dopo, da «Modulo».
 * Sempre a domande: la scelta H5P qui non c'e'.
 *
 * @var array $course
 * @var array|null $riprendi
 * @var int $maxOptions
 * @var string $titoloVerifica
 * @var string $titoloQuestionario
 */
$campi = $riprendi['campi'] ?? [];
?>
<div class="page-header">
    <a href="/courses/<?= (int) $course['id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars($course['title']) ?></span></a>
    <h1>Nuova verifica finale</h1>
    <p class="page-subtitle">
        Il questionario che chiude il corso. Sta sempre in fondo, si apre a chi ha completato tutti gli altri moduli,
        e ce n’è una sola per corso.
    </p>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form action="/courses/<?= (int) $course['id'] ?>/verifica-finale" method="post" class="form" data-question-form>
    <?= Csrf::field() ?>

    <label for="title">Titolo della verifica</label>
    <input type="text" id="title" name="title" maxlength="200" required aria-describedby="title_aiuto"
           value="<?= htmlspecialchars((string) ($campi['title'] ?? $titoloVerifica)) ?>">
    <p class="form-hint" id="title_aiuto">È il nome del modulo. La data di apertura, se serve, si aggiunge dopo, da «Modulo».</p>

    <label for="quiz_title">Titolo del questionario</label>
    <input type="text" id="quiz_title" name="quiz_title" maxlength="200" required aria-describedby="quiz_title_aiuto"
           value="<?= htmlspecialchars((string) ($campi['quiz_title'] ?? $titoloQuestionario)) ?>">
    <p class="form-hint" id="quiz_title_aiuto">È il nome che vede lo studente quando apre il questionario.</p>

    <label for="passing_score_pct">Punteggio minimo per superarlo (%)</label>
    <input type="number" id="passing_score_pct" name="passing_score_pct" min="1" max="100"
           value="<?= (int) ($campi['passing_score_pct'] ?? 70) ?>">

    <p class="form-hint">
        I tentativi sono illimitati: allo studente viene conteggiato il punteggio migliore.
    </p>

    <?php require __DIR__ . '/../quizzes/_prima_domanda.php'; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Crea verifica finale</button>
        <a href="/courses/<?= (int) $course['id'] ?>" class="btn btn-secondary">Annulla</a>
    </div>
</form>
