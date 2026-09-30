<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;

/** @var string $pageTitle */
/** @var bool $obbligato */
/** @var int $minPassword */
/** @var string $passwordHint */
/** @var string|null $error */

/*
 * Due vestiti per la stessa pagina. Con una password temporanea la pagina
 * arriva nella cornice delle pagine di accesso, senza la barra laterale: e'
 * l'unica pagina raggiungibile, e un menu i cui collegamenti riportano tutti
 * qui confonderebbe. Negli altri casi e' una normale pagina interna,
 * raggiunta dal profilo.
 *
 * Il modulo e' scritto una volta sola: due copie da tenere allineate sono il
 * modo in cui nascono le differenze (pistacchio-lms.md Sezione 4).
 */

ob_start();
?>
<?php if ($obbligato): ?>
    <p class="auth-subtitle">Scegli la tua password</p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($obbligato): ?>
    <div class="alert alert-info">
        Stai usando una password temporanea, generata da chi amministra la piattaforma.
        Scegline una tua per continuare: fino ad allora le altre pagine non sono
        raggiungibili.
    </div>
<?php endif; ?>

<form action="<?= Auth::PASSWORD_PAGE ?>" method="post" class="<?= $obbligato ? 'auth-form' : 'form' ?>">
    <?= Csrf::field() ?>

    <label for="current_password">Password attuale</label>
    <input type="password" id="current_password" name="current_password" required
           autocomplete="current-password" autofocus>

    <label for="new_password">Nuova password</label>
    <input type="password" id="new_password" name="new_password" required
           minlength="<?= (int) $minPassword ?>" autocomplete="new-password">

    <label for="confirm_password">Ripeti la nuova password</label>
    <input type="password" id="confirm_password" name="confirm_password" required
           minlength="<?= (int) $minPassword ?>" autocomplete="new-password">

    <?php if ($obbligato): ?>
        <button type="submit" class="btn btn-primary btn-block">Cambia password</button>
    <?php else: ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Cambia password</button>
            <a href="/profilo" class="btn btn-secondary">Annulla</a>
        </div>
    <?php endif; ?>
</form>

<p class="form-hint">
    <?= htmlspecialchars($passwordHint) ?>
    Cambiando la password, le altre sessioni aperte con quella precedente vengono chiuse;
    questo browser resta collegato.
</p>

<?php if ($obbligato): ?>
    <?php /* L'uscita resta sempre aperta: chi e' entrato per sbaglio con un
             account altrui deve poter andarsene senza cambiare nulla. */ ?>
    <form action="/logout" method="post" class="auth-links">
        <?= Csrf::field() ?>
        <button type="submit" class="link-btn">Esci</button>
    </form>
<?php endif; ?>
<?php
$cardContent = ob_get_clean();

if ($obbligato) {
    require __DIR__ . '/../auth/_card.php';

    return;
}
?>
<div class="page-header">
    <a href="/profilo" class="back-link">← Profilo</a>
    <h1>Cambia password</h1>
</div>

<div class="card">
    <?= $cardContent ?>
</div>
