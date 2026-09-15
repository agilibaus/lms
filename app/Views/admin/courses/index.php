<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $courses */
/** @var bool $canCreate */
/** @var bool $canDelete */
?>
<div class="page-header">
    <h1>Gestione corsi</h1>
    <p class="page-subtitle">Creazione, pubblicazione e iscrizioni. I contenuti (moduli, lezioni, quiz) si gestiscono dalla scheda del corso.</p>
    <?php if ($canCreate): ?>
        <p><a href="/admin/courses/create" class="btn btn-primary">+ Nuovo corso</a></p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($courses === []): ?>
    <p class="empty-state">Nessun corso.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
        <tr><th>Titolo</th><th>Stato</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($courses as $course): ?>
            <tr>
                <td>
                    <a href="/admin/courses/<?= (int) $course['id'] ?>/edit"><?= htmlspecialchars((string) $course['title']) ?></a>
                    <span class="cell-sub">/<?= htmlspecialchars((string) $course['slug']) ?></span>
                </td>
                <td>
                    <?php if ((int) $course['is_published'] === 1): ?>
                        <span class="badge badge-success">pubblicato</span>
                    <?php else: ?>
                        <span class="badge">bozza</span>
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <a href="/courses/<?= (int) $course['id'] ?>">Contenuti</a>
                    <a href="/reports/courses/<?= (int) $course['id'] ?>">Report</a>
                    <?php if ($canDelete): ?>
                        <form action="/admin/courses/<?= (int) $course['id'] ?>/delete" method="post"
                              onsubmit="return confirm('Eliminare il corso con moduli, lezioni, quiz, iscrizioni e certificati?');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn link-btn-danger">Elimina</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
