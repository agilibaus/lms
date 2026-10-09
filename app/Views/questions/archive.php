<?php

declare(strict_types=1);

/**
 * La pagina «L'esperto risponde» (09/10, Elena; prima «Domande e
 * risposte»), per lo studente e per il tutor: l'archivio
 * di un corso alla volta, con un menu per scegliere il corso fra quelli a cui
 * e' iscritto. Con un corso solo il menu non serve e non c'e'.
 *
 * @var list<array<string, mixed>> $corsi
 * @var array<string, mixed>|null $corso
 * @var list<array<string, mixed>> $archivio
 * @var string $cerca
 */
?>
<div class="page-header">
    <h1>L'esperto risponde</h1>
</div>

<?php if ($corso === null): ?>
    <p class="empty-state">Non ci sono ancora corsi da mostrare.</p>
<?php else: ?>
    <?php if (count($corsi) > 1): ?>
        <?php /* Un GET con il suo pulsante: funziona senza JavaScript. */ ?>
        <form method="get" action="/domande-e-risposte" class="form-inline qa-scegli-corso">
            <label for="qa-corso">Corso</label>
            <select id="qa-corso" name="corso">
                <?php foreach ($corsi as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $corso['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $c['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Apri</button>
        </form>
    <?php endif; ?>

    <section class="card qa-archivio" aria-labelledby="qa-archivio-titolo">
        <h2 id="qa-archivio-titolo"><?= htmlspecialchars((string) $corso['title']) ?></h2>
        <?php
        $indirizzo = '/domande-e-risposte';
        $campiNascosti = ['corso' => (string) (int) $corso['id']];
        require __DIR__ . '/_archivio.php';
        ?>
    </section>
<?php endif; ?>
