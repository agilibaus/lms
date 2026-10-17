<?php

declare(strict_types=1);

use App\Core\Bacheca;

/**
 * La prima pagina della Bacheca (17/10): un riquadro per categoria, nel
 * colore che le ha dato l'admin («riquadro tenue»: la tinta chiara, un filo
 * del colore in cima, il titolo colorato). Tutto il riquadro porta alla
 * categoria. Sopra, i comandi di chi guarda: lo studente ritrova le sue
 * risorse e ne propone una; chi modera vede quante proposte aspettano;
 * chi pubblica aggiunge una risorsa.
 *
 * @var list<array<string, mixed>> $corsi
 * @var array<string, mixed>|null $corso
 * @var list<array<string, mixed>> $categorie
 * @var bool $partecipa
 * @var int $tenute
 * @var int $daValutare
 * @var bool $puoPubblicare
 * @var bool $gestisceCategorie
 */
?>
<?php require __DIR__ . '/../admin/_flash.php'; ?>
<div class="page-header">
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
    <?php if (count($corsi) > 1): ?>
        <form method="get" action="/bacheca" class="form-inline qa-scegli-corso">
            <label for="bacheca-corso">Corso</label>
            <select id="bacheca-corso" name="corso">
                <?php foreach ($corsi as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $courseId ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $c['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Apri</button>
        </form>
    <?php endif; ?>

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
                <a href="/bacheca/mie?corso=<?= $courseId ?>" class="bacheca-comando">Le mie risorse (<?= $tenute ?>)</a>
                <?php if ($categorie !== []): ?>
                    <a href="/bacheca/condividi?corso=<?= $courseId ?>" class="btn btn-secondary">Condividi una risorsa</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($puoPubblicare && $categorie !== []): ?>
                <a href="/bacheca/nuova?corso=<?= $courseId ?>" class="btn btn-secondary">Nuova risorsa</a>
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
                       style="--cat:<?= $col['primary'] ?>;--cat-soft:<?= $col['soft'] ?>">
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
