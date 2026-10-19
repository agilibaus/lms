<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Icona;

/**
 * @var string $transport
 * @var array<string, string> $values
 * @var bool $hasPassword
 * @var array|null $lastUpdate
 * @var string|null $myEmail
 */

?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Server di posta elettronica</h1>
    <p class="page-subtitle">
        Come la piattaforma invia le sue email: verifiche dell'indirizzo, recuperi password, avvisi.
        Quello che salvi qui ha la precedenza sul file <code>.env</code>; lasciando un campo vuoto
        torna a valere il valore scritto nel file.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<section class="card">
    <h2>Invio</h2>

    <form action="/admin/settings/posta" method="post" class="form">
        <?= Csrf::field() ?>

        <label for="MAIL_TRANSPORT">Modalità</label>
        <select id="MAIL_TRANSPORT" name="MAIL_TRANSPORT">
            <option value="log" <?= $transport === 'log' ? 'selected' : '' ?>>
                Registro — i messaggi si leggono in storage/mail, non parte niente
            </option>
            <option value="mail" <?= $transport === 'mail' ? 'selected' : '' ?>>
                Funzione mail() del server
            </option>
            <option value="smtp" <?= $transport === 'smtp' ? 'selected' : '' ?>>
                Server SMTP
            </option>
        </select>
        <p class="form-hint">
            In prova tieni "Registro": nessuno studente riceve niente per sbaglio.
        </p>

        <p class="form-hint">
            Il mittente e il testo delle email si impostano nella pagina <a href="/admin/settings/email">Messaggi email</a>.
        </p>

        <h3>Server SMTP</h3>
        <p class="form-hint">Serve solo con la modalità "Server SMTP".</p>

        <label for="MAIL_HOST">Indirizzo del server</label>
        <input type="text" id="MAIL_HOST" name="MAIL_HOST"
               value="<?= htmlspecialchars($values['MAIL_HOST']) ?>"
               placeholder="smtp.tuoprovider.it">

        <label for="MAIL_PORT">Porta</label>
        <input type="text" id="MAIL_PORT" name="MAIL_PORT" inputmode="numeric"
               value="<?= htmlspecialchars($values['MAIL_PORT']) ?>" placeholder="587">

        <label for="MAIL_ENCRYPTION">Cifratura</label>
        <select id="MAIL_ENCRYPTION" name="MAIL_ENCRYPTION">
            <option value="tls" <?= $values['MAIL_ENCRYPTION'] === 'tls' ? 'selected' : '' ?>>TLS (porta 587)</option>
            <option value="ssl" <?= $values['MAIL_ENCRYPTION'] === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
            <option value="none" <?= $values['MAIL_ENCRYPTION'] === 'none' ? 'selected' : '' ?>>Nessuna</option>
        </select>

        <label for="MAIL_USERNAME">Utente</label>
        <input type="text" id="MAIL_USERNAME" name="MAIL_USERNAME" autocomplete="off"
               value="<?= htmlspecialchars($values['MAIL_USERNAME']) ?>">

        <label for="MAIL_PASSWORD">Password</label>
        <input type="password" id="MAIL_PASSWORD" name="MAIL_PASSWORD" autocomplete="new-password"
               placeholder="<?= $hasPassword ? 'impostata — scrivi qui per sostituirla' : 'nessuna password impostata' ?>">
        <p class="form-hint">
            La password non viene mai rimandata al browser: se lasci il campo vuoto resta quella di prima.
            <?php if ($hasPassword): ?>
                <label class="checkbox-inline">
                    <input type="checkbox" name="clear_password" value="1"> Cancella la password salvata
                </label>
            <?php endif; ?>
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
            <a href="/admin/settings" class="btn btn-secondary">Annulla</a>
        </div>
    </form>

    <?php if ($lastUpdate !== null): ?>
        <p class="card-meta">
            Ultima modifica da questa pagina:
            <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $lastUpdate['updated_at'])))
                . ($lastUpdate['full_name'] !== null ? ' — ' . htmlspecialchars((string) $lastUpdate['full_name']) : '')
                . '.' ?>
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Prova di invio</h2>
    <p class="card-meta">
        Manda un messaggio al tuo indirizzo<?= $myEmail !== null && $myEmail !== '' ? ' (' . htmlspecialchars($myEmail) . ')' : '' ?>,
        usando le impostazioni salvate. Salva prima di provare.
    </p>

    <form action="/admin/settings/posta/prova" method="post">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-secondary btn-con-icona"><?= Icona::svg('send') ?>Invia messaggio di prova</button>
    </form>
</section>
