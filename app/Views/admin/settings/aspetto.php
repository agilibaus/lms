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
 * @var string $raggio
 * @var array<string, array<string, string>> $raggi
 * @var string $misuraTesto
 * @var array<string, array<string, string>> $misureTesto
 * @var string $coloreTesto
 * @var string $coloreTestoInVigore
 * @var string $misuraScena
 * @var array<string, array<string, string>> $misureScena
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

        <label for="<?= Theme::KEY_TEXT_COLOR ?>">Colore del testo (facoltativo)</label>
        <input type="text" id="<?= Theme::KEY_TEXT_COLOR ?>" name="<?= Theme::KEY_TEXT_COLOR ?>"
               value="<?= htmlspecialchars($coloreTesto) ?>"
               placeholder="<?= htmlspecialchars($coloreTestoInVigore) ?>"
               inputmode="text" spellcheck="false"
               aria-describedby="testo_aiuto">
        <p class="form-hint" id="testo_aiuto">
            Il nero dei testi, se ne volete uno diverso — per esempio un grigio molto scuro
            invece del quasi-nero di oggi, che è
            <code><?= htmlspecialchars($coloreTestoInVigore) ?></code>.
            Il grigio dei testi secondari <strong>non si imposta</strong>: viene ricavato da
            questo, schiarito fin dove resta leggibile. Due campi che devono stare in
            rapporto fra loro sono due modi di sbagliare invece di uno.
            Il controllo è fatto su tutti i fondi su cui il testo finisce, <em>comprese le
            tinte tenui di tutte e quattro le tavolozze</em>: così il colore resta valido
            anche se un domani cambiate tavolozza.
        </p>
    </section>

    <section class="card">
        <h2>Forma e misura</h2>
        <p class="form-hint" style="margin-top: 0;">
            Anche queste valgono su tutte le pagine.
        </p>

        <label for="<?= Theme::KEY_RADIUS ?>">Arrotondamento degli angoli</label>
        <select id="<?= Theme::KEY_RADIUS ?>" name="<?= Theme::KEY_RADIUS ?>"
                aria-describedby="raggio_aiuto">
            <?php foreach ($raggi as $chiave => $r): ?>
                <option value="<?= htmlspecialchars($chiave) ?>"
                        <?= $raggio === $chiave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['nome']) ?>
                    (<?= htmlspecialchars($r['sm']) ?> / <?= htmlspecialchars($r['md']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint" id="raggio_aiuto">
            Due valori per ogni livello: il primo veste campi e pulsanti, il secondo i
            riquadri. Il rapporto fra i due è quello che fa sembrare la pagina disegnata
            invece che assemblata, ed è il motivo per cui qui non c'è un numero libero.
        </p>

        <label for="<?= Theme::KEY_TEXT_SIZE ?>">Dimensione del testo</label>
        <select id="<?= Theme::KEY_TEXT_SIZE ?>" name="<?= Theme::KEY_TEXT_SIZE ?>"
                aria-describedby="misura_aiuto">
            <?php foreach ($misureTesto as $chiave => $m): ?>
                <option value="<?= htmlspecialchars($chiave) ?>"
                        <?= $misuraTesto === $chiave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint" id="misura_aiuto">
            <strong>Scala tutta l'interfaccia, non solo le lettere</strong>: nel foglio di
            stile quasi ogni misura è relativa a questa, quindi crescono insieme testo,
            riempimenti e spazi, e le proporzioni restano quelle. Non si scende sotto i
            15&nbsp;px. Chi ha bisogno di testo più grande può comunque ingrandire dal proprio
            browser, e continuerà a funzionare qualunque misura scegliate qui.
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

        <label for="<?= Theme::KEY_SCENE_SIZE ?>">Misura del titolo e del testo</label>
        <select id="<?= Theme::KEY_SCENE_SIZE ?>" name="<?= Theme::KEY_SCENE_SIZE ?>"
                aria-describedby="scena_aiuto">
            <?php foreach ($misureScena as $chiave => $m): ?>
                <option value="<?= htmlspecialchars($chiave) ?>"
                        <?= $misuraScena === $chiave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint" id="scena_aiuto">
            Riguarda <strong>solo queste due righe</strong>, non il resto della piattaforma:
            è indipendente dalla «Dimensione del testo» qui sopra, perché qui il titolo è un
            elemento grafico e ingrandirlo è una scelta di presentazione, non di leggibilità.
            Titolo e testo crescono insieme, per non rompere il rapporto fra i due.
            Su schermo stretto si riducono in proporzione alla misura scelta.
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
