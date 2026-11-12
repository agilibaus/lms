<?php

declare(strict_types=1);

/**
 * Le categorie della bacheca di un corso, in una pagina sua (06/11, variante
 * 2A del mockup): lo stesso riquadro della scheda del corso, raggiungibile da
 * «Gestione categorie» nella bacheca. Serve al supervisore, che la scheda di
 * un corso pubblicato non la apre; le azioni sulle categorie tornano qui.
 *
 * @var array $course
 * @var list<array<string, mixed>> $bacheca
 */

$tornaBacheca = true;
$titoloRiquadro = 'Categorie';
?>
<?php require __DIR__ . '/../admin/_flash.php'; ?>
<div class="page-header">
    <a href="/bacheca?corso=<?= (int) $course['id'] ?>" class="back-link"><span class="back-link-testo">Bacheca di <?= htmlspecialchars((string) $course['title']) ?></span></a>
    <h1>Categorie della bacheca</h1>
    <p class="page-subtitle"><?= htmlspecialchars((string) $course['title']) ?></p>
</div>

<?php require __DIR__ . '/../admin/courses/_bacheca.php'; ?>
