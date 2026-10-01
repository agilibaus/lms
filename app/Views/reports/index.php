<?php

declare(strict_types=1);

use App\Core\Xlsx;

/** @var array $courses */
/** @var array $students */
/** @var array $groups */
/** @var array $liveSessions */
/** @var array $corsiConVideo */
/** @var bool $restricted */
?>
<div class="page-header">
    <h1>Report</h1>
    <?php if ($restricted): ?>
        <p class="page-subtitle">Stai vedendo solo gli studenti dei gruppi seguiti dal tuo tutor di riferimento.</p>
    <?php endif; ?>
</div>

<?php
/*
 * Le cinque sezioni sono costruite dalla stessa funzione invece che scritte
 * a mano una per una. Non e' amore per l'astrazione: sotto i 500 px ogni
 * cella deve portare l'etichetta della propria colonna, e scrivendo le
 * tabelle a mano l'etichetta e l'intestazione sarebbero due stringhe
 * distinte, destinate prima o poi a non dire piu' la stessa cosa. Cosi'
 * vengono dallo stesso posto e non possono divergere.
 *
 * L'ordine delle sezioni e' deciso: dal contenitore piu' grande al piu'
 * piccolo — corso, gruppo, studente — poi gli eventi e infine il dettaglio
 * sui video, che e' il taglio piu' specifico. Chi apre questa pagina scende
 * finche' non trova il livello che gli serve.
 */

/**
 * La tendina dello scarico, uguale in tutte le sezioni: `details`/`summary`,
 * che si apre e si chiude senza JavaScript e si usa da tastiera. XLSX prima
 * del CSV perche' e' quello che si apre con un doppio clic; il CSV resta per
 * chi deve darlo in pasto a un programma.
 *
 * Il CSV c'e' sempre, l'XLSX solo dove PHP ha l'estensione zip: senza, il
 * file non si puo' nemmeno costruire, e un comando che porta a un errore e'
 * peggio di un comando che non c'e'.
 */
$scarica = static function (string $base): string {
    $b = htmlspecialchars($base);
    $xlsx = Xlsx::disponibile()
        ? '<a href="' . $b . '/xlsx">XLSX</a>'
        : '';

    return '<details class="dropdown dropdown-riga">'
        . '<summary class="btn btn-secondary btn-small">Scarica</summary>'
        . '<div class="dropdown-menu">' . $xlsx . '<a href="' . $b . '/csv">CSV</a></div>'
        . '</details>';
};

/** Le due azioni di ogni riga, sempre nello stesso ordine. */
$azioni = static function (string $base) use ($scarica): string {
    return '<a href="' . htmlspecialchars($base) . '">Dettaglio</a> ' . $scarica($base);
};

$esc = static fn (?string $v): string => htmlspecialchars((string) $v);

/**
 * Una sezione: titolo, eventuale occhiello, colonne e righe.
 *
 * Ogni colonna e' [etichetta, funzione che rende la cella]. L'etichetta
 * finisce sia nell'intestazione sia in `data-label`, che sotto i 500 px il
 * CSS stampa davanti al valore.
 *
 * `role` esplicito su tutto: sotto i 500 px la tabella diventa un elenco di
 * schede con `display: block`, e cambiando il `display` il browser perde le
 * semantiche di tabella. Senza questi attributi, su un telefono un lettore
 * di schermo smetterebbe di annunciare righe e colonne — la resa diventa
 * piu' leggibile e il significato si perde, che non e' un buon affare.
 *
 * @param list<array{0: string, 1: callable(array): string}> $colonne
 * @param array<int, array<string, mixed>>                   $righe
 */
