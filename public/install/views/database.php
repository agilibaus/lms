<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/** @var array<string, string> $data */
/** @var string|null $error */
/** @var string $token */
?>
<p>Indica il database da usare. Se non esiste, provo a crearlo con le stesse credenziali;
   se esiste, deve essere vuoto. Al termine di questo passo lo schema sarà importato.</p>

<?php if ($error !== null): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" action="?step=database" class="form">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($token) ?>">

    <label for="db_host">Host</label>
    <input type="text" id="db_host" name="db_host" required
           value="<?= htmlspecialchars($data['DB_HOST'] ?? '127.0.0.1') ?>">

    <label for="db_name">Nome del database</label>
    <input type="text" id="db_name" name="db_name" required placeholder="lms"
           value="<?= htmlspecialchars($data['DB_NAME'] ?? 'lms') ?>">

    <label for="db_user">Utente</label>
    <input type="text" id="db_user" name="db_user" required
           value="<?= htmlspecialchars($data['DB_USER'] ?? '') ?>">

    <label for="db_pass">Password</label>
    <input type="password" id="db_pass" name="db_pass" autocomplete="new-password"
           value="<?= htmlspecialchars($data['DB_PASS'] ?? '') ?>">

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Verifica e importa lo schema</button>
        <a href="?step=requirements" class="btn btn-secondary">Indietro</a>
    </div>
</form>
