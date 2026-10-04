<?php

declare(strict_types=1);

use App\Core\Ordinamento;
use App\Core\ReportSections;
use App\Core\Xlsx;

/**
 * L'elenco completo di un taglio: ricerca, tabella, paginazione.
 *
 * Una vista sola per tutti e cinque i tagli. Cambiano le colonne — che
 * stanno in `_colonne.php` — non quello che la pagina fa.
 *
 * @var string $chiave
 * @var array<string, mixed> $sezione
 * @var array<int, array<string, mixed>> $righe
 * @var int $pagina
 * @var int $pagine
 * @var int $totale
 * @var string $cerca
 * @var Ordinamento $ordine
 * @var bool $restricted
 */

$esc = static fn (?string $v): string => htmlspecialchars((string) $v);

/** @var array<string, list<array{0: string, 1: string, 2: callable(array): string}>> $tutteLeColonne */
$tutteLeColonne = require __DIR__ . '/_colonne.php';
$colonne = $tutteLeColonne[$chiave];

/**
 * I comandi di una riga: il dettaglio e i due formati, in chiaro.
 *
 * PRIMA ERA UNA TENDINA, ed era sbagliato per due motivi che si sommavano.
 * Il primo: la tabella stava dentro a un contenitore con `overflow-x`, e
 * un contenitore che scorre **ritaglia** quello che esce. Aprendo la
 * tendina comparivano dei bordi e una barra di scorrimento verticale
 * attorno alla tabella — l'aria di un riquadro incastrato dentro la
 * pagina — e sull'ultima riga il menu restava tagliato: 74 px fuori,
 * misurati. Il secondo: una tendina per riga e' un comando in piu' da
 * aprire per arrivare a due collegamenti, cinquanta volte in una pagina.
 *
 * Due collegamenti scritti non si possono ritagliare, non hanno uno stato
 * aperto/chiuso, si raggiungono con un clic invece di due e funzionano
 * uguale da tastiera. La tendina resta dove serve davvero: in cima a una
 * pagina, dove i comandi sono tanti e la riga e' una sola.
 *
 * L'XLSX compare solo dove PHP ha l'estensione zip: senza, il file non si
 * puo' nemmeno costruire, e un comando che porta a un errore e' peggio di
 * un comando che non c'e'.
 */
$scarica = static function (string $base): string {
    $b = htmlspecialchars($base);
    $xlsx = Xlsx::disponibile() ? '<a href="' . $b . '/xlsx">XLSX</a>' : '';

    return $xlsx . '<a href="' . $b . '/csv">CSV</a>';
};

