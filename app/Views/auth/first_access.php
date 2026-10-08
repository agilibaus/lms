<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\PersonName;

/*
 * La pagina «Primo accesso» (08/10), nell'aspetto delle pagine di accesso:
 * senza menu, perche' in quel momento lo studente fa una cosa sola.
 *
 * Il titolo, «Ciao, Marta», e' quello della pagina e lo stampa _card.php;
 * e' senza genere: Pistacchio non sa se dire
 * «benvenuta» o «benvenuto» (la stessa scelta del saluto dopo l'accesso).
 * La scelta parte da «Solo il nome» (Elena, 08/10). Finche' lo studente non
 * preme «Continua», gli altri lo vedono con le sole iniziali (la protezione
 * predefinita, PersonName::PREDEFINITO).
 *
 * @var string $nome
 * @var string $cognome
 * @var bool $conPassword
 * @var string $scelta
 * @var int $minPassword
 * @var string $passwordHint
 * @var string|null $error
 */

$esempi = [
    PersonName::FULL => trim($nome . ' ' . $cognome),
    PersonName::FIRST => $nome,
    PersonName::INITIALS => PersonName::initials($nome, $cognome),
];
// L'ordine della pagina: dalla piu' riservata alla piu' aperta, cominciando
// da quella che vale finche' non si sceglie.
$ordine = [PersonName::INITIALS, PersonName::FIRST, PersonName::FULL];

ob_start();
?>
<p class="auth-subtitle"><?= $conPassword ? 'Due cose prima di cominciare.' : 'Una cosa prima di cominciare.' ?></p>

<?php if ($error !== null): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form action="<?= Auth::PRIMO_ACCESSO ?>" method="post" class="auth-form">
    <?= Csrf::field() ?>

    <?php if ($conPassword): ?>
        <label for="current_password">Password ricevuta per email</label>
        <input type="password" id="current_password" name="current_password" required
               autocomplete="current-password" autofocus>

        <label for="new_password">Nuova password</label>
        <input type="password" id="new_password" name="new_password" required
               minlength="<?= (int) $minPassword ?>" autocomplete="new-password">

        <label for="confirm_password">Conferma la nuova password</label>
        <input type="password" id="confirm_password" name="confirm_password" required
               minlength="<?= (int) $minPassword ?>" autocomplete="new-password">

        <p class="form-hint"><?= htmlspecialchars($passwordHint) ?></p>
    <?php endif; ?>

    <fieldset class="primo-accesso-scelta">
        <legend>Come ti vedono gli altri studenti</legend>
        <?php foreach ($ordine as $valore): ?>
            <label class="primo-accesso-opzione">
                <input type="radio" name="name_display" value="<?= $valore ?>" <?= $valore === $scelta ? 'checked' : '' ?>>
                <span><?= htmlspecialchars(PersonName::SCELTE[$valore]) ?>
                    <span class="primo-accesso-esempio">— «<?= htmlspecialchars($esempi[$valore]) ?>»</span></span>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <?php /* Corta, per un modulo meno alto (Elena, 08/10). */ ?>
    <p class="form-hint">Tutor e admin vedono sempre nome e cognome. Scelta modificabile dal profilo.</p>

    <button type="submit" class="btn btn-primary btn-block">Continua</button>
</form>

<?php /* L'uscita resta sempre aperta, come nel cambio password obbligato:
         chi e' entrato per sbaglio con un account altrui deve poter andarsene
         senza cambiare nulla. */ ?>
<form action="/logout" method="post" class="auth-links">
    <?= Csrf::field() ?>
    <button type="submit" class="link-btn">Esci</button>
</form>
<?php
$cardContent = ob_get_clean();

require __DIR__ . '/_card.php';
