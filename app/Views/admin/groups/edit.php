<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\GroupLogo;

/** @var array $group */
/** @var array $tutors */
/** @var bool $canChooseTutor */
/** @var array $members */
/** @var array $courses */
/** @var array $availableStudents */
/** @var array $availableCourses */

$groupId = (int) $group['id'];
?>
<div class="page-header">
    <a href="/admin/groups" class="back-link">&larr; Gruppi</a>
    <h1><?= htmlspecialchars((string) $group['name']) ?></h1>
    <p class="page-subtitle">
        <?= count($members) ?> membri · <?= count($courses) ?> corsi assegnati ·
        <a href="/reports/groups/<?= $groupId ?>">vedi report</a>
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<section class="card">
    <h2>Dati del gruppo</h2>
    <form action="/admin/groups/<?= $groupId ?>" method="post" class="form" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <?php require __DIR__ . '/_fields.php'; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>

    <?php if (GroupLogo::url($group) !== null): ?>
        <?php /* Modulo a se': dentro quello dei dati sarebbe stato il primo
                 pulsante di invio, e premere Invio nel nome del gruppo
                 avrebbe rimosso l'immagine invece di salvare. */ ?>
        <form action="/admin/groups/<?= $groupId ?>/logo/elimina" method="post"
              onsubmit="return confirm('Rimuovere l\'immagine di questo gruppo?');">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn link-btn-danger">Rimuovi immagine</button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Corsi assegnati</h2>
    <p class="card-meta">
        Assegnare un corso iscrive tutti i membri del gruppo; chi entra dopo viene iscritto automaticamente.
        Rimuovendo il corso le iscrizioni già create restano attive.
    </p>

    <?php if ($courses === []): ?>
        <p class="empty-state-small">Nessun corso assegnato.</p>
    <?php else: ?>
        <ul class="assign-list">
            <?php foreach ($courses as $course): ?>
                <li>
                    <a href="/admin/courses/<?= (int) $course['id'] ?>/edit"><?= htmlspecialchars((string) $course['title']) ?></a>
                    <form action="/admin/groups/<?= $groupId ?>/courses/<?= (int) $course['id'] ?>/delete" method="post"
                          onsubmit="return confirm('Rimuovere il corso dal gruppo? Gli iscritti restano tali.');">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-btn">Rimuovi</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($availableCourses !== []): ?>
        <form action="/admin/groups/<?= $groupId ?>/courses" method="post" class="form form-inline">
            <?= Csrf::field() ?>
            <select name="course_id" aria-label="Corso da assegnare">
                <?php foreach ($availableCourses as $course): ?>
                    <option value="<?= (int) $course['id'] ?>">
                        <?= htmlspecialchars((string) $course['title']) ?><?= (int) $course['is_published'] === 0 ? ' (bozza)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Assegna corso</button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Membri</h2>

    <?php if ($members === []): ?>
        <p class="empty-state-small">Nessun membro.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Nome</th><th>Email</th><th>Nel gruppo dal</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($members as $member): ?>
                <tr>
                    <td><a href="/reports/students/<?= (int) $member['id'] ?>"><?= htmlspecialchars((string) $member['full_name']) ?></a></td>
                    <td><?= htmlspecialchars((string) $member['email']) ?></td>
                    <td><?= htmlspecialchars((string) $member['joined_at']) ?></td>
                    <td class="row-actions">
                        <form action="/admin/groups/<?= $groupId ?>/members/<?= (int) $member['id'] ?>/delete" method="post"
                              onsubmit="return confirm('Rimuovere questo membro dal gruppo? Le iscrizioni ai corsi restano attive.');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn">Rimuovi</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($availableStudents !== []): ?>
        <form action="/admin/groups/<?= $groupId ?>/members" method="post" class="form form-inline">
            <?= Csrf::field() ?>
            <select name="user_id" aria-label="Studente da aggiungere">
                <?php foreach ($availableStudents as $student): ?>
                    <option value="<?= (int) $student['id'] ?>">
                        <?= htmlspecialchars((string) $student['full_name']) ?> — <?= htmlspecialchars((string) $student['email']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Aggiungi al gruppo</button>
        </form>
    <?php else: ?>
        <p class="form-hint">Tutti gli studenti registrati sono già nel gruppo.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="danger-heading">Elimina gruppo</h2>
    <form action="/admin/groups/<?= $groupId ?>/delete" method="post"
          onsubmit="return confirm('Eliminare questo gruppo? Le iscrizioni ai corsi restano attive.');">
        <?= Csrf::field() ?>
        <button type="submit" class="link-btn link-btn-danger">Elimina definitivamente</button>
    </form>
</section>
