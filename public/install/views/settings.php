<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/** @var array<string, string> $data */
/** @var string|null $error */
/** @var string $guessedUrl */
/** @var string $token */

$timezones = ['Europe/Rome', 'Europe/London', 'Europe/Madrid', 'Europe/Berlin', 'UTC'];
$current = $data['APP_TIMEZONE'] ?? 'Europe/Rome';
?>
<p>Ultimo passo. Sono tutte impostazioni facoltative: quelle lasciate vuote si compilano
   in seguito modificando il file <code>.env</code>.</p>

<?php if ($error !== null): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" action="?step=settings" class="form">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($token) ?>">

    <label for="app_url">Indirizzo pubblico della piattaforma</label>
    <input type="url" id="app_url" name="app_url"
           value="<?= htmlspecialchars($data['APP_URL'] ?? $guessedUrl) ?>">
    <p class="form-hint">Usato nel PDF del certificato per il link di verifica pubblica.</p>

    <label for="app_timezone">Fuso orario</label>
    <select id="app_timezone" name="app_timezone">
        <?php foreach ($timezones as $timezone): ?>
            <option value="<?= htmlspecialchars($timezone) ?>" <?= $current === $timezone ? 'selected' : '' ?>>
                <?= htmlspecialchars($timezone) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="checkbox-label">
        <input type="checkbox" name="app_debug" value="1" <?= ($data['APP_DEBUG'] ?? '0') === '1' ? 'checked' : '' ?>>
        Modalità diagnostica: mostra gli errori a video. Utile in locale, da tenere spenta in produzione.
    </label>

    <h2>Video</h2>
    <label for="bunny_library_id">Bunny Stream — Library ID</label>
    <input type="text" id="bunny_library_id" name="bunny_library_id"
           value="<?= htmlspecialchars($data['BUNNY_LIBRARY_ID'] ?? '') ?>">

    <label for="cloudflare_customer_code">Cloudflare Stream — Customer code</label>
    <input type="text" id="cloudflare_customer_code" name="cloudflare_customer_code"
           value="<?= htmlspecialchars($data['CLOUDFLARE_STREAM_CUSTOMER_CODE'] ?? '') ?>">

    <h2>Invio email</h2>
    <p class="form-hint">
        Serve per la conferma dell'indirizzo in fase di registrazione e per il recupero password.
        Lasciando "salva su file" la piattaforma funziona lo stesso: i messaggi finiscono in
        <code>storage/mail</code> e si leggono da lì — comodo in locale, da cambiare in produzione.
    </p>

    <label for="mail_transport">Modalità di invio</label>
    <?php $transport = $data['MAIL_TRANSPORT'] ?? 'log'; ?>
    <select id="mail_transport" name="mail_transport">
        <option value="log" <?= $transport === 'log' ? 'selected' : '' ?>>Salva su file (nessun invio)</option>
        <option value="smtp" <?= $transport === 'smtp' ? 'selected' : '' ?>>Server SMTP</option>
        <option value="mail" <?= $transport === 'mail' ? 'selected' : '' ?>>Funzione mail() di PHP</option>
    </select>

    <label for="mail_from_address">Indirizzo mittente</label>
    <input type="email" id="mail_from_address" name="mail_from_address" placeholder="no-reply@tuodominio.it"
           value="<?= htmlspecialchars($data['MAIL_FROM_ADDRESS'] ?? '') ?>">

    <label for="mail_from_name">Nome mittente</label>
    <input type="text" id="mail_from_name" name="mail_from_name"
           value="<?= htmlspecialchars($data['MAIL_FROM_NAME'] ?? 'Pistacchio LMS') ?>">

    <label for="mail_host">Server SMTP</label>
    <input type="text" id="mail_host" name="mail_host" placeholder="smtp.tuoprovider.it"
           value="<?= htmlspecialchars($data['MAIL_HOST'] ?? '') ?>">

    <label for="mail_port">Porta</label>
    <input type="number" id="mail_port" name="mail_port" value="<?= htmlspecialchars($data['MAIL_PORT'] ?? '587') ?>">

    <label for="mail_encryption">Cifratura</label>
    <?php $encryption = $data['MAIL_ENCRYPTION'] ?? 'tls'; ?>
    <select id="mail_encryption" name="mail_encryption">
        <option value="tls" <?= $encryption === 'tls' ? 'selected' : '' ?>>STARTTLS (porta 587)</option>
        <option value="ssl" <?= $encryption === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
        <option value="none" <?= $encryption === 'none' ? 'selected' : '' ?>>Nessuna</option>
    </select>

    <label for="mail_username">Utente SMTP</label>
    <input type="text" id="mail_username" name="mail_username" autocomplete="off"
           value="<?= htmlspecialchars($data['MAIL_USERNAME'] ?? '') ?>">

    <label for="mail_password">Password SMTP</label>
    <input type="password" id="mail_password" name="mail_password" autocomplete="new-password"
           value="<?= htmlspecialchars($data['MAIL_PASSWORD'] ?? '') ?>">

    <h2>Sessioni live su Google Meet</h2>
    <p class="form-hint">
        Senza queste impostazioni le sessioni live funzionano lo stesso, con il link Meet inserito a mano.
    </p>

    <label for="google_json">Percorso del file JSON dell'account di servizio</label>
    <input type="text" id="google_json" name="google_json" placeholder="/percorso/protetto/credenziali.json"
           value="<?= htmlspecialchars($data['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '') ?>">
    <p class="form-hint">Tienilo fuori dalla cartella pubblica.</p>

    <label for="google_impersonate">Utente Workspace da impersonare</label>
    <input type="email" id="google_impersonate" name="google_impersonate" placeholder="corsi@tuodominio.it"
           value="<?= htmlspecialchars($data['GOOGLE_IMPERSONATE_EMAIL'] ?? '') ?>">

    <label for="google_calendar_id">Calendario</label>
    <input type="text" id="google_calendar_id" name="google_calendar_id"
           value="<?= htmlspecialchars($data['GOOGLE_CALENDAR_ID'] ?? 'primary') ?>">

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Completa l'installazione</button>
    </div>
</form>
