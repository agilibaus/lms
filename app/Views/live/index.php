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
    <table class="data-table">
        <thead>
        <tr><th>Quando</th><th>Sessione</th><th>Destinatari</th><th>Meet</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($sessions as $session): ?>
            <?php
            $startsAt = new DateTimeImmutable((string) $session['starts_at']);
            $endsAt = new DateTimeImmutable((string) $session['ends_at']);
            $isLive = $startsAt <= $now && $now <= $endsAt;
            $isPast = $endsAt < $now;
            ?>
            <tr class="<?= $isPast ? 'row-past' : '' ?>">
                <td>
                    <?= htmlspecialchars($startsAt->format('d/m/Y H:i')) ?>–<?= htmlspecialchars($endsAt->format('H:i')) ?>
                    <?php if ($isLive): ?>
                        <span class="badge badge-success">in corso</span>
                    <?php elseif ($isPast): ?>
                        <span class="badge">conclusa</span>
                    <?php endif; ?>
                </td>
                <td><a href="/live/<?= (int) $session['id'] ?>"><?= htmlspecialchars((string) $session['title']) ?></a></td>
                <td>
                    <?php if (!empty($session['course_title'])): ?>
                        <span class="cell-sub"><?= htmlspecialchars((string) $session['course_title']) ?> · <?= htmlspecialchars((string) $session['module_title']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($session['group_name'])): ?>
                        <span class="cell-sub">Gruppo: <?= htmlspecialchars((string) $session['group_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
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
                <td class="row-actions">
                    <?php if (!empty($session['meet_link']) && !$isPast): ?>
                        <a href="/live/<?= (int) $session['id'] ?>/join">Entra</a>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <a href="/live/<?= (int) $session['id'] ?>/edit">Modifica</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
