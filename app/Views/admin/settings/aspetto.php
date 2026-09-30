<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * @var string $layout
 * @var array<string, string> $choices
 * @var array<string, string> $values
 * @var array<string, string> $defaults
 * @var array|null $lastUpdate
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link">← Impostazioni</a>
    <h1>Aspetto</h1>
    <p class="page-subtitle">
        Come si presentano le pagine pubbliche: accesso, registrazione, recupero password,
        nuova password e cambio password obbligato. La scelta vale per tutte e cinque insieme:
        cambiare aspetto nel giro di tre clic si leggerebbe come un difetto.
        Le pagine interne non sono toccate.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/settings/aspetto" method="post" class="form">
    <?= Csrf::field() ?>

    <section class="card">
        <h2>Struttura</h2>

        <?php foreach ($choices as $valore => $etichetta): ?>
            <label class="checkbox-label">
                <input type="radio" name="AUTH_LAYOUT" value="<?= htmlspecialchars($valore) ?>"
                       <?= $layout === $valore ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($etichetta) ?></span>
            </label>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <h2>Testi della presentazione</h2>
        <p class="form-hint" style="margin-top: 0;">
            Si vedono solo con l'aspetto affiancato, nella sezione di sinistra.
            Un campo lasciato vuoto usa il testo predefinito, quello che si legge in grigio.
        </p>

        <label for="AUTH_SPLIT_TITLE">Titolo</label>
        <textarea id="AUTH_SPLIT_TITLE" name="AUTH_SPLIT_TITLE" rows="2"
                  placeholder="<?= htmlspecialchars($defaults['AUTH_SPLIT_TITLE']) ?>"><?= htmlspecialchars($values['AUTH_SPLIT_TITLE']) ?></textarea>
        <p class="form-hint">
            Gli a capo che scrivi qui valgono anche nella pagina: servono a decidere tu dove
            spezza il titolo, invece di lasciarlo alla larghezza della finestra.
        </p>

        <label for="AUTH_SPLIT_TEXT">Testo sotto il titolo</label>
        <textarea id="AUTH_SPLIT_TEXT" name="AUTH_SPLIT_TEXT" rows="3"
                  placeholder="<?= htmlspecialchars($defaults['AUTH_SPLIT_TEXT']) ?>"><?= htmlspecialchars($values['AUTH_SPLIT_TEXT']) ?></textarea>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
        <a href="/login" class="btn btn-secondary" target="_blank" rel="noopener">Vedi la pagina di accesso</a>
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
