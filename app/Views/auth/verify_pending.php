<?php

declare(strict_types=1);

/** @var string $email */
/** @var bool $usesLogTransport */
/** @var string|null $notice */

ob_start();
?>
<p class="auth-subtitle">Controlla la posta</p>

<?php if (!empty($notice)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<p>
    Se l'indirizzo <strong><?= htmlspecialchars($email) ?></strong> non era già registrato,
    abbiamo inviato un messaggio con il link per confermarlo. Il link vale 24 ore: fino ad
    allora l'accesso resta bloccato.
</p>

<p class="form-hint">Non trovi l'email? Guarda anche nella posta indesiderata.</p>

<?php if ($usesLogTransport): ?>
    <div class="alert alert-warning">
        Questa installazione non invia email: i messaggi vengono salvati come file in
        <code>storage/mail</code>. Apri il file più recente e usa il link che contiene.
    </div>
<?php endif; ?>

<p class="auth-links">
    <a href="/register/rinvia">Rinvia il link</a> · <a href="/login">Torna all'accesso</a>
</p>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
