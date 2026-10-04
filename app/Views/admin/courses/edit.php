<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Ordinamento;

/** @var array $course */
/** @var array $requests */
/** @var array $enrollments */
/** @var array $availableStudents */
/** @var bool $canDelete */

$courseId = (int) $course['id'];

// Due tabelle, due insiemi di chiavi: l'indirizzo ne porta uno alla volta
// e l'altro lascia le sue righe come stanno.
$ordineRichieste = Ordinamento::daRichiesta([
    'richiedente' => ['full_name', Ordinamento::TESTO],
    'richiesta' => ['requested_at', Ordinamento::DATA],
]);
$requests = $ordineRichieste->applica($requests);

$ordineIscritti = Ordinamento::daRichiesta([
    'studente' => ['full_name', Ordinamento::TESTO],
    'iscritto' => ['enrolled_at', Ordinamento::DATA],
    'progresso' => ['progress_pct', Ordinamento::NUMERO],
]);
$enrollments = $ordineIscritti->applica($enrollments);
?>
<div class="page-header">
    <a href="/admin/courses" class="back-link">&larr; Gestione corsi</a>
    <h1><?= htmlspecialchars((string) $course['title']) ?></h1>
    <p class="page-subtitle">
        <?= count($enrollments) ?> iscritti ·
        <a href="/courses/<?= $courseId ?>">gestisci contenuti</a> ·
        <a href="/reports/courses/<?= $courseId ?>">report</a>
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<section class="card">
    <h2>Dati del corso</h2>
    <form action="/admin/courses/<?= $courseId ?>" method="post" class="form">
        <?= Csrf::field() ?>
        <?php require __DIR__ . '/_fields.php'; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/_cover.php'; ?>

<?php if ($requests !== []): ?>
    <section class="card">
        <h2>Richieste di iscrizione <span class="badge badge-danger"><?= count($requests) ?></span></h2>
        <p class="card-meta">
            Approvando, lo studente viene iscritto e riceve un'email; rifiutando, riceve un avviso.
        </p>

        <?php /* Sotto i 50 rem di spazio diventa un elenco di schede. */ ?>
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <?= $ordineRichieste->th('Studente', 'richiedente') ?>
                <th scope="col" role="columnheader">Messaggio</th>
                <?= $ordineRichieste->th('Richiesta del', 'richiesta') ?>
                <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($requests as $request): ?>
                <tr role="row">
                    <td role="cell" data-label="Studente">
                        <?= htmlspecialchars((string) $request['full_name']) ?>
                        <span class="cell-sub"><?= htmlspecialchars((string) $request['email']) ?></span>
                    </td>
                    <td role="cell" data-label="Messaggio"><?= $request['message'] !== null ? nl2br(htmlspecialchars((string) $request['message'])) : '—' ?></td>
                    <td role="cell" data-label="Richiesta del"><?= htmlspecialchars((string) $request['requested_at']) ?></td>
                    <td role="cell" class="row-actions">
                        <form action="/admin/requests/<?= (int) $request['id'] ?>" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="decision" value="approve">
                            <button type="submit" class="link-btn">Approva</button>
                        </form>
                        <form action="/admin/requests/<?= (int) $request['id'] ?>" method="post"
                              onsubmit="return confirm('Rifiutare la richiesta? Lo studente riceverà un avviso.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="decision" value="reject">
                            <button type="submit" class="link-btn link-btn-danger">Rifiuta</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Iscritti</h2>
    <p class="card-meta">
        Le iscrizioni possono arrivare anche dai gruppi: assegnando il corso a un gruppo, i membri vengono iscritti qui.
    </p>

    <?php if ($enrollments === []): ?>
        <p class="empty-state-small">Nessuno studente iscritto.</p>
    <?php else: ?>
        <?php /* Come sopra. */ ?>
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <?= $ordineIscritti->th('Studente', 'studente') ?>
                <?= $ordineIscritti->th('Iscritto il', 'iscritto') ?>
                <?= $ordineIscritti->th('Progresso', 'progresso') ?>
                <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($enrollments as $row): ?>
                <tr role="row">
                    <td role="cell" data-label="Studente">
                        <a href="/reports/students/<?= (int) $row['user_id'] ?>"><?= htmlspecialchars((string) $row['full_name']) ?></a>
                        <span class="cell-sub"><?= htmlspecialchars((string) $row['email']) ?></span>
                    </td>
                    <td role="cell" data-label="Iscritto il"><?= htmlspecialchars((string) $row['enrolled_at']) ?></td>
                    <td role="cell" data-label="Progresso">
                        <?= number_format((float) $row['progress_pct'], 0) ?>%
                        <?php if ($row['completed_at'] !== null): ?>
                            <span class="badge badge-success">completato</span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" class="row-actions">
                        <form action="/admin/courses/<?= $courseId ?>/enrollments/<?= (int) $row['user_id'] ?>/delete" method="post"
                              onsubmit="return confirm('Rimuovere l’iscrizione? Progresso, tentativi quiz e certificato di questo corso verranno eliminati.');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn link-btn-danger">Rimuovi</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($availableStudents !== []): ?>
        <form action="/admin/courses/<?= $courseId ?>/enrollments" method="post" class="form form-inline">
            <?= Csrf::field() ?>
            <select name="user_id" aria-label="Studente da iscrivere">
                <?php foreach ($availableStudents as $student): ?>
                    <option value="<?= (int) $student['id'] ?>">
                        <?= htmlspecialchars((string) $student['full_name']) ?> — <?= htmlspecialchars((string) $student['email']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Iscrivi</button>
        </form>
    <?php else: ?>
        <p class="form-hint">Tutti gli studenti registrati sono già iscritti.</p>
    <?php endif; ?>
</section>

<?php if ($canDelete): ?>
    <section class="card">
        <h2 class="danger-heading">Elimina corso</h2>
        <p class="card-meta">Vengono rimossi anche moduli, lezioni, quiz, tentativi, iscrizioni e certificati collegati.</p>
        <form action="/admin/courses/<?= $courseId ?>/delete" method="post"
              onsubmit="return confirm('Eliminare definitivamente questo corso e tutti i dati collegati?');">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn link-btn-danger">Elimina definitivamente</button>
        </form>
    </section>
<?php endif; ?>
