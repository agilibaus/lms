<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $course */
/** @var array|null $module */
$isEdit = $module !== null;
// L'id del modulo, 0 quando si sta creando: la vista serve a entrambe le cose,
// e la riga esiste solo nel secondo caso.
$moduleId = (int) ($module['id'] ?? 0);
$action = $isEdit ? '/modules/' . $moduleId : '/courses/' . $course['id'] . '/modules';
?>
<div class="page-header">
    <a href="/courses/<?= (int) $course['id'] ?>" class="back-link">&larr; <?= htmlspecialchars($course['title']) ?></a>
    <h1><?= $isEdit ? 'Modifica modulo' : 'Nuovo modulo' ?></h1>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form action="<?= htmlspecialchars($action) ?>" method="post" class="stacked-form">
    <?= Csrf::field() ?>
    <label for="title">Titolo del modulo</label>
    <input type="text" id="title" name="title" required value="<?= htmlspecialchars($module['title'] ?? '') ?>">

    <label class="checkbox-label">
        <input type="checkbox" name="quiz_required" value="1" <?= !empty($module['quiz_required']) ? 'checked' : '' ?>>
        Questionario obbligatorio: i moduli successivi restano bloccati finché lo studente non supera il questionario di questo modulo
    </label>

    <?php /* Il valore in tabella e' "2026-11-15 09:00:00", il campo del
             browser vuole "2026-11-15T09:00": la T al posto dello spazio e
             senza i secondi. */ ?>
    <?php
    $availableFrom = $module['available_from'] ?? null;
    $availableFromInput = $availableFrom === null
        ? ''
        : str_replace(' ', 'T', substr((string) $availableFrom, 0, 16));
    ?>
    <label for="available_from">Disponibile dal</label>
    <input type="datetime-local" id="available_from" name="available_from"
           value="<?= htmlspecialchars($availableFromInput) ?>"
           aria-describedby="available_from_aiuto">
    <p class="hint" id="available_from_aiuto">
        Lascia vuoto per tenere il modulo aperto da subito. Con una data, gli studenti
        vedono il titolo del modulo in grigio con l’indicazione di quando si aprirà, e
        non possono aprirne le lezioni, i questionari, i materiali né gli incontri dal vivo.
        La data è uguale per tutti gli studenti e il giorno dell’apertura ricevono un’email.
    </p>

    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salva' : 'Crea modulo' ?></button>
</form>
