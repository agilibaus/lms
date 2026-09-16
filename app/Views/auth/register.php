<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string|null $error */
/** @var array $old */

ob_start();
?>
<p class="auth-subtitle">Crea il tuo account studente</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form action="/register" method="post" class="auth-form">
    <?= Csrf::field() ?>

    <label for="full_name">Nome e cognome</label>
    <input type="text" id="full_name" name="full_name" required maxlength="150" autofocus
           value="<?= htmlspecialchars((string) ($old['full_name'] ?? '')) ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required maxlength="190" autocomplete="email"
           value="<?= htmlspecialchars((string) ($old['email'] ?? '')) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

    <label for="password_confirm">Conferma password</label>
    <input type="password" id="password_confirm" name="password_confirm" required minlength="8"
           autocomplete="new-password">

    <button type="submit" class="btn btn-primary btn-block">Registrati</button>
</form>

<p class="auth-links">
    Hai già un account? <a href="/login">Accedi</a>
</p>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
