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
        Il video che uno studente vede al primo accesso. Lo si può rivedere dal
        proprio profilo.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($configurato): ?>
    <div class="alert alert-success">
        Il benvenuto è attivo: chi accede per la prima volta vedrà il video prima dei
        propri corsi.
    </div>
<?php else: ?>
    <?php /* La frase di Alessandro (25/11). Tolto anche l'avviso «Chi è già
             iscritto non lo vedrà»: valeva per gli account che c'erano il
             05/10, e in produzione, con un'installazione nuova, gli studenti
             nascono tutti dopo. */ ?>
    <div class="alert alert-info">
        Nessun video configurato: la pagina di benvenuto compare dopo aver scelto un provider e
        inserito l'identificativo del video qui sotto.
    </div>
<?php endif; ?>

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
    <?php /* La frase di Alessandro (25/11). */ ?>
    <p class="form-hint">
        Gli stessi provider delle lezioni, meno il video caricato sul nostro server: quello
        utilizza l'identificativo di una lezione, e il benvenuto non è una lezione.
    </p>

    <label for="welcome-ref">Identificativo del video</label>
    <input type="text" id="welcome-ref" name="WELCOME_VIDEO_REF" maxlength="190"
           value="<?= htmlspecialchars($videoRef) ?>">
    <p class="form-hint">
        Su Bunny Stream è il GUID del video, lo stesso che si incolla nelle lezioni.
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
        <a href="/admin/settings" class="btn btn-secondary">Annulla</a>
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
