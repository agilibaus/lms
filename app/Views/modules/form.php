<?php

declare(strict_types=1);

/** @var array $course */
/** @var array|null $module */
$isEdit = $module !== null;
$action = $isEdit ? '/modules/' . $module['id'] : '/courses/' . $course['id'] . '/modules';
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
    <label for="title">Titolo del modulo</label>
    <input type="text" id="title" name="title" required value="<?= htmlspecialchars($module['title'] ?? '') ?>">

    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salva' : 'Crea modulo' ?></button>
</form>
