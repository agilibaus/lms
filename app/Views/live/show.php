<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $session */
/** @var bool $canManage */
/** @var array $participants */
/** @var array<int, array{joined_at: string|null, source: string}> $attendance */
/** @var bool $hasJoined */

$id = (int) $session['id'];
$startsAt = new DateTimeImmutable((string) $session['starts_at']);
$endsAt = new DateTimeImmutable((string) $session['ends_at']);
$now = new DateTimeImmutable('now');
$isPast = $endsAt < $now;
?>
<div class="page-header">
    <a href="/live" class="back-link">&larr; Sessioni live</a>
    <h1><?= htmlspecialchars((string) $session['title']) ?></h1>
    <p class="page-subtitle">
        <?= htmlspecialchars($startsAt->format('d/m/Y H:i')) ?>–<?= htmlspecialchars($endsAt->format('H:i')) ?>
        <?php if (!empty($session['course_title'])): ?>
            · <?= htmlspecialchars((string) $session['course_title']) ?> / <?= htmlspecialchars((string) $session['module_title']) ?>
        <?php endif; ?>
        <?php if (!empty($session['group_name'])): ?>
            · Gruppo <?= htmlspecialchars((string) $session['group_name']) ?>
        <?php endif; ?>
    </p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if (!empty($session['description'])): ?>
    <p class="course-description"><?= nl2br(htmlspecialchars((string) $session['description'])) ?></p>
<?php endif; ?>

<section class="card">
    <h2>Collegamento</h2>

    <?php if (!empty($session['meet_link'])): ?>
        <p class="card-meta">
            <?= !empty($session['google_event_id'])
                ? 'Link creato da Google Calendar insieme all’evento.'
                : 'Link inserito manualmente: questa sessione non è sincronizzata con Google Calendar.' ?>
        </p>
        <?php if ($isPast): ?>
            <p class="empty-state-small">La sessione è conclusa.</p>
        <?php else: ?>
            <p><a href="/live/<?= $id ?>/join" class="btn btn-primary">Entra nella sessione</a></p>
        <?php endif; ?>
        <?php if ($hasJoined): ?>
            <p class="form-hint">Il tuo ingresso è stato registrato.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="empty-state-small">Nessun link Meet associato a questa sessione.</p>
        <?php if ($canManage): ?>
            <form action="/live/<?= $id ?>/sync" method="post" class="form-inline">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-secondary">Crea l’evento su Google Calendar</button>
            </form>
            <p class="form-hint">In alternativa, inserisci un link manuale dalla pagina di modifica.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <h2>Presenze</h2>
        <p class="card-meta">
            L’ingresso viene registrato quando lo studente apre il Meet da questa piattaforma; qui puoi
            correggerlo o segnare le presenze a mano, anche a sessione conclusa.
        </p>

        <?php if ($participants === []): ?>
            <p class="empty-state-small">Nessun partecipante atteso: il modulo o il gruppo collegati non hanno iscritti.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                <tr><th>Partecipante</th><th>Ingresso</th><th>Origine</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($participants as $participant): ?>
                    <?php
                    $record = $attendance[(int) $participant['id']] ?? null;
                    $present = $record !== null && $record['joined_at'] !== null;
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars((string) $participant['full_name']) ?>
                            <span class="cell-sub"><?= htmlspecialchars((string) $participant['email']) ?></span>
                        </td>
                        <td>
                            <?php if ($present): ?>
                                <?= htmlspecialchars((new DateTimeImmutable((string) $record['joined_at']))->format('d/m/Y H:i')) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($present): ?>
                                <span class="badge <?= $record['source'] === 'manual' ? '' : 'badge-success' ?>">
                                    <?= $record['source'] === 'manual' ? 'segnata dal tutor' : 'piattaforma' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-danger">assente</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <form action="/live/<?= $id ?>/attendance/<?= (int) $participant['id'] ?>" method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="present" value="<?= $present ? '0' : '1' ?>">
                                <button type="submit" class="link-btn">
                                    <?= $present ? 'Segna assente' : 'Segna presente' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="danger-heading">Elimina sessione</h2>
        <p class="card-meta">Viene rimosso anche l’evento su Google Calendar, se collegato.</p>
        <form action="/live/<?= $id ?>/delete" method="post"
              onsubmit="return confirm('Eliminare questa sessione e le presenze registrate?');">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn link-btn-danger">Elimina definitivamente</button>
        </form>
    </section>
<?php endif; ?>
