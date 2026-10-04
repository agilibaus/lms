<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Theme;

/**
 * @var string $layout
 * @var array<string, string> $choices
 * @var array<string, string> $values
 * @var array<string, string> $defaults
 * @var string $palette
 * @var array<string, array<string, string>> $tavolozze
 * @var string $primario
 * @var string $primarioInVigore
 * @var array|null $lastUpdate
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link">← Impostazioni</a>
    <h1>Aspetto</h1>
    <p class="page-subtitle">
        Due cose, con due portate diverse. <strong>I colori</strong> valgono per tutta la
        piattaforma, dentro e fuori. <strong>Struttura e testi</strong> riguardano solo le
        cinque pagine pubbliche — accesso, registrazione, recupero password, nuova password
        e cambio password obbligato — e valgono per tutte e cinque insieme: cambiare aspetto
        nel giro di tre clic si leggerebbe come un difetto.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/settings/aspetto" method="post" class="form">
    <?= Csrf::field() ?>

    <section class="card">
        <h2>Colori</h2>
        <p class="form-hint" style="margin-top: 0;">
            Valgono su <strong>tutte</strong> le pagine, non solo su quelle pubbliche.
            Le quattro tavolozze sono state verificate sui contrasti prima di essere offerte:
            qualunque si scelga, i testi restano leggibili.
        </p>

        <fieldset class="tavolozze">
            <legend class="sr-only">Tavolozza</legend>
            <?php foreach ($tavolozze as $chiave => $t): ?>
                <label class="tavolozza">
                    <input type="radio" name="<?= Theme::KEY_PALETTE ?>"
                           value="<?= htmlspecialchars($chiave) ?>"
                           <?= $palette === $chiave ? 'checked' : '' ?>>
                    <?php /* I quadratini sono decorazione: il nome accanto dice gia'
                             di quale tavolozza si tratta, e un lettore di schermo
                             leggerebbe tre volte la stessa cosa. */ ?>
                    <span class="tavolozza-campioni" aria-hidden="true">
                        <span style="background: <?= htmlspecialchars($t['primary']) ?>"></span>
                        <span style="background: <?= htmlspecialchars($t['soft']) ?>"></span>
                        <span style="background: <?= htmlspecialchars($t['auth_bg']) ?>"></span>
                    </span>
                    <span class="tavolozza-nome"><?= htmlspecialchars($t['nome']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <label for="<?= Theme::KEY_PRIMARY ?>">Colore principale (facoltativo)</label>
        <input type="text" id="<?= Theme::KEY_PRIMARY ?>" name="<?= Theme::KEY_PRIMARY ?>"
               value="<?= htmlspecialchars($primario) ?>"
               placeholder="<?= htmlspecialchars($primarioInVigore) ?>"
               inputmode="text" spellcheck="false"
               aria-describedby="primario_aiuto">
        <p class="form-hint" id="primario_aiuto">
            Scritto come <code>#rrggbb</code>, per avvicinare il colore a quello del vostro
            marchio. Lascia vuoto per usare quello della tavolozza, che è
            <code><?= htmlspecialchars($primarioInVigore) ?></code>.
            Un colore troppo chiaro viene <strong>rifiutato al salvataggio</strong>, dicendo
            di quanto manca: sotto 4,5:1 di contrasto la scritta bianca sui pulsanti non si
            leggerebbe più.
        </p>
    </section>

    <section class="card">
        <h2>Struttura delle pagine pubbliche</h2>

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
