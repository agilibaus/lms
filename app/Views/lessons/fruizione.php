<?php

declare(strict_types=1);

use App\Controllers\VideoProgressController;
use App\Core\Ordinamento;
use App\Core\Xlsx;

/** @var array $lesson */
/** @var array $module */
/** @var array $course */
/** @var list<array<string, mixed>> $righe */
/** @var array{iscritti: int, avviati: int, media: int|null, completati: int} $riepilogo */

$durata = (int) ($lesson['duration_seconds'] ?? 0);

// «Vista» mostra una percentuale che puo' mancare (durata del video
// sconosciuta): quelle righe finiscono in fondo, come ogni vuoto.
$ordine = Ordinamento::daRichiesta([
    'studente' => ['full_name', Ordinamento::TESTO],
    'vista' => ['percentage', Ordinamento::NUMERO],
    'tempo' => ['watched_seconds', Ordinamento::NUMERO],
    'posizione' => ['position_seconds', Ordinamento::NUMERO],
    'visita' => ['updated_at', Ordinamento::DATA],
    'completata' => ['completed_at', Ordinamento::DATA],
]);
$righe = $ordine->applica($righe);

/** «12:04», come sulla barra del player. */
$minutoSecondo = static function (?int $secondi): string {
    if ($secondi === null) {
        return '—';
    }

    return sprintf('%d:%02d', intdiv($secondi, 60), $secondi % 60);
};

$quando = static function (?string $data): string {
    if ($data === null) {
        return '—';
    }

    $ora = strtotime($data);

    return $ora === false ? '—' : date('d/m/Y H:i', $ora);
};
?>
<div class="page-header">
    <a href="/lessons/<?= (int) $lesson['id'] ?>/edit" class="back-link">
        &larr; <?= htmlspecialchars($course['title']) ?> &mdash; <?= htmlspecialchars($lesson['title']) ?>
    </a>
    <h1>Fruizione del video</h1>
    <p class="page-subtitle">
        Quanta parte del video ha guardato ciascuno studente iscritto al corso.
        <?php if ($durata > 0): ?>
            Il video dura <?= htmlspecialchars($minutoSecondo($durata)) ?>.
        <?php endif; ?>
    </p>
    <div class="page-actions">
        <?php /* Stessa tendina dell'elenco utenti: `details`/`summary`, che si
                 apre e si chiude senza JavaScript. */ ?>
        <details class="dropdown">
            <summary class="btn btn-secondary">Scarica</summary>
            <div class="dropdown-menu">
                <?php if (Xlsx::disponibile()): ?>
                    <a href="/lessons/<?= (int) $lesson['id'] ?>/fruizione/xlsx">XLSX</a>
                <?php endif; ?>
                <a href="/lessons/<?= (int) $lesson['id'] ?>/fruizione/csv">CSV</a>
            </div>
        </details>
    </div>
</div>

<?php if ($durata <= 0): ?>
    <?php /* Il testo sta in un paragrafo perche' e' un paragrafo: e cosi' il
             collegamento si legge come parte della frase, che e' cio' che le
             WCAG esentano dalla misura minima dei bersagli. */ ?>
    <div class="alert alert-info">
        <p>
            La durata del video non è indicata sulla lezione, e nessuno studente l'ha ancora
            aperto: finché manca un denominatore non si può calcolare una percentuale. Si
            riempie da sé appena il primo studente guarda il video, oppure scrivendo la durata
            in <a href="/lessons/<?= (int) $lesson['id'] ?>/edit">Modifica lezione</a>.
        </p>
    </div>
<?php endif; ?>

<?php /* Le medie sono sugli iscritti, non su chi ha guardato: la media di chi
         ha guardato direbbe sempre bene, ed è esattamente il numero che non
         serve a un rendiconto. */ ?>
<dl class="riepilogo-fruizione">
    <div>
        <dt>Iscritti al corso</dt>
        <dd><?= (int) $riepilogo['iscritti'] ?></dd>
    </div>
    <div>
        <dt>Hanno aperto il video</dt>
        <dd><?= (int) $riepilogo['avviati'] ?></dd>
    </div>
    <div>
        <dt>Percentuale media vista</dt>
        <dd><?= $riepilogo['media'] === null ? '—' : (int) $riepilogo['media'] . '%' ?></dd>
    </div>
    <div>
        <dt>Lezione completata</dt>
        <dd><?= (int) $riepilogo['completati'] ?></dd>
    </div>
