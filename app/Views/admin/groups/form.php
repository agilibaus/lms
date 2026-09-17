<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array|null $group */
/** @var array $tutors */
/** @var bool $canChooseTutor */
?>
<div class="page-header">
    <a href="/admin/groups" class="back-link">&larr; Gruppi</a>
    <h1>Nuovo gruppo</h1>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/groups" method="post" class="form" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <?php require __DIR__ . '/_fields.php'; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Crea gruppo</button>
        <a href="/admin/groups" class="btn btn-secondary">Annulla</a>
    </div>
</form>
