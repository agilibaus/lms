<?php

declare(strict_types=1);

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

<?php /* L'ordine delle sezioni è deciso: dal contenitore più grande al più
         piccolo — corso, gruppo, studente — poi gli eventi e infine il
         dettaglio sui video, che è il taglio più specifico. Non è l'ordine
         in cui le sezioni sono state scritte, ed è voluto: chi apre questa
         pagina scende finché non trova il livello che gli serve. I corsi
         senza video non compaiono nell'ultima: non avrebbero niente da dire. */ ?>

<section class="card">
    <h2>Per corso</h2>
    <?php if ($courses === []): ?>
        <p class="empty-state-small">Nessun corso.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Corso</th><th>Iscritti</th><th>Completati</th><th>Certificati</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($courses as $course): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) $course['title']) ?>
                        <?php if ((int) $course['is_published'] === 0): ?>
                            <span class="badge">bozza</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $course['enrolled_count'] ?></td>
                    <td><?= (int) $course['completed_count'] ?></td>
                    <td><?= (int) $course['certificate_count'] ?></td>
                    <td class="row-actions">
                        <a href="/reports/courses/<?= (int) $course['id'] ?>">Dettaglio</a>
                        <a href="/reports/courses/<?= (int) $course['id'] ?>/csv">CSV</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php /* Sta subito dopo "Per corso" perché è la stessa lettura — un corso per
         riga — vista da un'altra angolazione. I corsi senza video non
         compaiono: non avrebbero niente da dire. */ ?>

<section class="card">
    <h2>Per gruppo</h2>
    <?php if ($groups === []): ?>
        <p class="empty-state-small">Nessun gruppo visibile.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Gruppo</th><th>Tutor</th><th>Membri</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $group): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $group['name']) ?></td>
                    <td><?= htmlspecialchars((string) ($group['tutor_name'] ?? '—')) ?></td>
                    <td><?= (int) $group['member_count'] ?></td>
                    <td class="row-actions">
                        <a href="/reports/groups/<?= (int) $group['id'] ?>">Dettaglio</a>
                        <a href="/reports/groups/<?= (int) $group['id'] ?>/csv">CSV</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Per studente</h2>
    <?php if ($students === []): ?>
        <p class="empty-state-small">Nessuno studente visibile.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Studente</th><th>Email</th><th>Corsi</th><th>Certificati</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) $student['full_name']) ?>
                        <?php if ((int) $student['is_active'] === 0): ?>
                            <span class="badge">disattivato</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string) $student['email']) ?></td>
                    <td><?= (int) $student['enrolled_count'] ?></td>
                    <td><?= (int) $student['certificate_count'] ?></td>
                    <td class="row-actions">
                        <a href="/reports/students/<?= (int) $student['id'] ?>">Dettaglio</a>
                        <a href="/reports/students/<?= (int) $student['id'] ?>/csv">CSV</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Per incontro dal vivo</h2>
    <?php if ($liveSessions === []): ?>
        <p class="empty-state-small">Nessun incontro.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Incontro</th><th>Quando</th><th>Corso o gruppo</th><th>Presenti</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($liveSessions as $sessione): ?>
                <?php $inizio = strtotime((string) $sessione['starts_at']); ?>
                <tr>
                    <td><?= htmlspecialchars((string) $sessione['title']) ?></td>
                    <td><?= $inizio === false ? '—' : htmlspecialchars(date('d/m/Y H:i', $inizio)) ?></td>
                    <td>
                        <?= htmlspecialchars((string) ($sessione['course_title'] ?? $sessione['group_name'] ?? '—')) ?>
                    </td>
                    <td><?= (int) $sessione['attended'] ?>/<?= (int) $sessione['expected'] ?></td>
                    <td class="row-actions">
                        <a href="/reports/live/<?= (int) $sessione['id'] ?>">Dettaglio</a>
                        <a href="/reports/live/<?= (int) $sessione['id'] ?>/csv">CSV</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Fruizione dei video</h2>
    <p class="hint">
        Quanta parte di ogni video hanno guardato gli studenti. È il dato da rendicontare:
        si scarica per corso, con una colonna per lezione.
    </p>
    <?php if ($corsiConVideo === []): ?>
        <p class="empty-state-small">Nessun corso ha lezioni con video.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Corso</th><th>Lezioni con video</th><th>Iscritti</th><th>Hanno aperto un video</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($corsiConVideo as $corso): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) $corso['title']) ?>
                        <?php if ((int) $corso['is_published'] === 0): ?>
                            <span class="badge">bozza</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $corso['lezioni_video'] ?></td>
                    <td><?= (int) $corso['iscritti'] ?></td>
                    <td><?= (int) $corso['avviati'] ?></td>
                    <td class="row-actions">
                        <a href="/reports/fruizione/<?= (int) $corso['id'] ?>">Dettaglio</a>
                        <a href="/reports/fruizione/<?= (int) $corso['id'] ?>/csv">CSV</a>
                        <?php if (App\Core\Xlsx::disponibile()): ?>
                            <a href="/reports/fruizione/<?= (int) $corso['id'] ?>/xlsx">XLSX</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