/** L'indirizzo di questa pagina con un parametro cambiato. */
$conParametro = static function (string $nome, string $valore) use ($chiave, $cerca, $pagina, $ordine): string {
    // L'ordine scelto viaggia con le pagine: «pagina 2» di un elenco
    // ordinato per punteggio deve restare ordinata per punteggio.
    $q = [
        'cerca' => $cerca,
        'ordina' => (string) $ordine->chiave(),
        'verso' => $ordine->chiave() === null ? '' : $ordine->verso(),
        'pagina' => (string) $pagina,
    ];
    $q[$nome] = $valore;
    $q = array_filter($q, static fn (string $v): bool => $v !== '' && $v !== '1');

    return '/reports/elenco/' . $chiave . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= $esc((string) $sezione['titolo']) ?></h1>
    <p class="page-subtitle"><?= $esc((string) $sezione['occhiello']) ?></p>
    <?php if ($restricted): ?>
        <p class="page-subtitle">
            Stai vedendo solo gli studenti dei gruppi seguiti dal tuo tutor di riferimento.
        </p>
    <?php endif; ?>
</div>

<?php /* La ricerca è un modulo GET: funziona senza JavaScript, e l'indirizzo
         che ne esce si può salvare nei preferiti o passare a un collega. */ ?>
<form class="report-ricerca" method="get" action="/reports/elenco/<?= $esc($chiave) ?>">
    <?php /* «Cerca fra **gli** studenti», non «fra i studenti»: l'articolo
             dipende da come comincia la parola e sta nella sezione. */ ?>
    <label for="cerca">
        Cerca fra <?= $esc((string) $sezione['articolo']) ?> <?= $esc((string) $sezione['plurale']) ?>
    </label>
    <?php /* L'ordine scelto sopravvive alla ricerca: chi ha ordinato per
             punteggio e poi cerca un nome si aspetta di ritrovare lo stesso
             ordine, non quello di partenza. */ ?>
    <?php if ($ordine->chiave() !== null): ?>
        <input type="hidden" name="ordina" value="<?= $esc((string) $ordine->chiave()) ?>">
        <input type="hidden" name="verso" value="<?= $esc($ordine->verso()) ?>">
    <?php endif; ?>
    <div class="report-ricerca-riga">
        <?php /* Il suggerimento nomina le colonne in cui si cerca davvero:
                 l'email si cerca fra gli studenti, non fra i corsi. */ ?>
        <input type="search" id="cerca" name="cerca" value="<?= $esc($cerca) ?>"
               placeholder="<?= $esc(ReportSections::suggerimento($sezione['cerca'])) ?>"
               spellcheck="false">
        <button type="submit" class="btn btn-secondary">Cerca</button>
        <?php if ($cerca !== ''): ?>
            <a class="btn btn-secondary" href="/reports/elenco/<?= $esc($chiave) ?>">Azzera</a>
        <?php endif; ?>
    </div>
</form>

<p class="report-conteggio">
    <?php if ($cerca === ''): ?>
        <strong><?= (int) $totale ?></strong>
        <?= $esc($totale === 1 ? (string) $sezione['singolare'] : (string) $sezione['plurale']) ?>
    <?php else: ?>
        <strong><?= (int) $totale ?></strong>
        <?= $esc($totale === 1 ? (string) $sezione['singolare'] : (string) $sezione['plurale']) ?>
        per «<?= $esc($cerca) ?>»
    <?php endif; ?>
    <?php if ($pagine > 1): ?>
        — pagina <?= (int) $pagina ?> di <?= (int) $pagine ?>
    <?php endif; ?>
</p>

<?php if ($righe === []): ?>
    <p class="empty-state">
        <?= $cerca === '' ? $esc((string) $sezione['vuoto']) : 'Nessun risultato per «' . $esc($cerca) . '».' ?>
    </p>
<?php else: ?>
    <?php /* `tabella-schede` trasforma la tabella in un elenco di schede
             quando lo spazio non basta; `reports-elenco` tiene le colonne
             numeriche della stessa larghezza in tutte e cinque le pagine. */ ?>
    <?php /* Niente contenitore che scorre qui dentro: sotto i 50 rem le
             righe diventano schede, quindi la tabella non ha mai bisogno
             di scorrere in orizzontale — e un contenitore che scorre
             ritaglia tutto quello che esce dai suoi bordi. */ ?>
    <div class="reports-elenco tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
                <tr role="row">
                    <?php foreach ($colonne as $c): ?>
                        <?= $ordine->th(
                            $c['etichetta'],
                            $c['chiave'],
                            $c['tipo'] === 'numero' ? 'col-numero' : ''
                        ) ?>
                    <?php endforeach; ?>
                    <th scope="col" role="columnheader" class="col-azioni">
                        <span class="sr-only">Azioni</span>
                    </th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                <?php foreach ($righe as $riga): ?>
                    <?php $base = $sezione['base'] . '/' . (int) $riga['id']; ?>
                    <tr role="row">
                        <?php foreach ($colonne as $c): ?>
                            <td role="cell" data-label="<?= $esc($c['etichetta']) ?>"
                                class="<?= $c['tipo'] === 'numero' ? 'col-numero' : '' ?>">
                                <?= ($c['cella'])($riga) ?>
                            </td>
                        <?php endforeach; ?>
                        <td role="cell" data-label="Azioni" class="col-azioni row-actions">
                            <a href="<?= $esc($base) ?>">Dettaglio</a>
                            <?= $scarica($base) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pagine > 1): ?>
        <nav class="paginazione" aria-label="Pagine dei risultati">
            <?php if ($pagina > 1): ?>
                <a class="btn btn-secondary" href="<?= $esc($conParametro('pagina', (string) ($pagina - 1))) ?>"
                   rel="prev">&larr; Precedenti</a>
            <?php endif; ?>

            <span class="paginazione-stato">
                <?= (int) ((($pagina - 1) * ReportSections::PER_PAGINA) + 1) ?>–<?=
                    (int) min($pagina * ReportSections::PER_PAGINA, $totale) ?>
                di <?= (int) $totale ?>
            </span>

            <?php if ($pagina < $pagine): ?>
                <a class="btn btn-secondary" href="<?= $esc($conParametro('pagina', (string) ($pagina + 1))) ?>"
                   rel="next">Successivi &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
