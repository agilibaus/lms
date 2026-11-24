<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string $token */
/** @var bool $valid */
/** @var string|null $error */
/** @var int $minPassword */
/** @var string $passwordHint */
/** @var string|null $erroreCampo  il campo dell'errore: password o password_confirm */

ob_start();
?>
<p class="auth-subtitle">Scegli una nuova password</p>

<?php if (!$valid): ?>
    <div class="alert alert-error">Questo link non è valido, è scaduto oppure è già stato usato.</div>
    <p><a href="/password/dimenticata" class="btn btn-primary btn-block">Richiedine uno nuovo</a></p>
<?php else: ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error" id="errore-password"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php
    // I collegamenti per i lettori di schermo (24/11): la regola alla nuova
    // password, l'errore al campo che lo ha causato, segnato come non
    // valido; il cursore va su quel campo.
    $inErrore = !empty($error) ? (string) ($erroreCampo ?? 'password') : null;
    $attributi = static function (string $campo, array $descrizioni) use ($inErrore): string {
        if ($inErrore === $campo) {
            $descrizioni[] = 'errore-password';
        }

        return ($descrizioni !== [] ? ' aria-describedby="' . implode(' ', $descrizioni) . '"' : '')
            . ($inErrore === $campo ? ' aria-invalid="true"' : '')
            . (($inErrore ?? 'password') === $campo ? ' autofocus' : '');
    };
    ?>

    <form action="/password/reimposta/<?= htmlspecialchars($token) ?>" method="post" class="auth-form">
        <?= Csrf::field() ?>

        <label for="password">Nuova password</label>
        <input type="password" id="password" name="password" required minlength="<?= (int) $minPassword ?>"
               autocomplete="new-password"<?= $attributi('password', ['regola-password']) ?>>
        <?php /* La regola subito sotto il campo, non sotto i pulsanti (24/11). */ ?>
        <p class="form-hint regola-password" id="regola-password"><?= htmlspecialchars($passwordHint) ?></p>

        <label for="password_confirm">Conferma password</label>
        <input type="password" id="password_confirm" name="password_confirm" required
               minlength="<?= (int) $minPassword ?>" autocomplete="new-password"<?= $attributi('password_confirm', []) ?>>

        <?php /* «Salva la password» (24/11, Alessandro): era «Salva e accedi», ma
                 dopo il salvataggio si torna all'accesso e si entra con la nuova. */ ?>
        <button type="submit" class="btn btn-primary btn-block">Salva la password</button>
        <?php /* «Annulla» (14/10): non cambia la password e torna all'accesso. */ ?>
        <a href="/login" class="btn btn-secondary btn-block">Annulla</a>
    </form>
<?php endif; ?>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
