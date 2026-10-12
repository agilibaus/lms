<?php

declare(strict_types=1);

use App\Controllers\GroupPageController;
use App\Core\GiroDelCerchio;

/**
 * Le domande passate del giro del cerchio (12/10), dalla piu' recente: si
 * leggono e basta. Le risposte nascoste seguono la stessa regola della
 * pagina del gruppo.
 *
 * @var array $group
 * @var list<array<string, mixed>> $domande
 * @var bool $modera
 * @var int $chiGuarda
 */
?>
<div class="page-header">
    <a href="/gruppi/<?= (int) $group['id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars(GroupPageController::titolo((string) $group['name'])) ?></span></a>
    <h1>Domande passate</h1>
    <p class="page-subtitle">Le domande del giro del cerchio a cui il gruppo ha già risposto, dalla più recente.</p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if ($domande === []): ?>
    <p class="empty-state">Non ci sono ancora domande passate.</p>
<?php endif; ?>

<?php foreach ($domande as $q): ?>
    <section class="card giro" aria-labelledby="domanda-<?= (int) $q['id'] ?>">
        <p class="gruppo-etichetta">
            <?= htmlspecialchars(GiroDelCerchio::chiChiede($q['author_first_name'] ?? null)) ?>
            · <?= htmlspecialchars(date('d/m/Y', strtotime((string) $q['created_at']))) ?>
        </p>
        <h2 class="giro-domanda" id="domanda-<?= (int) $q['id'] ?>"><?= nl2br(htmlspecialchars((string) $q['question']), false) ?></h2>
        <?php $risposte = $q['risposte']; ?>
        <?php if ($risposte === []): ?>
            <p class="giro-conto">Nessuna risposta.</p>
        <?php endif; ?>
        <?php require __DIR__ . '/_risposte.php'; ?>
    </section>
<?php endforeach; ?>
