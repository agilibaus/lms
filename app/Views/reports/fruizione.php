<?php

declare(strict_types=1);

use App\Core\ReportSections;
use App\Core\Ritorno;
use App\Controllers\VideoProgressController;
use App\Core\Ordinamento;
use App\Core\Xlsx;
use App\Core\Icona;

/** @var array $course */
/** @var list<array{id: int, title: string, module_title: string, duration_seconds: int|null}> $lezioni */
/** @var list<array<string, mixed>> $righe */
/** @var bool $dettaglioApribile */

/*
 * Qui si ordina per studente, per media e per tempo totale, non per
 * singola lezione: le colonne delle lezioni sono quante sono le lezioni
 * del corso, e i loro titoli sono gia' collegamenti al dettaglio. Farli
 * diventare anche comandi di ordinamento vorrebbe dire due cose diverse
 * sotto lo stesso clic.
 */
$ordine = Ordinamento::daRichiesta([
    'studente' => ['full_name', Ordinamento::TESTO, 'email'],
    'media' => ['percentuale_media', Ordinamento::NUMERO],
    'tempo' => ['secondi_totali', Ordinamento::NUMERO],
]);
$righe = $ordine->applica($righe);
?>
<div class="page-header">
    <?php /* Da Report › Video si torna all'elenco (17/11); altrimenti a «Report». */ ?>
    <?php $ritornoElenco = Ritorno::daRichiesta($_GET, ['elenco-fruizione']); ?>
    <a href="<?= htmlspecialchars($ritornoElenco !== null ? Ritorno::indirizzo($ritornoElenco) : '/reports') ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars($ritornoElenco !== null ? (string) ReportSections::tutte()['fruizione']['titolo'] : 'Report') ?></span></a>
    <h1>Fruizione dei video</h1>
    <p class="page-subtitle"><?= htmlspecialchars((string) $course['title']) ?></p>
    <div class="page-actions">
        <details class="dropdown">
            <summary class="btn btn-secondary btn-con-icona"><?= Icona::svg('download') ?>Scarica</summary>
            <div class="dropdown-menu">
                <?php if (Xlsx::disponibile()): ?>
                    <a href="/reports/fruizione/<?= (int) $course['id'] ?>/xlsx">XLSX</a>
                <?php endif; ?>
                <a href="/reports/fruizione/<?= (int) $course['id'] ?>/csv">CSV</a>
            </div>
        </details>
    </div>
</div>

<?php if ($lezioni === []): ?>
    <p class="empty-state">Questo corso non ha lezioni con video.</p>
<?php elseif ($righe === []): ?>
    <p class="empty-state">Nessuno studente è iscritto a questo corso.</p>
<?php else: ?>
    <?php /* Con molte lezioni la tabella diventa più larga dello schermo:
             scorre in orizzontale invece di stringere le colonne fino a
             renderle illeggibili. */ ?>
    <section class="card">
        <h2>Studenti (<?= count($righe) ?>)</h2>
    <?php /* Colonne con una larghezza minima (30/10, Alessandro): strette, i
             titoli delle lezioni andavano su quattro righe e una parola lunga
             usciva dalla sua colonna, sopra i valori di quella accanto. La
             tabella si allarga e scorre di lato dentro `tabella-larga`. */ ?>
    <div class="tabella-larga">
        <table class="data-table tabella-fruizione">
            <caption class="sr-only">
                Percentuale di ciascun video guardata da ogni studente iscritto a
                <?= htmlspecialchars((string) $course['title']) ?>.
            </caption>
            <thead>
                <tr>
                    <?= $ordine->th('Studente', 'studente') ?>
                    <?php foreach ($lezioni as $lezione): ?>
                        <th scope="col" class="col-numero">
                            <?php if ($dettaglioApribile): ?>
                                <a href="<?= htmlspecialchars(Ritorno::link('/lessons/' . (int) $lezione['id'] . '/fruizione', 'report-fruizione', (int) $course['id'], $_GET)) ?>">
                                    <?= htmlspecialchars($lezione['title']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($lezione['title']) ?>
                            <?php endif; ?>
                            <span class="riga-secondaria"><?= htmlspecialchars($lezione['module_title']) ?></span>
                        </th>
                    <?php endforeach; ?>
                    <?= $ordine->th('Media', 'media', 'col-numero') ?>
                    <?= $ordine->th('Tempo totale (secondi)', 'tempo', 'col-numero') ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($righe as $riga): ?>
                    <tr>
                        <th scope="row" class="cella-studente">
                            <?= htmlspecialchars((string) $riga['full_name']) ?>
                            <span class="riga-secondaria"><?= htmlspecialchars((string) $riga['email']) ?></span>
                        </th>
                        <?php foreach ($lezioni as $lezione): ?>
                            <?php $cella = $riga['per_lezione'][$lezione['id']] ?? null; ?>
                            <td class="col-numero">
                                <?php if ($cella === null || $cella['percentuale'] === null): ?>
                                    <span class="valore-assente" title="Durata del video sconosciuta">—</span>
                                <?php else: ?>
                                    <?= (int) $cella['percentuale'] ?>%
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="col-numero">
                            <?= $riga['percentuale_media'] === null
                                ? '<span class="valore-assente">—</span>'
                                : (int) $riga['percentuale_media'] . '%' ?>
                        </td>
                        <td class="col-numero"><?= VideoProgressController::secondi((int) $riga['secondi_totali']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="hint">
        <?php if ($dettaglioApribile): ?>
            Il titolo di ogni lezione porta al suo dettaglio, con i tempi e le date di
            ciascuno studente.
        <?php endif; ?>
        Un trattino vuol dire che la durata di quel video non è nota, quindi non c'è un
        denominatore per la percentuale.
    </p>
    </section>
<?php endif; ?>

<details class="nota-metodo">
    <summary>Come sono calcolati questi numeri</summary>
    <div>
        <p>
            La percentuale è la parte di video effettivamente vista: riguardare due volte lo
            stesso minuto conta una volta sola, e saltare avanti con la barra non conta. La
            media è su tutte le lezioni con video del corso, comprese quelle mai aperte, che
            valgono zero.
        </p>
        <p>
            I tempi li misura il browser dello studente e li manda al server alla pausa, alla
            fine, alla chiusura della scheda e ogni minuto. Il server scarta quello che non
            può essere successo — più video di quanto ne sia passato di orologio, o oltre la
            durata del file — e registra l'ora di arrivo di ogni scrittura.
        </p>
        <p>
            <strong>Resta un dato dichiarato dal browser.</strong> Chi sa usare gli strumenti
            per sviluppatori può alterarlo, e nessun controllo sul nostro server può impedirlo
            del tutto. È una misura di partecipazione attendibile nell'uso normale, non una
            presenza certificata.
        </p>
    </div>
</details>
