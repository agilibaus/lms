<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $course */
/** @var array $requests */
/** @var array $enrollments */
/** @var array $availableStudents */
/** @var bool $canDelete */

$courseId = (int) $course['id'];
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

<?php if ($requests !== []): ?>
    <section class="card">
        <h2>Richieste di iscrizione <span class="badge badge-danger"><?= count($requests) ?></span></h2>
        <p class="card-meta">
            Approvando, lo studente viene iscritto e riceve un'email; rifiutando, riceve un avviso.
        </p>

        <table class="data-table">
            <thead>
            <tr><th>Studente</th><th>Messaggio</th><th>Richiesta del</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) $request['full_name']) ?>
                        <span class="cell-sub"><?= htmlspecialchars((string) $request['email']) ?></span>
                    </td>
                    <td><?= $request['message'] !== null ? nl2br(htmlspecialchars((string) $request['message'])) : '—' ?></td>
                    <td><?= htmlspecialchars((string) $request['requested_at']) ?></td>
                    <td class="row-actions">
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
        <table class="data-table">
            <thead>
            <tr><th>Studente</th><th>Iscritto il</th><th>Progresso</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($enrollments as $row): ?>
                <tr>
                    <td>
                        <a href="/reports/students/<?= (int) $row['user_id'] ?>"><?= htmlspecialchars((string) $row['full_name']) ?></a>
                        <span class="cell-sub"><?= htmlspecialchars((string) $row['email']) ?></span>
                    </td>
                    <td><?= htmlspecialchars((string) $row['enrolled_at']) ?></td>
                    <td>
                        <?= number_format((float) $row['progress_pct'], 0) ?>%
                        <?php if ($row['completed_at'] !== null): ?>
                            <span class="badge badge-success">completato</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
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
