<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string|null $error */
/** @var string|null $notice */
/** @var bool $unverified */

ob_start();
?>
<p class="auth-subtitle">Accedi alla piattaforma corsi</p>

<?php if (!empty($notice)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!empty($unverified)): ?>
    <p class="form-hint"><a href="/register/rinvia">Rinvia il link di conferma</a></p>
<?php endif; ?>

<form action="/login" method="post" class="auth-form">
    <?= Csrf::field() ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus autocomplete="username">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">

    <button type="submit" class="btn btn-primary btn-block">Accedi</button>
</form>

<p class="auth-links">
    <a href="/password/dimenticata">Password dimenticata?</a>
</p>
<p class="auth-links">
    Non hai un account? <a href="/register">Registrati</a>
</p>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
