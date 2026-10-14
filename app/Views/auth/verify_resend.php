<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string|null $error */

ob_start();
?>
<p class="auth-subtitle">Rinvia il link di conferma</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<p>Indica l'indirizzo usato in fase di registrazione: se risulta non ancora confermato,
   ti mandiamo un nuovo link.</p>

<form action="/register/rinvia" method="post" class="auth-form">
    <?= Csrf::field() ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus autocomplete="email">

    <button type="submit" class="btn btn-primary btn-block">Invia il link</button>
</form>

<p class="auth-links"><a href="/login">Torna all'accesso</a></p>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
