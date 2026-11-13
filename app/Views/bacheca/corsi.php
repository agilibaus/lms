<?php

declare(strict_types=1);

/**
 * La pagina «Bacheca» di chi gestisce le bacheche di tutti i corsi (06/11,
 * variante 1A del mockup): una riga per corso, con stato, categorie, risorse
 * pubblicate e proposte da valutare, e il collegamento alla sua bacheca. La
 * raggiunge il supervisore dal menu.
 *
 * @var list<array<string, mixed>> $corsi
 * @var array<int, array{categorie: int, risorse: int, proposte: int}> $numeri
 */
?>
<div class="page-header">
    <h1>Bacheche</h1>
    <p class="page-subtitle">Le bacheche di tutti i corsi. Apri un corso per pubblicare risorse, valutare le proposte degli studenti e gestire le categorie.</p>
</div>

<?php if ($corsi === []): ?>
    <p class="empty-state">Nessun corso.</p>
<?php else: ?>
    <section class="card">
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <th scope="col" role="columnheader">Corso</th>
                <th scope="col" role="columnheader">Stato</th>
                <th scope="col" role="columnheader">Categorie</th>
                <th scope="col" role="columnheader">Risorse</th>
                <th scope="col" role="columnheader">Da valutare</th>
                <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($corsi as $c): ?>
                <?php
                $id = (int) $c['id'];
                $n = $numeri[$id] ?? ['categorie' => 0, 'risorse' => 0, 'proposte' => 0];
                $pubblicato = (int) ($c['is_published'] ?? 0) === 1;
                ?>
                <tr role="row">
                    <td role="cell" data-label="Corso"><a href="/bacheca?corso=<?= $id ?>"><?= htmlspecialchars((string) $c['title']) ?></a></td>
                    <td role="cell" data-label="Stato"><span class="badge<?= $pubblicato ? ' badge-success' : '' ?>"><?= $pubblicato ? 'pubblicato' : 'bozza' ?></span></td>
                    <td role="cell" data-label="Categorie"><?= $n['categorie'] ?></td>
                    <td role="cell" data-label="Risorse"><?= $n['risorse'] ?></td>
                    <td role="cell" data-label="Da valutare"><?= $n['proposte'] ?></td>
                    <td role="cell" class="row-actions"><a href="/bacheca?corso=<?= $id ?>">Apri la bacheca</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>
