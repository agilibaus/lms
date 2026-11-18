<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Privacy;

/** @var string|null $error */
/** @var array $old */
/** @var int $minPassword */
/** @var string $passwordHint */

ob_start();
?>
<p class="auth-subtitle">Crea il tuo account studente</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form action="/register" method="post" class="auth-form">
    <?= Csrf::field() ?>

    <?php /* Nome e cognome separati (07/10): dal profilo lo studente potra'
             scegliere di comparire agli altri con il solo nome o le iniziali. */ ?>
    <?php /* Affiancati (Elena, 07/10): uno sopra l'altro allungavano il modulo
             senza bisogno. Dove lo spazio non basta vanno a capo da soli. */ ?>
    <div class="campi-affiancati">
        <div>
            <label for="first_name">Nome</label>
            <input type="text" id="first_name" name="first_name" required maxlength="100" autofocus
                   autocomplete="given-name"
                   value="<?= htmlspecialchars((string) ($old['first_name'] ?? '')) ?>">
        </div>
        <div>
            <label for="last_name">Cognome</label>
            <input type="text" id="last_name" name="last_name" required maxlength="100"
                   autocomplete="family-name"
                   value="<?= htmlspecialchars((string) ($old['last_name'] ?? '')) ?>">
        </div>
    </div>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required maxlength="190" autocomplete="email"
           value="<?= htmlspecialchars((string) ($old['email'] ?? '')) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required
           minlength="<?= (int) $minPassword ?>" autocomplete="new-password">

    <label for="password_confirm">Conferma password</label>
    <input type="password" id="password_confirm" name="password_confirm" required
           minlength="<?= (int) $minPassword ?>" autocomplete="new-password">
    <p class="form-hint"><?= htmlspecialchars($passwordHint) ?></p>

    <button type="submit" class="btn btn-primary btn-block">Registrati</button>

    <?php /* Privacy (18/11): l'informativa si comunica qui, con una frase e
             il collegamento. Nessuna casella da spuntare: e' una
             comunicazione, non un consenso. Solo quando ce n'e' una pubblicata. */ ?>
    <?php if (Privacy::inVigore() !== null): ?>
        <p class="form-hint nota-privacy">
            Registrandoti dichiari di aver preso visione della nostra
            <a href="<?= htmlspecialchars(Privacy::link('registrazione')) ?>" data-privacy-registrazione>Informativa sulla privacy</a>.
        </p>
    <?php endif; ?>
</form>

<p class="auth-links">
    Hai già un account? <a href="/login">Accedi</a>
</p>
<?php if (Privacy::inVigore() !== null): ?>
    <script src="/assets/js/registrazione-privacy.js" defer></script>
<?php endif; ?>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
