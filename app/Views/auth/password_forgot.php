<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string|null $notice */

ob_start();
?>
<p class="auth-subtitle">Password dimenticata</p>

<?php if (!empty($notice)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<p>Inserisci l'indirizzo del tuo account: ti invieremo un link per scegliere una nuova password.</p>

<form action="/password/dimenticata" method="post" class="auth-form">
    <?= Csrf::field() ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus autocomplete="email">

    <button type="submit" class="btn btn-primary btn-block">Invia il link</button>
    <?php /* «Annulla» (14/10): non manda niente e torna all'accesso. */ ?>
    <a href="/login" class="btn btn-secondary btn-block">Annulla</a>
</form>

<p class="auth-links"><a href="/login">Torna all'accesso</a></p>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
