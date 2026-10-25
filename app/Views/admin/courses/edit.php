<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Icona;
use App\Core\Ordinamento;

/** @var array $course */
/** @var array $requests */
/** @var array $enrollments */
/** @var array $availableStudents */
/** @var bool $canDelete */
/** @var list<array<string, mixed>>|null $welcomes null se chi guarda non puo' caricare benvenuti */
/** @var list<array<string, mixed>>|null $bacheca le categorie della bacheca; null se chi guarda non le gestisce */
/** @var bool $tutor il tutor del corso (26/10): il suo benvenuto e i suoi studenti, in sola lettura */

$courseId = (int) $course['id'];

// Due tabelle, due insiemi di chiavi: l'indirizzo ne porta uno alla volta
// e l'altro lascia le sue righe come stanno.
$ordineRichieste = Ordinamento::daRichiesta([
    'richiedente' => ['full_name', Ordinamento::TESTO, 'email'],
    'richiesta' => ['requested_at', Ordinamento::DATA],
]);
$requests = $ordineRichieste->applica($requests);

$ordineIscritti = Ordinamento::daRichiesta([
    'studente' => ['full_name', Ordinamento::TESTO, 'email'],
    'iscritto' => ['enrolled_at', Ordinamento::DATA],
    'progresso' => ['progress_pct', Ordinamento::NUMERO],
]);
$enrollments = $ordineIscritti->applica($enrollments);
?>
<div class="page-header">
    <a href="/admin/courses" class="back-link"><span class="back-link-testo">Gestione corsi</span></a>
    <h1><?= htmlspecialchars((string) $course['title']) ?></h1>
    <p class="page-subtitle">
        <?= count($enrollments) ?> <?= count($enrollments) === 1 ? 'iscritto' : 'iscritti' ?> ·
        <a href="/courses/<?= $courseId ?>"><?= $tutor ? 'contenuti' : 'gestisci contenuti' ?></a> ·
        <a href="/reports/courses/<?= $courseId ?>">report</a>
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php /* Dal 26/10 i dati, la copertina e gli iscritti li gestisce l'admin;
         il tutor qui ha il suo benvenuto e l'elenco dei suoi studenti. */ ?>
<?php if (!$tutor): ?>
<section class="card">
    <h2>Dati del corso</h2>
    <form action="/admin/courses/<?= $courseId ?>" method="post" class="form">
        <?= Csrf::field() ?>
        <?php require __DIR__ . '/_fields.php'; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
            <button type="reset" class="btn btn-secondary">Annulla</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/_cover.php'; ?>
<?php endif; ?>

<?php if ($welcomes !== null): ?>
    <?php require __DIR__ . '/_welcomes.php'; ?>
<?php endif; ?>

<?php if ($bacheca !== null): ?>
    <?php require __DIR__ . '/_bacheca.php'; ?>
<?php endif; ?>

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
                            <button type="submit" class="link-btn comando-con-icona"><?= Icona::svg('check-lg') ?>Approva</button>
                        </form>
                        <form action="/admin/requests/<?= (int) $request['id'] ?>" method="post"
                              onsubmit="return confirm('Rifiutare la richiesta? Lo studente riceverà un avviso.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="decision" value="reject">
                            <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('x-lg') ?>Rifiuta</button>
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
        <?php if ($tutor): ?>
            I tuoi studenti in questo corso: si iscrivono dai tuoi gruppi. Le altre iscrizioni e le richieste dal
            catalogo le gestisce l’amministrazione.
        <?php else: ?>
            Le iscrizioni possono arrivare anche dai gruppi: assegnando il corso a un gruppo, i membri vengono iscritti qui.
        <?php endif; ?>
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
                <?php if (!$tutor): ?>
                    <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
                <?php endif; ?>
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
                    <?php if (!$tutor): ?>
                    <td role="cell" class="row-actions">
                        <form action="/admin/courses/<?= $courseId ?>/enrollments/<?= (int) $row['user_id'] ?>/delete" method="post"
                              onsubmit="return confirm('Rimuovere l’iscrizione? Progresso, tentativi dei questionari e certificato di questo corso verranno eliminati.');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?>Rimuovi</button>
                        </form>
                    </td>
                    <?php endif; ?>
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
    <?php /* Solo il comando e una breve spiegazione, senza titolo (Elena,
                 07/10): un titolo «Elimina …» sopra un comando «Elimina
                 definitivamente» diceva la stessa cosa due volte. Un `div` e non
                 una `section`: senza titolo non e' una sezione della pagina. */ ?>
    <div class="card">
        <p class="card-meta">Vengono rimossi anche moduli, lezioni, questionari, tentativi, iscrizioni e certificati collegati.</p>
        <form action="/admin/courses/<?= $courseId ?>/delete" method="post"
              onsubmit="return confirm('Eliminare definitivamente questo corso e tutti i dati collegati?');">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?>Elimina corso</button>
        </form>
    </div>
<?php endif; ?>
