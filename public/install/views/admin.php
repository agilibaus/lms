<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/** @var array<string, string> $data */
/** @var string|null $error */
/** @var string $token */
?>
<div class="alert alert-success">Schema importato: il database è pronto.</div>

<p>Crea il primo amministratore. Da qui in poi tutti gli altri utenti si gestiscono dal pannello,
   senza toccare il database.</p>

<?php if ($error !== null): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" action="?step=admin" class="form">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($token) ?>">

    <label for="full_name">Nome e cognome</label>
    <input type="text" id="full_name" name="full_name" required maxlength="150"
           value="<?= htmlspecialchars($data['admin_name'] ?? '') ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required maxlength="190"
           value="<?= htmlspecialchars($data['admin_email'] ?? '') ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

    <label for="password_confirm">Conferma password</label>
    <input type="password" id="password_confirm" name="password_confirm" required minlength="8"
           autocomplete="new-password">
    <p class="form-hint">Almeno 8 caratteri. Sarà la password con cui accederai alla piattaforma.</p>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Continua</button>
    </div>
</form>
