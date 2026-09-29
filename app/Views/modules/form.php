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
        Quiz obbligatorio: i moduli successivi restano bloccati finché lo studente non supera il quiz di questo modulo
    </label>

    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salva' : 'Crea modulo' ?></button>
</form>
