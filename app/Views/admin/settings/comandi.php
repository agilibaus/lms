<?php

declare(strict_types=1);

use App\Core\ComandiPianificati;
use App\Core\Quando;

/**
 * «Comandi pianificati» (17/11, Alessandro): una riga per comando, con la
 * frequenza, l'ultima esecuzione e lo stato. Le soglie stanno in
 * `ComandiPianificati`.
 *
 * @var list<array<string, mixed>> $comandi
 * @var int $adesso
 */

$etichette = [
    ComandiPianificati::REGOLARE => ['regolare', 'badge-success'],
    ComandiPianificati::FERMO => ['fermo', 'badge-danger'],
    ComandiPianificati::MAI => ['mai partito', 'badge-danger'],
];
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Comandi pianificati</h1>
    <p class="page-subtitle">I tre comandi che il server lancia da solo. Se uno smette di girare, Pistacchio non se ne accorge: lo si vede qui.</p>
</div>

<section class="card">
    <?php /* Cinque colonne: sotto i 50 rem di spazio le righe diventano schede. */ ?>
    <div class="tabella-schede">
    <table class="data-table comandi-pianificati" role="table">
        <thead role="rowgroup">
        <tr role="row">
            <th scope="col" role="columnheader">Comando</th>
            <th scope="col" role="columnheader">Che cosa fa</th>
            <th scope="col" role="columnheader">Frequenza</th>
            <th scope="col" role="columnheader">Ultima esecuzione</th>
            <th scope="col" role="columnheader">Stato</th>
        </tr>
        </thead>
        <tbody role="rowgroup">
        <?php foreach ($comandi as $c): ?>
            <?php [$etichetta, $classe] = $etichette[$c['stato']]; ?>
            <tr role="row">
                <td role="cell" data-label="Comando">
                    <?= htmlspecialchars((string) $c['nome']) ?>
                    <span class="cell-sub"><code><?= htmlspecialchars((string) $c['file']) ?></code></span>
                </td>
                <td role="cell" data-label="Che cosa fa"><?= htmlspecialchars((string) $c['cosa']) ?></td>
                <td role="cell" data-label="Frequenza" class="comandi-frequenza"><?= htmlspecialchars((string) $c['frequenza']) ?></td>
                <td role="cell" data-label="Ultima esecuzione">
                    <?php if ($c['ultima'] === null): ?>
                        <span class="valore-assente">mai</span>
                    <?php else: ?>
                        <?php /* In un contenitore solo: nelle schede del telefono
                                 ogni figlio della cella prende una riga sua, e
                                 «4 giorni fa» si staccava dalla data. */ ?>
                        <span class="comandi-ultima">
                            <span class="comandi-quando"><?= htmlspecialchars(Quando::dataOra((string) $c['ultima'])) ?></span>
                            <span class="cell-sub"><?= htmlspecialchars(ComandiPianificati::quantoFa((string) $c['ultima'], $adesso)) ?></span>
                        </span>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Stato"><span class="badge <?= $classe ?>"><?= $etichetta ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
