<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Welcome;

/**
 * @var string $provider
 * @var string $videoRef
 * @var bool $configurato
 * @var array<string, string> $sources
 * @var array|null $lastUpdate
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Video di benvenuto</h1>
    <p class="page-subtitle">
        Il video che uno studente vede al primo accesso, una volta sola. Lo si può
        rivedere dal proprio profilo.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($configurato): ?>
    <div class="alert alert-success">
        Il benvenuto è attivo: chi accede per la prima volta vedrà il video prima dei
        propri corsi.
    </div>
<?php else: ?>
    <div class="alert alert-info">
        Nessun video configurato: la pagina di benvenuto non compare a nessuno. Si accende
        scegliendo un provider e indicando l'identificativo del video qui sotto.
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <strong>Chi è già iscritto non lo vedrà.</strong> Il benvenuto compare solo agli account
    creati dopo l'aggiornamento che ha introdotto questa funzione: gli altri risultano averlo
    già visto, e non vengono interrotti da una schermata nuova. Per farlo rivedere a una
    persona bisogna svuotare a mano la sua casella <code>welcome_seen_at</code>.
</div>

<form action="/admin/settings/benvenuto" method="post" class="form">
    <?= Csrf::field() ?>

    <label for="welcome-provider">Dove sta il video</label>
    <select id="welcome-provider" name="WELCOME_VIDEO_PROVIDER">
        <?php foreach (Welcome::PROVIDERS as $valore => $etichetta): ?>
            <option value="<?= htmlspecialchars($valore) ?>"
                    <?= $provider === $valore ? 'selected' : '' ?>>
                <?= htmlspecialchars($etichetta) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="form-hint">
        Gli stessi provider delle lezioni, meno il video caricato sul nostro server: quello
        viene servito passando dall'identificativo di una lezione, e il benvenuto non è una
        lezione.
    </p>

    <label for="welcome-ref">Identificativo del video</label>
    <input type="text" id="welcome-ref" name="WELCOME_VIDEO_REF" maxlength="190"
           value="<?= htmlspecialchars($videoRef) ?>">
    <p class="form-hint">
        Su Bunny Stream è il GUID del video, lo stesso che si incolla nelle lezioni.
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
    </div>
</form>

<?php if ($lastUpdate !== null): ?>
    <p class="form-hint">
        Ultima modifica:
        <?= htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $lastUpdate['updated_at'])))
            . ($lastUpdate['full_name'] !== null
                ? ' — ' . htmlspecialchars((string) $lastUpdate['full_name'])
                : '') ?>
    </p>
<?php endif; ?>
