<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\StudentiFermi;
use App\Controllers\ReportController;
use App\Core\Ordinamento;
use App\Core\Icona;

/** @var array $student */
/** @var array $courses */
/** @var array<int, array> $quizzesByCourse */
/** @var array{attended: int, total: int} $liveAttendance */
/** @var array $liveSessions */

/*
 * Due ordinamenti su una pagina sola, con chiavi diverse: l'indirizzo ne
 * porta una alla volta, e quello che non la riconosce lascia le righe come
 * stanno. Cosi' ordinando gli incontri le tabelle dei quiz non si
 * scompongono, e viceversa.
 *
 * Le tabelle dei quiz sono tante, una per corso, e si ordinano **tutte
 * insieme**: hanno le stesse colonne, e un ordine per ciascuna vorrebbe
 * dire un parametro per corso nell'indirizzo.
 */
$ordineQuiz = Ordinamento::daRichiesta([
    'quiz' => ['quiz_title', Ordinamento::TESTO],
    'modulo' => ['module_title', Ordinamento::TESTO],
    'tentativi' => ['attempts', Ordinamento::NUMERO],
    'punteggio' => ['best_score_pct', Ordinamento::NUMERO],
    // L'esito si ordina sull'etichetta mostrata, in ordine alfabetico (06/11,
    // Alessandro): sul dato `passed` i «non svolto», che non ce l'hanno,
    // finivano in fondo in tutti e due i versi.
    'esito' => ['esito_etichetta', Ordinamento::TESTO],
    'ultimo' => ['last_attempt_at', Ordinamento::DATA],
]);

$ordineIncontri = Ordinamento::daRichiesta([
    'incontro' => ['title', Ordinamento::TESTO],
    'quando' => ['starts_at', Ordinamento::DATA],
    'presenza' => ['presenza', Ordinamento::NUMERO],
    'ingresso' => ['joined_at', Ordinamento::DATA],
]);

$liveSessions = $ordineIncontri->applica(array_map(
    static fn (array $r): array => $r + ['presenza' => $r['joined_at'] !== null ? 1 : 0],
    $liveSessions
));

// Corsi e incontri in due schede (06/11, Alessandro, variante C): prima
// erano riquadri bianchi uguali uno sotto l'altro, e non si distinguevano.
// Le schede ci sono solo se ci sono tutte e due le cose; la scheda e' un
// parametro dell'indirizzo, cosi' funziona senza JavaScript e l'ordinamento
// delle colonne la conserva.
$conSchede = $courses !== [] && $liveSessions !== [];
$scheda = ($_GET['scheda'] ?? '') === 'incontri' && $liveSessions !== [] ? 'incontri' : 'corsi';
if ($courses === [] && $liveSessions !== []) {
    $scheda = 'incontri';
}

/** Le date come in tutta la piattaforma: «16/09/2026 18:00» (06/11). */
$quando = static fn (?string $d): string => ReportController::dateTimeLabel($d);
?>
<div class="page-header">
    <a href="/reports" class="back-link"><span class="back-link-testo">Report</span></a>
    <h1><?= htmlspecialchars((string) $student['full_name']) ?></h1>
    <?php /* Solo il ruolo sotto il nome (21/10, Alessandro): l'email sta
             nell'elenco degli studenti e nei file scaricati, e per scrivere
             c'e' «Scrivi» in «Studenti fermi». */ ?>
    <p class="page-subtitle"><?= htmlspecialchars(Auth::roleLabel((string) $student['role'])) ?></p>
    <?php /* L'ultimo accesso alla piattaforma (20/10, «Studenti fermi»):
             vuoto finche' lo studente non torna dopo la migrazione. */ ?>
    <p class="page-subtitle">
        Ultimo accesso:
        <?php if (empty($student['last_seen_at'])): ?>
            non ancora registrato
        <?php else: ?>
            <?php $giorniAccesso = StudentiFermi::giorni((string) $student['last_seen_at'], new DateTimeImmutable('now')); ?>
            <?= htmlspecialchars(StudentiFermi::quando($giorniAccesso)) ?>
            (<?= htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $student['last_seen_at']))) ?>)
        <?php endif; ?>
    </p>
    <?php if ($liveAttendance['total'] > 0): ?>
        <p class="page-subtitle">
            Sessioni live seguite: <?= (int) $liveAttendance['attended'] ?>/<?= (int) $liveAttendance['total'] ?>
        </p>
    <?php endif; ?>
    <?php /* Stessa tendina dell'elenco dei report: XLSX prima perche' si apre
             con un doppio clic, CSV per chi deve darlo in pasto a un programma.
             `details`/`summary` funziona senza JavaScript. */ ?>
    <p>
        <details class="dropdown">
            <summary class="btn btn-secondary btn-con-icona"><?= Icona::svg('download') ?>Scarica</summary>
            <div class="dropdown-menu">
                <?php if (App\Core\Xlsx::disponibile()): ?>
                    <a href="/reports/students/<?= (int) $student['id'] ?>/xlsx">XLSX</a>
                <?php endif; ?>
                <a href="/reports/students/<?= (int) $student['id'] ?>/csv">CSV</a>
            </div>
        </details>
    </p>
