<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array|null $course */
?>
<div class="page-header">
    <a href="/admin/courses" class="back-link">&larr; Gestione corsi</a>
    <h1>Nuovo corso</h1>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/courses" method="post" class="form">
    <?= Csrf::field() ?>
    <?php require __DIR__ . '/_fields.php'; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Crea corso</button>
        <a href="/admin/courses" class="btn btn-secondary">Annulla</a>
    </div>
</form>
