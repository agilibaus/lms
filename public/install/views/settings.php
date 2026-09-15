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
