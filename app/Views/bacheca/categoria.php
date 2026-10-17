<?php

declare(strict_types=1);

use App\Auth\BachecaScope;
use App\Core\Bacheca;

/**
 * La pagina di una categoria (17/10), con la testata nel suo colore come
 * il riquadro da cui si arriva, e le schede «Tutte» e «Le mie risorse».
 * Senza categoria e' «Le mie risorse» di tutto il corso: le risorse che uno
 * si tiene, con il nome della categoria accanto.
 *
 * @var array<string, mixed> $corso
 * @var array<string, mixed>|null $categoria
 * @var bool $mie
 * @var array{tutte: int, mie: int} $conteggi
 * @var list<array<string, mixed>> $risorse
 * @var string $qui
 */

$courseId = (int) $corso['id'];
$conCategoria = $categoria === null;
?>
<?php require __DIR__ . '/../admin/_flash.php'; ?>
<?php if ($categoria !== null): ?>
    <?php
    $col = Bacheca::colore((string) $categoria['color']);
    $base = '/bacheca/categorie/' . (int) $categoria['id'];
    ?>
    <a href="/bacheca?corso=<?= $courseId ?>" class="back-link"><span class="back-link-testo">Bacheca</span></a>
    <div class="bacheca-cat-testa" style="--cat:<?= $col['primary'] ?>;--cat-soft:<?= $col['soft'] ?>">
        <h1><?= htmlspecialchars((string) $categoria['name']) ?></h1>
        <?php if (!empty($categoria['description'])): ?>
            <p><?= htmlspecialchars((string) $categoria['description']) ?></p>
        <?php endif; ?>
    </div>

    <div class="bacheca-sotto-testa">
        <nav class="bacheca-filtri" aria-label="Quali risorse">
            <a href="<?= $base ?>" class="bacheca-filtro<?= $mie ? '' : ' attivo" aria-current="page' ?>">Tutte (<?= $conteggi['tutte'] ?>)</a>
            <a href="<?= $base ?>?mie=1" class="bacheca-filtro<?= $mie ? ' attivo" aria-current="page' : '' ?>">Le mie risorse (<?= $conteggi['mie'] ?>)</a>
        </nav>
        <?php if (BachecaScope::puoPubblicare($courseId)): ?>
            <a href="/bacheca/nuova?corso=<?= $courseId ?>&amp;categoria=<?= (int) $categoria['id'] ?>" class="btn btn-secondary">Nuova risorsa</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="page-header">
        <a href="/bacheca?corso=<?= $courseId ?>" class="back-link"><span class="back-link-testo">Bacheca</span></a>
        <h1>Le mie risorse</h1>
        <p class="page-subtitle">Le risorse che hai deciso di tenere, in tutte le categorie. Le vedi solo tu.</p>
    </div>
<?php endif; ?>

<?php if ($risorse === []): ?>
    <p class="empty-state">
        <?= $mie
            ? 'Non hai ancora tenuto nessuna risorsa' . ($conCategoria ? '' : ' di questa categoria') . '. Premi «Questa me la tengo» sotto quelle che vuoi ritrovare.'
            : 'Ancora nessuna risorsa in questa categoria.' ?>
    </p>
<?php else: ?>
    <div class="card bacheca-sezione">
        <ul class="bacheca-elenco" role="list">
            <?php foreach ($risorse as $r): ?>
                <?php require __DIR__ . '/_risorsa.php'; ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<script src="/assets/js/quiz-open-count.js"></script>