</div>

<?php if ($conSchede): ?>
    <nav class="schede-pagina" aria-label="Sezioni della pagina">
        <a href="?scheda=corsi" class="<?= $scheda === 'corsi' ? 'attiva' : '' ?>"
           <?= $scheda === 'corsi' ? 'aria-current="page"' : '' ?>>Corsi (<?= count($courses) ?>)</a>
        <a href="?scheda=incontri" class="<?= $scheda === 'incontri' ? 'attiva' : '' ?>"
           <?= $scheda === 'incontri' ? 'aria-current="page"' : '' ?>>Incontri dal vivo (<?= count($liveSessions) ?>)</a>
    </nav>
<?php endif; ?>

<?php if ($scheda !== 'corsi'): ?>
    <?php /* La scheda «Incontri dal vivo»: i corsi non si stampano. */ ?>
<?php elseif ($courses === []): ?>
    <p class="empty-state">Questo utente non è iscritto ad alcun corso.</p>
<?php else: ?>
    <?php foreach ($courses as $course): ?>
        <section class="card report-studente-corso">
            <div class="card-head">
                <h2><a href="/reports/courses/<?= (int) $course['course_id'] ?>"><?= htmlspecialchars((string) $course['course_title']) ?></a></h2>
                <span>
                    <?= number_format((float) $course['progress_pct'], 0) ?>%
                    <?php if ($course['completed_at'] !== null): ?>
                        <span class="badge badge-success">completato</span>
                    <?php endif; ?>
                </span>
            </div>

            <?php /* La barra dell'avanzamento (variante C), come in «I miei corsi». */ ?>
            <div class="report-studente-barra" aria-hidden="true">
                <span style="width: <?= max(0, min(100, (int) round((float) $course['progress_pct']))) ?>%"></span>
            </div>
            <p class="card-meta">
                Lezioni <?= (int) $course['lessons_completed'] ?>/<?= (int) $course['lessons_total'] ?> ·
                Questionari superati <?= (int) $course['quizzes_passed'] ?>/<?= (int) $course['quizzes_total'] ?> ·
                Iscritto il <?= htmlspecialchars($quando((string) $course['enrolled_at'])) ?>
                <?php if (!empty($course['certificate_code'])): ?>
                    · Certificato
                    <?php if ($course['certificate_revoked_at'] !== null): ?>
                        <span class="badge badge-danger">revocato</span>
                    <?php else: ?>
                        <code><?= htmlspecialchars((string) $course['certificate_code']) ?></code>
                    <?php endif; ?>
                <?php endif; ?>
            </p>

            <?php
            $quizzes = $ordineQuiz->applica(array_map(
                static fn (array $q): array => $q + ['esito_etichetta' => (int) $q['attempts'] === 0
                    ? 'non svolto'
                    : ((int) $q['passed'] === 1 ? 'superato' : 'non superato')],
                $quizzesByCourse[$course['course_id']] ?? []
            ));
            ?>

            <?php if ($quizzes !== []): ?>
                <?php /* Sei colonne: sotto i 50 rem di spazio diventa un
                         elenco di schede (vedi `.tabella-schede`). */ ?>
                <div class="tabella-schede">
                <table class="data-table" role="table">
                    <thead role="rowgroup">
                    <tr role="row">
                        <?= $ordineQuiz->th('Questionario', 'quiz') ?>
                        <?= $ordineQuiz->th('Modulo', 'modulo') ?>
                        <?= $ordineQuiz->th('Tentativi', 'tentativi') ?>
                        <?= $ordineQuiz->th('Miglior punteggio', 'punteggio') ?>
                        <?= $ordineQuiz->th('Esito', 'esito') ?>
                        <?= $ordineQuiz->th('Ultimo tentativo', 'ultimo') ?>
                    </tr>
                    </thead>
                    <tbody role="rowgroup">
                    <?php foreach ($quizzes as $quiz): ?>
                        <tr role="row">
                            <td role="cell" data-label="Questionario"><?= htmlspecialchars((string) $quiz['quiz_title']) ?></td>
                            <td role="cell" data-label="Modulo"><?= htmlspecialchars((string) $quiz['module_title']) ?></td>
                            <td role="cell" data-label="Tentativi"><?= (int) $quiz['attempts'] ?></td>
                            <td role="cell" data-label="Miglior punteggio"><?= $quiz['best_score_pct'] === null ? '—' : number_format((float) $quiz['best_score_pct'], 0) . '%' ?></td>
                            <td role="cell" data-label="Esito">
                                <?php $esito = (string) $quiz['esito_etichetta']; ?>
                                <span class="badge<?= $esito === 'superato' ? ' badge-success' : ($esito === 'non superato' ? ' badge-danger' : '') ?>"><?= htmlspecialchars($esito) ?></span>
                            </td>
                            <td role="cell" data-label="Ultimo tentativo">
                                <?php /* Il collegamento all'ultimo tentativo e'
                                         l'unico modo per arrivare alle risposte
                                         aperte, che nessuno puo' correggere a
                                         macchina e qualcuno deve leggere. */ ?>
                                <?php /* Il collegamento solo a chi puo' aprire il
                                         tentativo (26/10): l'assistente con
                                         «Tentativi dei questionari» spento vede
                                         la data, non il collegamento. */ ?>
                                <?php if (!empty($quiz['last_attempt_id']) && \App\Auth\StudentScope::puoAprireTentativo((int) $student['id'], (int) $course['course_id'])): ?>
                                    <a href="/attempts/<?= (int) $quiz['last_attempt_id'] ?>">
                                        <?= htmlspecialchars($quiz['last_attempt_at'] !== null ? $quando((string) $quiz['last_attempt_at']) : '—') ?>
                                    </a>
                                <?php elseif (!empty($quiz['last_attempt_id'])): ?>
                                    <?= htmlspecialchars($quiz['last_attempt_at'] !== null ? $quando((string) $quiz['last_attempt_at']) : '—') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($liveSessions !== [] && $scheda === 'incontri'): ?>
    <section class="card report-studente-incontri">
        <?php /* Il titolo lo dice la scheda; senza schede resta, per un lettore
                 di schermo, come titolo di sezione nascosto. */ ?>
        <h2 class="sr-only">Incontri dal vivo</h2>
        <p class="card-meta">
            Gli incontri a cui questo studente era atteso. L’ingresso è quello registrato quando
            ha aperto la riunione da Pistacchio; quanto sia rimasto in riunione non lo sappiamo,
            perché l’uscita avviene dentro Google Meet.
        </p>

        <?php /* Sei colonne anche qui: stesso trattamento della tabella dei
                 quiz qui sopra. */ ?>
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <?= $ordineIncontri->th('Incontro', 'incontro') ?>
                <?= $ordineIncontri->th('Quando', 'quando') ?>
                <th scope="col" role="columnheader">Corso o gruppo</th>
                <?= $ordineIncontri->th('Presenza', 'presenza') ?>
                <?= $ordineIncontri->th('Ingresso', 'ingresso') ?>
                <th scope="col" role="columnheader">Ritardo</th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($liveSessions as $incontro): ?>
                <?php
                $presente = $incontro['joined_at'] !== null;
                $ritardo = ReportController::delayLabel($incontro);
                ?>
                <tr role="row">
                    <td role="cell" data-label="Incontro"><a href="/reports/live/<?= (int) $incontro['id'] ?>"><?= htmlspecialchars((string) $incontro['title']) ?></a></td>
                    <td role="cell" data-label="Quando"><?= htmlspecialchars(ReportController::dateTimeLabel((string) $incontro['starts_at'])) ?></td>
                    <td role="cell" data-label="Corso o gruppo"><?= htmlspecialchars((string) ($incontro['course_title'] ?? $incontro['group_name'] ?? '—')) ?></td>
                    <td role="cell" data-label="Presenza">
                        <?php if ($presente): ?>
                            <span class="badge badge-success">presente</span>
                        <?php else: ?>
                            <span class="badge badge-danger">assente</span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" data-label="Ingresso"><?= $presente ? htmlspecialchars(ReportController::dateTimeLabel($incontro['joined_at'])) : '—' ?></td>
                    <td role="cell" data-label="Ritardo">
                        <?= $presente && $ritardo !== '' && $ritardo !== '0'
                            ? htmlspecialchars($ritardo) . ' min'
                            : ($presente ? 'in orario' : '—') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<script src="/assets/js/dropdown.js"></script>
