<?php

declare(strict_types=1);

use App\Auth\Auth;

/** @var bool $isStaff */
/** @var array $certificates */
/** @var bool $dompdfAvailable */
?>
<div class="page-header">
    <h1>Certificati</h1>
    <p class="page-subtitle">
        <?= $isStaff
            ? 'Tutti i certificati emessi. L\'emissione è automatica quando lo studente completa lezioni e quiz del corso.'
            : 'I certificati che hai ottenuto completando lezioni e quiz dei tuoi corsi.' ?>
    </p>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if ($isStaff && !$dompdfAvailable): ?>
    <div class="alert alert-warning">
        Dompdf non risulta installato: i PDF non possono essere generati.
        Esegui <code>composer install</code> nella root del progetto.
    </div>
<?php endif; ?>

<?php if ($certificates === []): ?>
    <p class="empty-state">Nessun certificato<?= $isStaff ? ' emesso' : ' ancora disponibile' ?>.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
        <tr>
            <?php if ($isStaff): ?><th>Studente</th><?php endif; ?>
            <th>Corso</th>
            <th>Codice</th>
            <th>Emesso il</th>
            <th>Stato</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($certificates as $certificate): ?>
            <?php $revoked = $certificate['revoked_at'] !== null; ?>
            <tr>
                <?php if ($isStaff): ?>
                    <td><?= htmlspecialchars((string) ($certificate['full_name'] ?? '')) ?></td>
                <?php endif; ?>
                <td><?= htmlspecialchars((string) $certificate['course_title']) ?></td>
                <td><code><?= htmlspecialchars((string) $certificate['certificate_code']) ?></code></td>
                <td><?= htmlspecialchars((string) $certificate['issued_at']) ?></td>
                <td>
                    <?php if ($revoked): ?>
                        <span class="badge badge-danger">revocato</span>
                    <?php else: ?>
                        <span class="badge badge-success">valido</span>
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <?php if (!$revoked): ?>
                        <a href="/certificates/<?= (int) $certificate['id'] ?>/download">PDF</a>
                    <?php endif; ?>
                    <a href="/verify/<?= urlencode((string) $certificate['certificate_code']) ?>">Verifica</a>
                    <?php if (!$revoked && Auth::hasRole('admin', 'tutor')): ?>
                        <form action="/certificates/<?= (int) $certificate['id'] ?>/revoke" method="post"
                              onsubmit="return confirm('Revocare questo certificato?');">
                            <input type="hidden" name="redirect_to" value="/certificates">
                            <button type="submit" class="link-btn link-btn-danger">Revoca</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
