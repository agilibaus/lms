<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $module */
/** @var array|null $course */
/** @var array|null $quiz */
/** @var bool $h5pDisponibile si puo' caricare un contenuto H5P (estensione zip) */
$h5pDisponibile ??= false;
/** @var bool $h5pAmmesso il modulo non e' ne' obbligatorio ne' la verifica finale (29/10) */
$h5pAmmesso ??= true;
/** @var int $maxOptions */
$maxOptions ??= 6;
/** @var array|null $riprendi la pagina rifiutata, con la prima domanda (`PrimaDomanda::riprendi`) */
$riprendi ??= null;
$campi = $riprendi['campi'] ?? [];

$action = $quiz === null
    ? '/modules/' . (int) $module['id'] . '/quiz'
    : '/quizzes/' . (int) $quiz['id'];
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars($course['title'] ?? 'Corso') ?></span></a>
    <h1><?= $quiz === null ? 'Nuovo questionario' : 'Impostazioni del questionario' ?></h1>
    <p class="page-subtitle">Modulo: <?= htmlspecialchars($module['title']) ?></p>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form action="<?= $action ?>" method="post" class="form" enctype="multipart/form-data"<?= $quiz === null ? ' data-question-form' : '' ?>>
    <?= Csrf::field() ?>
    <?php /* Che questionario (22/10): le domande scritte qui, come finora, o un
             contenuto interattivo H5P creato con Lumi. Il campo del file si
             vede solo scegliendo H5P (CSS, `:has`); senza, resta visibile
             e il server lo ignora per un questionario a domande. */ ?>
    <?php if ($quiz === null): ?>
        <fieldset class="quiz-tipo">
            <legend>Tipo di questionario</legend>
            <label class="quiz-tipo-scelta">
                <input type="radio" name="kind" value="domande" checked>
                <span><strong>Domande</strong> <span class="form-hint">La prima la scrivi qui, le altre dopo, nella pagina del questionario.</span></span>
            </label>
            <label class="quiz-tipo-scelta">
                <input type="radio" name="kind" value="h5p" <?= $h5pDisponibile && $h5pAmmesso ? '' : 'disabled' ?>>
                <span><strong>Contenuto H5P</strong> <span class="form-hint">Un esercizio interattivo creato con Lumi (file .h5p): il punteggio lo calcola l'esercizio.</span></span>
            </label>
            <?php if (!$h5pAmmesso): ?>
                <p class="form-hint">Non disponibile in questo modulo: <?= !empty($module['is_final']) ? 'la verifica finale' : 'il questionario è obbligatorio, e' ?> deve essere a domande, corrette da Pistacchio.</p>
            <?php elseif (!$h5pDisponibile): ?>
                <p class="form-hint">Per i contenuti H5P serve l'estensione zip di PHP, che su questo server non c'è.</p>
            <?php endif; ?>
            <div class="quiz-tipo-file">
                <label for="h5p">File .h5p</label>
                <input type="file" id="h5p" name="h5p" accept=".h5p" aria-describedby="h5p-aiuto">
                <p class="form-hint" id="h5p-aiuto">Serve un esercizio con domande, che dà un punteggio (Question Set, Drag the Words, Fill in the Blanks…): i contenuti da guardare o esplorare, come Accordion o Timeline, non si caricano come questionario. In Lumi: «Esporta», formato H5P. Conviene impostare l'italiano prima di esportare, così anche i pulsanti dell'esercizio sono in italiano.</p>
            </div>
        </fieldset>
    <?php endif; ?>

    <label for="title">Titolo del questionario</label>
    <input type="text" id="title" name="title" maxlength="200" required
           value="<?= htmlspecialchars((string) ($campi['title'] ?? $quiz['title'] ?? '')) ?>">

    <label for="passing_score_pct">Punteggio minimo per superarlo (%)</label>
    <input type="number" id="passing_score_pct" name="passing_score_pct" min="1" max="100"
           value="<?= (int) ($campi['passing_score_pct'] ?? $quiz['passing_score_pct'] ?? 70) ?>">

    <p class="form-hint">
        I tentativi sono illimitati: allo studente viene conteggiato il punteggio migliore.
    </p>

    <?php /* La prima domanda (30/10, Alessandro): un questionario a domande
             nasce con almeno una, o non nasce. Il riquadro e' quello di
             «Video» nella lezione; scegliendo «Contenuto H5P» sparisce e non
             si invia (prima-domanda.js). */ ?>
    <?php if ($quiz === null): ?>
        <?php require __DIR__ . '/_prima_domanda.php'; ?>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $quiz === null ? 'Crea questionario' : 'Salva' ?></button>
        <a href="/courses/<?= (int) $module['course_id'] ?>" class="btn btn-secondary">Annulla</a>
    </div>
</form>
