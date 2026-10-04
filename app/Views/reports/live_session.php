<?php

declare(strict_types=1);

use App\Controllers\ReportController;
use App\Core\Ordinamento;

/** @var array $session */
/** @var array $rows */
/** @var bool $restricted */

$id = (int) $session['id'];
$inizio = new DateTimeImmutable((string) $session['starts_at']);
$fine = new DateTimeImmutable((string) $session['ends_at']);
$presenti = count(array_filter($rows, static fn (array $r): bool => $r['joined_at'] !== null));

/*
 * Presenza e ritardo si vedono come etichette ma si ordinano come numeri,
 * e il valore su cui ordinare si calcola qui: «presente/assente» e'
 * `joined_at` che c'e' o non c'e', e il ritardo e' un numero di minuti che
 * per gli assenti non esiste.
 */
$rows = array_map(static function (array $r): array {
    $presente = $r['joined_at'] !== null;
    $ritardo = ReportController::delayLabel($r);

    return $r + [
        'presenza' => $presente ? 1 : 0,
        'minuti_ritardo' => $presente ? (int) $ritardo : null,
    ];
}, $rows);

$ordine = Ordinamento::daRichiesta([
    'partecipante' => ['full_name', Ordinamento::TESTO],
    'presenza' => ['presenza', Ordinamento::NUMERO],
    'ingresso' => ['joined_at', Ordinamento::DATA],
    'ritardo' => ['minuti_ritardo', Ordinamento::NUMERO],
    'origine' => ['source', Ordinamento::TESTO],
]);
$rows = $ordine->applica($rows);
?>
<div class="page-header">
    <a href="/reports" class="back-link">&larr; Report</a>
    <h1><?= htmlspecialchars((string) $session['title']) ?></h1>
    <p class="page-subtitle">
        <?= htmlspecialchars($inizio->format('d/m/Y H:i')) ?>–<?= htmlspecialchars($fine->format('H:i')) ?>
        <?php if (!empty($session['course_title'])): ?>
            · <?= htmlspecialchars((string) $session['course_title']) ?> / <?= htmlspecialchars((string) $session['module_title']) ?>
        <?php endif; ?>
        <?php if (!empty($session['group_name'])): ?>
            · Gruppo <?= htmlspecialchars((string) $session['group_name']) ?>
        <?php endif; ?>
    </p>
    <?php /* Stessa tendina dell'elenco dei report: XLSX prima perche' si apre
             con un doppio clic, CSV per chi deve darlo in pasto a un programma.
             `details`/`summary` funziona senza JavaScript. */ ?>
    <p>
        <details class="dropdown">
            <summary class="btn btn-secondary">Scarica</summary>
            <div class="dropdown-menu">
                <?php if (App\Core\Xlsx::disponibile()): ?>
                    <a href="/reports/live/<?= $id ?>/xlsx">XLSX</a>
                <?php endif; ?>
                <a href="/reports/live/<?= $id ?>/csv">CSV</a>
            </div>
        </details>
    </p>
</div>

<section class="card">
    <h2>Presenze: <?= $presenti ?> su <?= count($rows) ?> attesi</h2>
    <p class="card-meta">
        L’ingresso viene registrato quando qualcuno apre la riunione da Pistacchio, dalla pagina
        dell’incontro o dalla lezione. Quanto ciascuno sia poi rimasto in riunione non lo sappiamo:
        l’uscita avviene dentro Google Meet, che non ce lo comunica.
        Le presenze si correggono dalla <a href="/live/<?= $id ?>">pagina dell’incontro</a>.
    </p>

    <?php if ($rows === []): ?>
        <p class="empty-state-small">
            <?= $restricted
                ? 'Nessuno dei partecipanti a questo incontro è fra gli studenti dei gruppi che segui.'
                : 'Nessun partecipante atteso: il modulo o il gruppo collegati non hanno iscritti.' ?>
        </p>
    <?php else: ?>
        <?php /* Cinque colonne: sotto i 50 rem di spazio diventa un elenco di
                 schede. */ ?>
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <?= $ordine->th('Partecipante', 'partecipante') ?>
                <?= $ordine->th('Presenza', 'presenza') ?>
                <?= $ordine->th('Ingresso', 'ingresso') ?>
                <?= $ordine->th('Ritardo', 'ritardo') ?>
                <?= $ordine->th('Origine', 'origine') ?>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($rows as $row): ?>
                <?php $presente = $row['joined_at'] !== null; ?>
                <tr role="row">
                    <td role="cell" data-label="Partecipante">
                        <?= htmlspecialchars((string) $row['full_name']) ?>
                        <span class="cell-sub"><?= htmlspecialchars((string) $row['email']) ?></span>
                    </td>
                    <td role="cell" data-label="Presenza">
                        <?php if ($presente): ?>
                            <span class="badge badge-success">presente</span>
                        <?php else: ?>
                            <span class="badge badge-danger">assente</span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" data-label="Ingresso"><?= $presente ? htmlspecialchars(ReportController::dateTimeLabel($row['joined_at'])) : '—' ?></td>
                    <td role="cell" data-label="Ritardo">
                        <?php $ritardo = ReportController::delayLabel($row); ?>
                        <?= $presente && $ritardo !== '' && $ritardo !== '0'
                            ? htmlspecialchars($ritardo) . ' min'
                            : ($presente ? 'in orario' : '—') ?>
                    </td>
                    <td role="cell" data-label="Origine"><?= $presente ? htmlspecialchars(ReportController::sourceLabel($row['source'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<script src="/assets/js/dropdown.js"></script>
