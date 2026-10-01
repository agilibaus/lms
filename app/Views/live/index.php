<?php

declare(strict_types=1);

/** @var array $sessions */
/** @var bool $canManage */
/** @var bool $googleConfigured */

$now = new DateTimeImmutable('now');
?>
<div class="page-header">
    <h1>Sessioni live</h1>
    <p class="page-subtitle">
        <?= $canManage
            ? 'Incontri su Google Meet collegati a un modulo di corso o a un gruppo.'
            : 'Gli incontri dal vivo dei tuoi corsi e gruppi.' ?>
    </p>
    <?php if ($canManage): ?>
        <p><a href="/live/create" class="btn btn-primary">+ Nuova sessione</a></p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if ($canManage && !$googleConfigured): ?>
    <div class="alert alert-warning">
        Google Calendar non è configurato: le sessioni si creano comunque, ma il link Meet va inserito a mano.
        Vedi la sezione “Sessioni live (Google Meet)” del README.
    </div>
<?php endif; ?>

<?php if ($sessions === []): ?>
    <p class="empty-state">Nessuna sessione in programma.</p>
<?php else: ?>
    <?php /* Cinque colonne: sotto i 50 rem di spazio diventa un elenco di
             schede (vedi `.tabella-schede`). */ ?>
    <div class="tabella-schede">
    <table class="data-table" role="table">
        <thead role="rowgroup">
        <tr role="row">
            <th scope="col" role="columnheader">Quando</th>
            <th scope="col" role="columnheader">Sessione</th>
            <th scope="col" role="columnheader">Destinatari</th>
            <th scope="col" role="columnheader">Meet</th>
            <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
        </tr>
        </thead>
        <tbody role="rowgroup">
        <?php foreach ($sessions as $session): ?>
            <?php
            $startsAt = new DateTimeImmutable((string) $session['starts_at']);
            $endsAt = new DateTimeImmutable((string) $session['ends_at']);
            $isLive = $startsAt <= $now && $now <= $endsAt;
            $isPast = $endsAt < $now;
            ?>
            <tr role="row" class="<?= $isPast ? 'row-past' : '' ?>">
                <td role="cell" data-label="Quando">
                    <?= htmlspecialchars($startsAt->format('d/m/Y H:i')) ?>–<?= htmlspecialchars($endsAt->format('H:i')) ?>
                    <?php if ($isLive): ?>
                        <span class="badge badge-success">in corso</span>
                    <?php elseif ($isPast): ?>
                        <span class="badge">conclusa</span>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Sessione"><a href="/live/<?= (int) $session['id'] ?>"><?= htmlspecialchars((string) $session['title']) ?></a></td>
                <td role="cell" data-label="Destinatari">
                    <?php if (!empty($session['course_title'])): ?>
                        <span class="cell-line"><?= htmlspecialchars((string) $session['course_title']) ?> · <?= htmlspecialchars((string) $session['module_title']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($session['group_name'])): ?>
                        <span class="cell-line">Gruppo: <?= htmlspecialchars((string) $session['group_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Meet">
                    <?php if (!empty($session['meet_link'])): ?>
                        <?php if (!empty($session['google_event_id'])): ?>
                            <span class="badge badge-success">Google</span>
                        <?php else: ?>
                            <span class="badge">manuale</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-danger">assente</span>
                    <?php endif; ?>
                </td>
                <td role="cell" class="row-actions">
                    <?php if (!empty($session['meet_link']) && !$isPast): ?>
                        <a href="/live/<?= (int) $session['id'] ?>/join"
                           target="_blank" rel="noopener">Entra</a>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <a href="/live/<?= (int) $session['id'] ?>/edit">Modifica</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
