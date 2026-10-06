<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Ordinamento;

/** @var bool $isStaff */
/** @var array $certificates */
/** @var bool $dompdfAvailable */

$ordine = Ordinamento::daRichiesta([
    'studente' => ['full_name', Ordinamento::TESTO, 'email'],
    'corso' => ['course_title', Ordinamento::TESTO],
    'codice' => ['certificate_code', Ordinamento::TESTO],
    'emesso' => ['issued_at', Ordinamento::DATA],
    // Revocato o valido. **Non** si ordina su `revoked_at`: i validi non
    // ce l'hanno, e i vuoti vanno in fondo in tutti e due i versi, quindi
    // non si potrebbero mai portare in cima. Si ordina su un valore
    // calcolato qui, che e' 0 o 1 e si rovescia come ci si aspetta.
    'stato' => ['stato_revoca', Ordinamento::NUMERO],
]);

$certificates = array_map(
    static fn (array $c): array => $c + ['stato_revoca' => $c['revoked_at'] === null ? 0 : 1],
    $certificates
);
$certificates = $ordine->applica($certificates);
?>
<div class="page-header">
    <h1>Certificati</h1>
    <p class="page-subtitle">
        <?= $isStaff
            ? 'Tutti i certificati emessi. L\'emissione è automatica quando lo studente completa lezioni e questionari del corso.'
            : 'I certificati che hai ottenuto completando lezioni e questionari dei tuoi corsi.' ?>
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
    <?php /* Cinque colonne piu' i comandi non stanno su un telefono: sotto i
             50 rem di spazio la tabella diventa un elenco di schede, come i
             report. I `role` espliciti servono perche' cambiando il `display`
             la tabella perderebbe le proprie semantiche. */ ?>
    <div class="tabella-schede">
    <table class="data-table" role="table">
        <thead role="rowgroup">
        <tr role="row">
            <?php if ($isStaff): ?><?= $ordine->th('Studente', 'studente') ?><?php endif; ?>
            <?= $ordine->th('Corso', 'corso') ?>
            <?= $ordine->th('Codice', 'codice') ?>
            <?= $ordine->th('Emesso il', 'emesso') ?>
            <?= $ordine->th('Stato', 'stato') ?>
            <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
        </tr>
        </thead>
        <tbody role="rowgroup">
        <?php foreach ($certificates as $certificate): ?>
            <?php $revoked = $certificate['revoked_at'] !== null; ?>
            <tr role="row">
                <?php if ($isStaff): ?>
                    <td role="cell" data-label="Studente"><?= htmlspecialchars((string) ($certificate['full_name'] ?? '')) ?></td>
                <?php endif; ?>
                <td role="cell" data-label="Corso"><?= htmlspecialchars((string) $certificate['course_title']) ?></td>
                <td role="cell" data-label="Codice"><code><?= htmlspecialchars((string) $certificate['certificate_code']) ?></code></td>
                <td role="cell" data-label="Emesso il"><?= htmlspecialchars((string) $certificate['issued_at']) ?></td>
                <td role="cell" data-label="Stato">
                    <?php if ($revoked): ?>
                        <span class="badge badge-danger">revocato</span>
                    <?php else: ?>
                        <span class="badge badge-success">valido</span>
                    <?php endif; ?>
                </td>
                <td role="cell" class="row-actions">
                    <?php if (!$revoked): ?>
                        <a href="/certificates/<?= (int) $certificate['id'] ?>/download">PDF</a>
                    <?php endif; ?>
                    <a href="/verify/<?= urlencode((string) $certificate['certificate_code']) ?>">Verifica</a>
                    <?php if (!$revoked && Auth::can('certificate.issue')): ?>
                        <form action="/certificates/<?= (int) $certificate['id'] ?>/revoke" method="post"
                              onsubmit="return confirm('Revocare questo certificato?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="redirect_to" value="/certificates">
                            <button type="submit" class="link-btn link-btn-danger">Revoca</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
