<?php

declare(strict_types=1);

use App\Core\Bacheca;
use App\Core\Icona;

/**
 * La prima pagina della Bacheca (17/10): un riquadro per categoria, nel
 * colore che le ha dato l'admin («riquadro tenue»: la tinta chiara, un filo
 * del colore in cima, il titolo colorato). Tutto il riquadro porta alla
 * categoria. Sopra, i comandi di chi guarda: lo studente ritrova le sue
 * risorse e ne propone una; chi modera vede quante proposte aspettano;
 * chi pubblica aggiunge una risorsa.
 *
 * @var array<string, mixed>|null $corso
 * @var list<array<string, mixed>> $categorie
 * @var bool $partecipa
 * @var bool $propone propone risorse: interruttore «Proposte in Bacheca» (26/10)
 * @var int $tenute
 * @var int $daValutare
 * @var bool $puoPubblicare
 * @var bool $gestisceCategorie
 */
?>
<?php require __DIR__ . '/../admin/_flash.php'; ?>
<div class="page-header">
    <?php /* Il corso si sceglie entrando nel corso (17/10, variante C): qui
             niente menu dei corsi, ma il ritorno al corso di questa bacheca. */ ?>
    <?php if ($corso !== null): ?>
        <a href="/courses/<?= (int) $corso['id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars((string) $corso['title']) ?></span></a>
    <?php endif; ?>
    <h1>Bacheca</h1>
    <p class="page-subtitle">
        <?= $corso !== null && !$partecipa
            ? 'Le risorse del corso, per gli iscritti di tutti i gruppi.'
            : 'Risorse e strumenti da consultare quando ti servono, durante e dopo il corso.' ?>
    </p>
</div>

<?php if ($corso === null): ?>
    <p class="empty-state">Non ci sono ancora bacheche da mostrare.</p>
<?php else: ?>
    <?php $courseId = (int) $corso['id']; ?>
    <?php if ($daValutare > 0): ?>
        <div class="bacheca-avviso">
            <span><strong><?= htmlspecialchars(Bacheca::daValutare($daValutare)) ?></strong></span>
            <a href="/bacheca/proposte?corso=<?= $courseId ?>" class="btn btn-primary">Valuta</a>
        </div>
    <?php endif; ?>

    <?php if ($partecipa || $puoPubblicare || $gestisceCategorie): ?>
        <div class="bacheca-comandi">
            <?php if ($gestisceCategorie): ?>
                <a href="/admin/courses/<?= $courseId ?>/edit#bacheca" class="bacheca-comando">Gestione categorie</a>
            <?php endif; ?>
            <?php if ($partecipa): ?>
                <a href="/bacheca/mie?corso=<?= $courseId ?>" class="bacheca-comando comando-con-icona"><?= Icona::svg('bookmark') ?>Le mie risorse (<?= $tenute ?>)</a>
                <?php if ($propone && $categorie !== []): ?>
                    <a href="/bacheca/condividi?corso=<?= $courseId ?>" class="btn btn-secondary btn-con-icona"><?= Icona::svg('plus-lg') ?>Condividi una risorsa</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($puoPubblicare && $categorie !== []): ?>
                <a href="/bacheca/nuova?corso=<?= $courseId ?>" class="btn btn-secondary btn-con-icona"><?= Icona::svg('plus-lg') ?>Nuova risorsa</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($categorie === []): ?>
        <p class="empty-state">
            La bacheca di questo corso non ha ancora categorie<?= $gestisceCategorie ? ': si creano da «Gestione categorie».' : '.' ?>
        </p>
    <?php else: ?>
        <ul class="bacheca-griglia" role="list">
            <?php foreach ($categorie as $c): ?>
                <?php $col = Bacheca::colore((string) $c['color']); ?>
                <li>
                    <a href="/bacheca/categorie/<?= (int) $c['id'] ?>" class="bacheca-riquadro"
                       style="--cat:<?= $col['primary'] ?>;--cat-soft:<?= $col['soft'] ?>;--cat-titolo:<?= $col['titolo'] ?>">
                        <h2 class="bacheca-riquadro-titolo"><?= htmlspecialchars((string) $c['name']) ?></h2>
                        <?php if (!empty($c['description'])): ?>
                            <span class="bacheca-riquadro-desc"><?= htmlspecialchars((string) $c['description']) ?></span>
                        <?php endif; ?>
                        <span class="bacheca-riquadro-conto"><?= htmlspecialchars(Bacheca::conta((int) $c['pubblicate'])) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
