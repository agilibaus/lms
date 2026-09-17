<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\OrphanFiles;

/** @var array $courses */
/** @var bool $canCreate */
/** @var bool $canDelete */
/** @var int $pendingRequests */
?>
<div class="page-header">
    <h1>Gestione corsi</h1>
    <p class="page-subtitle">Creazione, pubblicazione e iscrizioni. I contenuti (moduli, lezioni, quiz) si gestiscono dalla scheda del corso.</p>
    <?php if ($canCreate): ?>
        <p><a href="/admin/courses/create" class="btn btn-primary">+ Nuovo corso</a></p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($pendingRequests > 0): ?>
    <div class="alert alert-warning">
        Ci sono <?= (int) $pendingRequests ?> richieste di iscrizione in attesa: le trovi nella scheda dei corsi interessati.
    </div>
<?php endif; ?>

<?php if ($courses === []): ?>
    <p class="empty-state">Nessun corso.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
        <tr><th>Titolo</th><th>Stato</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($courses as $course): ?>
            <tr>
                <td>
                    <a href="/admin/courses/<?= (int) $course['id'] ?>/edit"><?= htmlspecialchars((string) $course['title']) ?></a>
                    <span class="cell-sub">/<?= htmlspecialchars((string) $course['slug']) ?></span>
                </td>
                <td>
                    <?php if ((int) $course['is_published'] === 1): ?>
                        <span class="badge badge-success">pubblicato</span>
                    <?php else: ?>
                        <span class="badge">bozza</span>
                    <?php endif; ?>
                    <?php if (($course['enrollment_mode'] ?? 'closed') !== 'closed'): ?>
                        <span class="badge"><?= $course['enrollment_mode'] === 'open' ? 'iscrizione libera' : 'su richiesta' ?></span>
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <a href="/courses/<?= (int) $course['id'] ?>">Contenuti</a>
                    <a href="/reports/courses/<?= (int) $course['id'] ?>">Report</a>
                    <?php if ($canDelete): ?>
                        <form action="/admin/courses/<?= (int) $course['id'] ?>/delete" method="post"
                              onsubmit="return confirm('Eliminare il corso con moduli, lezioni, quiz, iscrizioni e certificati?');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn link-btn-danger">Elimina</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
// Riepilogo dei file caricati che nessuna lezione usa piu'. Sta qui, in un
// punto solo del pannello, perche' il calcolo legge le cartelle di /storage
// e ripeterlo a ogni pagina sarebbe spreco.
$scollegati = OrphanFiles::summary();
?>
<section class="card orphan-summary">
    <h2>File sul server non collegati a nessuna lezione</h2>

    <?php if ($scollegati['total']['count'] === 0): ?>
        <p class="card-meta">Nessuno: ogni file caricato è usato da una lezione.</p>
    <?php else: ?>
        <p class="card-meta">
            Restano <strong><?= (int) $scollegati['total']['count'] ?></strong> file per
            <strong><?= htmlspecialchars(OrphanFiles::humanSize($scollegati['total']['bytes'])) ?></strong>.
            Nascono togliendo un video dalla lezione senza eliminarlo, sostituendone uno, o
            eliminando una lezione: la piattaforma non cancella mai da sola i file caricati.
        </p>

        <table class="data-table">
            <thead>
            <tr><th>Cartella</th><th>File</th><th>Spazio</th></tr>
            </thead>
            <tbody>
            <?php foreach ($scollegati['folders'] as $nome => $dati): ?>
                <?php if ($dati['count'] === 0) { continue; } ?>
                <tr>
                    <td><code>storage/<?= htmlspecialchars((string) $nome) ?></code></td>
                    <td><?= (int) $dati['count'] ?></td>
                    <td><?= htmlspecialchars(OrphanFiles::humanSize($dati['bytes'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="form-hint">
            Vanno cancellati a mano dal server: nessuna pagina può sapere se ti servono ancora.
        </p>
    <?php endif; ?>
</section>