$sezione = static function (
    string $titolo,
    array $colonne,
    array $righe,
    string $vuoto,
    string $occhiello = ''
): void {
    ?>
    <section class="card">
        <h2><?= htmlspecialchars($titolo) ?></h2>
        <?php if ($occhiello !== ''): ?>
            <p class="hint"><?= htmlspecialchars($occhiello) ?></p>
        <?php endif; ?>

        <?php if ($righe === []): ?>
            <p class="empty-state-small"><?= htmlspecialchars($vuoto) ?></p>
        <?php else: ?>
            <table class="data-table" role="table">
                <thead role="rowgroup">
                    <tr role="row">
                        <?php foreach ($colonne as [$etichetta, ]): ?>
                            <th scope="col" role="columnheader"><?= htmlspecialchars($etichetta) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody role="rowgroup">
                    <?php foreach ($righe as $riga): ?>
                        <tr role="row">
                            <?php foreach ($colonne as [$etichetta, $cella]): ?>
                                <td role="cell" data-label="<?= htmlspecialchars($etichetta) ?>"><?= $cella($riga) ?></td>
                            <?php endforeach; ?>
                            <td role="cell" class="row-actions"><?= $riga['_azioni'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
};

/** Attacca a ogni riga l'HTML delle sue azioni, calcolato dal suo indirizzo. */
$con = static function (array $righe, callable $base) use ($azioni): array {
    foreach ($righe as $i => $riga) {
        $righe[$i]['_azioni'] = $azioni($base($riga));
    }

    return $righe;
};

$bozza = static function (array $riga) use ($esc): string {
    return $esc((string) $riga['title'])
        . ((int) $riga['is_published'] === 0 ? ' <span class="badge">bozza</span>' : '');
};
?>

<?php /* Il contenitore tiene qui dentro le regole di impaginazione: le
         colonne delle azioni allineate fra tabelle diverse, e le schede
         sotto i 500 px. Non e' detto che la stessa cosa serva altrove. */ ?>
<div class="reports-index">

<?php $sezione(
    'Per corso',
    [
        ['Corso', $bozza],
        ['Iscritti', static fn (array $r): string => (string) (int) $r['enrolled_count']],
        ['Completati', static fn (array $r): string => (string) (int) $r['completed_count']],
        ['Certificati', static fn (array $r): string => (string) (int) $r['certificate_count']],
    ],
    $con($courses, static fn (array $r): string => '/reports/courses/' . (int) $r['id']),
    'Nessun corso.'
); ?>

<?php $sezione(
    'Per gruppo',
    [
        ['Gruppo', static fn (array $r): string => $esc((string) $r['name'])],
        ['Tutor', static fn (array $r): string => $esc((string) ($r['tutor_name'] ?? '—'))],
        ['Membri', static fn (array $r): string => (string) (int) $r['member_count']],
    ],
    $con($groups, static fn (array $r): string => '/reports/groups/' . (int) $r['id']),
    'Nessun gruppo visibile.'
); ?>

<?php $sezione(
    'Per studente',
    [
        ['Studente', static function (array $r) use ($esc): string {
            return $esc((string) $r['full_name'])
                . ((int) $r['is_active'] === 0 ? ' <span class="badge">disattivato</span>' : '');
        }],
        ['Email', static fn (array $r): string => $esc((string) $r['email'])],
        ['Corsi', static fn (array $r): string => (string) (int) $r['enrolled_count']],
        ['Certificati', static fn (array $r): string => (string) (int) $r['certificate_count']],
    ],
    $con($students, static fn (array $r): string => '/reports/students/' . (int) $r['id']),
    'Nessuno studente visibile.'
); ?>

<?php $sezione(
    'Per incontro dal vivo',
    [
        ['Incontro', static fn (array $r): string => $esc((string) $r['title'])],
        ['Quando', static function (array $r) use ($esc): string {
            $inizio = strtotime((string) $r['starts_at']);

            return $inizio === false ? '—' : $esc(date('d/m/Y H:i', $inizio));
        }],
        ['Corso o gruppo', static fn (array $r): string
            => $esc((string) ($r['course_title'] ?? $r['group_name'] ?? '—'))],
        ['Presenti', static fn (array $r): string
            => (int) $r['attended'] . '/' . (int) $r['expected']],
    ],
    $con($liveSessions, static fn (array $r): string => '/reports/live/' . (int) $r['id']),
    'Nessun incontro.'
); ?>

<?php $sezione(
    'Fruizione dei video',
    [
        ['Corso', $bozza],
        ['Lezioni con video', static fn (array $r): string => (string) (int) $r['lezioni_video']],
        ['Iscritti', static fn (array $r): string => (string) (int) $r['iscritti']],
        ['Hanno aperto un video', static fn (array $r): string => (string) (int) $r['avviati']],
    ],
    $con($corsiConVideo, static fn (array $r): string => '/reports/fruizione/' . (int) $r['id']),
    'Nessun corso ha lezioni con video.',
    'Quanta parte di ogni video hanno guardato gli studenti. È il dato da rendicontare: '
        . 'si scarica per corso, con una colonna per lezione.'
); ?>

</div>

<?php /* La tendina funziona senza JavaScript; lo script aggiunge solo la
         chiusura con Esc e con un clic fuori. */ ?>
<script src="/assets/js/dropdown.js"></script>