</dl>

<?php if ($righe === []): ?>
    <p class="empty-state">Nessuno studente è iscritto a questo corso.</p>
<?php else: ?>
    <div class="tabella-schede">
    <table class="data-table" role="table">
        <caption class="sr-only">
            Fruizione del video della lezione <?= htmlspecialchars($lesson['title']) ?>,
            uno studente per riga.
        </caption>
        <thead role="rowgroup">
            <tr role="row">
                <?= $ordine->th('Studente', 'studente') ?>
                <?= $ordine->th('Vista', 'vista') ?>
                <?= $ordine->th('Tempo guardato (secondi)', 'tempo') ?>
                <?= $ordine->th('Ultima posizione', 'posizione') ?>
                <?= $ordine->th('Ultima visita', 'visita') ?>
                <?= $ordine->th('Completata', 'completata') ?>
            </tr>
        </thead>
        <tbody role="rowgroup">
            <?php foreach ($righe as $riga): ?>
                <tr role="row">
                    <td role="cell" data-label="Studente">
                        <?= htmlspecialchars((string) $riga['full_name']) ?>
                        <span class="riga-secondaria"><?= htmlspecialchars((string) $riga['email']) ?></span>
                    </td>
                    <td role="cell" data-label="Vista">
                        <?php if ($riga['percentage'] === null): ?>
                            <span class="valore-assente" title="Durata del video sconosciuta">—</span>
                        <?php else: ?>
                            <?php /* La barra è un di più: il numero accanto dice
                                     la stessa cosa, perché il colore da solo non
                                     basta a chi non lo distingue. */ ?>
                            <span class="barra-vista" aria-hidden="true">
                                <span class="barra-vista-piena" style="width: <?= (int) $riga['percentage'] ?>%"></span>
                            </span>
                            <?= (int) $riga['percentage'] ?>%
                        <?php endif; ?>
                    </td>
                    <td role="cell" data-label="Tempo guardato (secondi)"><?= VideoProgressController::secondi((int) $riga['watched_seconds']) ?></td>
                    <td role="cell" data-label="Ultima posizione"><?= htmlspecialchars($minutoSecondo(
                        $riga['position_seconds'] === null ? null : (int) $riga['position_seconds']
                    )) ?></td>
                    <td role="cell" data-label="Ultima visita"><?= htmlspecialchars($quando(
                        $riga['updated_at'] === null ? null : (string) $riga['updated_at']
                    )) ?></td>
                    <td role="cell" data-label="Completata"><?= htmlspecialchars($quando(
                        $riga['completed_at'] === null ? null : (string) $riga['completed_at']
                    )) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php /* Detto qui e non solo nel promemoria: chi legge questa pagina deve
         sapere com'è fatto il numero che sta leggendo, soprattutto se poi lo
         mette in un rendiconto. */ ?>
<details class="nota-metodo">
    <summary>Come sono calcolati questi numeri</summary>
    <div>
        <p>
            Il tempo guardato è la somma delle parti di video effettivamente viste, non
            quanto è rimasta aperta la pagina: riguardare due volte lo stesso minuto conta
            una volta sola, e saltare avanti con la barra non conta.
        </p>
        <p>
            I tempi li misura il browser dello studente e li manda al server alla pausa,
            alla fine, alla chiusura della scheda e ogni minuto. Il server scarta quello
            che non può essere successo — più video di quanto ne sia passato di orologio,
            o oltre la durata del file — e registra l'ora di arrivo di ogni scrittura.
        </p>
        <p>
            <strong>Resta un dato dichiarato dal browser.</strong> Chi sa usare gli
            strumenti per sviluppatori può alterarlo, e nessun controllo sul nostro server
            può impedirlo del tutto. È una misura di partecipazione attendibile nell'uso
            normale, non una presenza certificata.
        </p>
    </div>
</details>
