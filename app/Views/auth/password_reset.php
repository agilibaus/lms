<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string $token */
/** @var bool $valid */
/** @var string|null $error */
/** @var int $minPassword */

ob_start();
?>
<p class="auth-subtitle">Scegli una nuova password</p>

<?php if (!$valid): ?>
    <div class="alert alert-error">Questo link non è valido, è scaduto oppure è già stato usato.</div>
    <p><a href="/password/dimenticata" class="btn btn-primary btn-block">Richiedine uno nuovo</a></p>
<?php else: ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form action="/password/reimposta/<?= htmlspecialchars($token) ?>" method="post" class="auth-form">
        <?= Csrf::field() ?>

        <label for="password">Nuova password</label>
        <input type="password" id="password" name="password" required minlength="<?= (int) $minPassword ?>"
               autofocus autocomplete="new-password">

        <label for="password_confirm">Conferma password</label>
        <input type="password" id="password_confirm" name="password_confirm" required
               minlength="<?= (int) $minPassword ?>" autocomplete="new-password">

        <button type="submit" class="btn btn-primary btn-block">Salva e accedi</button>
    </form>
<?php endif; ?>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
