<?php

declare(strict_types=1);

/** @var array $course */
?>
<div class="page-header">
    <a href="/" class="back-link">&larr; Tutti i corsi</a>
    <h1><?= htmlspecialchars($course['title']) ?></h1>
</div>

<?php if (!empty($course['description'])): ?>
    <p class="course-description"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
<?php endif; ?>

<div class="empty-state">
    Elenco moduli e lezioni in arrivo.
</div>
