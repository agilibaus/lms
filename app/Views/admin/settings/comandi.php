<?php

declare(strict_types=1);

use App\Core\ComandiPianificati;
use App\Core\Csrf;
use App\Core\Icona;
use App\Core\Quando;
use App\Core\Sorveglianza;
use App\Core\Url;

/**
 * «Comandi pianificati» (17/11, Alessandro): una riga per comando, con la
 * frequenza, l'ultima esecuzione e lo stato. Le soglie stanno in
 * `ComandiPianificati`.
 *
 * @var list<array<string, mixed>> $comandi
 * @var int $adesso
 * @var string $sorveglianza Sorveglianza::SPENTA, ATTIVA o ALTROVE
 * @var string|null $sorveglianzaPer l'indirizzo per cui la chiave e' stata salvata
 * @var bool $chiaveNelFile la chiave viene dal `.env`
 * @var bool $chiaveSalvata c'e' una chiave scritta dal pannello
 * @var string $spazioPiano lo spazio del piano scritto nel pannello, o ''
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
    <p class="page-subtitle">I comandi che il server lancia da solo. Se uno smette di girare, Pistacchio non se ne accorge: lo si vede qui.</p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

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

<?php /* La sorveglianza esterna (23/11, Alessandro, mockup): lo stato
         accanto al titolo, l'indirizzo per cui e' stata salvata la chiave,
         il campo della chiave come quello di Bunny e il segnale di prova. */ ?>
<section class="card">
    <form action="/admin/settings/comandi" method="post" class="form sorveglianza">
        <?= Csrf::field() ?>
        <div class="sorveglianza-testa">
            <h2>Sorveglianza esterna</h2>
            <?php if ($sorveglianza === Sorveglianza::ATTIVA): ?>
                <span class="badge badge-success">Attiva</span>
            <?php else: ?>
                <span class="badge badge-muted">Spenta</span>
            <?php endif; ?>
        </div>

        <?php $nomi = implode(', ', array_map(static fn (string $c): string => '<code>' . $c . '</code>', array_slice(Sorveglianza::CHECK, 0, -1)))
            . ' e <code>' . Sorveglianza::CHECK[count(Sorveglianza::CHECK) - 1] . '</code>'; ?>
        <?php if ($sorveglianza === Sorveglianza::ATTIVA): ?>
            <p class="sorveglianza-stato">
                <?= $chiaveNelFile
                    ? 'Chiave scritta nel file <code>.env</code>.'
                    : 'Chiave salvata per <code>' . htmlspecialchars((string) $sorveglianzaPer) . '</code>.' ?>
            </p>
            <p class="form-hint">A ogni giro i comandi mandano un segnale a Healthchecks.io. I check devono chiamarsi <?= $nomi ?>.</p>
            <p class="form-hint">
                Il sito e lo spazio li controlla <code>bin/sorveglia</code>, che va aggiunto al cron ogni cinque minuti:
                <code>*/5 * * * * cd /percorso/del/sito &amp;&amp; php bin/sorveglia &gt;&gt; storage/logs/sorveglia.log 2&gt;&amp;1</code>
            </p>
        <?php elseif ($sorveglianza === Sorveglianza::ALTROVE): ?>
            <p class="alert alert-warning" role="note">
                Chiave salvata per <code><?= htmlspecialchars((string) $sorveglianzaPer) ?></code>: su questo indirizzo
                (<code><?= htmlspecialchars(Url::base()) ?></code>) la sorveglianza è spenta, perché il database viene da
                un'altra installazione. Per usarla qui, salva una chiave nuova.
            </p>
        <?php else: ?>
            <p class="sorveglianza-stato">Nessuna chiave: i comandi girano, ma non avvisano nessuno se si fermano.</p>
            <p class="form-hint">
                Con la chiave, a ogni giro i comandi mandano un segnale a Healthchecks.io, che ti avvisa per SMS o
                email quando un segnale non arriva o dice che qualcosa non va. I check devono chiamarsi <?= $nomi ?>.
            </p>
        <?php endif; ?>

        <?php if (!$chiaveNelFile): ?>
            <label for="<?= Sorveglianza::KEY ?>">Chiave dei segnali (ping key)</label>
            <input type="password" id="<?= Sorveglianza::KEY ?>" name="<?= Sorveglianza::KEY ?>" autocomplete="off"
                   placeholder="<?= $sorveglianza === Sorveglianza::ALTROVE ? 'salvata per un altro indirizzo'
                       : ($chiaveSalvata ? 'impostata — scrivi qui per sostituirla' : 'nessuna chiave impostata') ?>">
            <p class="form-hint">
                Si copia da Healthchecks.io, nel progetto «Pistacchio LMS»: Settings › Ping key. Non viene mai
                rimandata al browser: se lasci il campo vuoto resta quella di prima.
            </p>

            <?php if ($chiaveSalvata): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="cancella_chiave" value="1">
                    <span>Cancella la chiave salvata (la sorveglianza si spegne)</span>
                </label>
            <?php endif; ?>
        <?php endif; ?>

        <?php /* Lo spazio del piano (23/11): su un hosting condiviso conta la
                 quota dell'account, che Pistacchio non puo' leggere da solo. */ ?>
        <label for="<?= Sorveglianza::KEY_SPAZIO ?>">Spazio del piano di hosting, in GB (facoltativo)</label>
        <input type="number" id="<?= Sorveglianza::KEY_SPAZIO ?>" name="<?= Sorveglianza::KEY_SPAZIO ?>"
               value="<?= htmlspecialchars($spazioPiano) ?>" min="0.1" max="100000" step="0.1" inputmode="decimal"
               aria-describedby="spazio_aiuto">
        <p class="form-hint" id="spazio_aiuto">
            Lo leggi in cPanel. Una volta all'ora <code>bin/sorveglia</code> somma i file caricati (<code>storage/</code>)
            e il database, e oltre l'<?= (int) round(Sorveglianza::SOGLIA_DISCO * 100) ?>% dice a <code><?= Sorveglianza::DISCO ?></code>
            che qualcosa non va. Con il campo vuoto lo spazio non si controlla.
        </p>

        <div class="sorveglianza-comandi">
            <button type="submit" class="btn btn-primary">Salva</button>
            <a href="/admin/settings" class="btn btn-secondary">Annulla</a>
            <?php if ($sorveglianza === Sorveglianza::ATTIVA): ?>
                <button type="submit" formaction="/admin/settings/comandi/prova" class="btn btn-secondary btn-con-icona"><?= Icona::svg('send') ?>Manda un segnale di prova</button>
            <?php endif; ?>
        </div>
    </form>
</section>
