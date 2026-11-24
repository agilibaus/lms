<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Privacy;

/** @var string|null $error */
/** @var string|null $notice */
/** @var bool $unverified */
/** @var bool $credenziali  email o password sbagliate: i campi sono da correggere */

ob_start();
?>
<p class="auth-subtitle">Accedi alla piattaforma corsi</p>

<?php if (!empty($notice)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error" id="errore-accesso"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php
// L'avviso collegato ai due campi (24/11): chi usa un lettore di schermo lo
// sente arrivando sull'email, dove va il cursore. Con email o password
// sbagliate i campi sono anche segnati come non validi.
$collega = !empty($error) ? ' aria-describedby="errore-accesso"' . (!empty($credenziali) ? ' aria-invalid="true"' : '') : '';
?>

<?php if (!empty($unverified)): ?>
    <p class="form-hint"><a href="/register/rinvia">Rinvia il link di conferma</a></p>
<?php endif; ?>

<form action="/login" method="post" class="auth-form">
    <?= Csrf::field() ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus autocomplete="username"<?= $collega ?>>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password"<?= $collega ?>>

    <button type="submit" class="btn btn-primary btn-block">Accedi</button>
</form>

<p class="auth-links">
    <a href="/password/dimenticata">Password dimenticata?</a>
</p>
<p class="auth-links">
    Non hai un account? <a href="/register">Registrati</a>
</p>
<?php /* Privacy (18/11): una parola sola, piccola e grigia. Le linee guida
         europee sulla trasparenza (WP260) chiedono il collegamento in ogni
         pagina, e serve a chi non riesce a entrare. */ ?>
<?php if (Privacy::inVigore() !== null): ?>
    <p class="auth-privacy"><a class="auth-privacy-link" href="<?= htmlspecialchars(Privacy::link('accesso')) ?>">Privacy</a></p>
<?php endif; ?>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
