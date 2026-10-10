<?php

declare(strict_types=1);

use App\Core\AuthLayout;
use App\Core\CampoContato;
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
 * @var array<string, array<string, string>> $elementi
 * @var array<string, array<string, mixed>> $proprieta
 * @var array<string, array<string, string>> $ritocchi
 * @var string[] $catalogoFont
 * @var string $fontPubbliche
 * @var string $fontInterno
 * @var array|null $lastUpdate
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Aspetto</h1>
    <p class="page-subtitle">
        Le impostazioni sono divise per <strong>dove fanno effetto</strong>: prima quelle
        che valgono su tutte le pagine, poi quelle che riguardano solo le cinque pagine
        pubbliche — accesso, registrazione, recupero password, nuova password e cambio
        password obbligato.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/settings/aspetto" method="post" class="form">
    <?= Csrf::field() ?>

    <div class="gruppo-impostazioni">
        <h2 class="gruppo-titolo">Tutta la piattaforma</h2>
        <p class="gruppo-nota">Valgono ovunque: pagine pubbliche, corsi, report, pannello.</p>

    <section class="card">
        <h3>Colori</h3>
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
        <h3>Caratteri</h3>
        <p class="form-hint" style="margin-top: 0;">
            Dal catalogo di Google Fonts, <strong><?= count($catalogoFont) ?> famiglie</strong>.
            I caratteri <strong>non si caricano dai server di Google</strong>: quando ne
            scegli uno, è Pistacchio a scaricarlo una volta sola e poi a servirlo lui, così
            chi apre la pagina di accesso non contatta nessuno. Lascia vuoto per il carattere
            di partenza.
        </p>

        <?php /* Una tendina da 1941 voci sarebbe inservibile; un `datalist`
                 lascia scrivere le prime lettere e propone le corrispondenze,
                 e senza JavaScript resta un campo di testo che funziona lo
                 stesso. */ ?>
        <datalist id="catalogo-caratteri">
            <?php foreach ($catalogoFont as $famiglia): ?>
                <option value="<?= htmlspecialchars($famiglia) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <label for="font_interno">Carattere dell’applicazione</label>
        <input type="text" id="font_interno" name="font_interno" list="catalogo-caratteri"
               value="<?= htmlspecialchars($fontInterno) ?>"
               placeholder="carattere di sistema" spellcheck="false"
               aria-describedby="font_interno_aiuto">
        <p class="form-hint" id="font_interno_aiuto">
            Corsi, lezioni, report, pannello: le pagine dove si legge per ore. Un carattere
            neutro è spesso la scelta giusta qui.
        </p>

        <label for="font_pubbliche">Carattere delle pagine pubbliche</label>
        <input type="text" id="font_pubbliche" name="font_pubbliche" list="catalogo-caratteri"
               value="<?= htmlspecialchars($fontPubbliche) ?>"
               placeholder="Albert Sans" spellcheck="false"
               aria-describedby="font_pubbliche_aiuto">
        <p class="form-hint" id="font_pubbliche_aiuto">
            Accesso, registrazione, recupero password. È la prima cosa che si vede, e si
            vede per pochi secondi: qui un carattere caratterizzato ha senso.
        </p>

        <p class="form-hint">
            <strong>Se il salvataggio fallisce dicendo che non riesce a raggiungere
            Google</strong>, il server non può uscire su internet — capita su molti hosting
            condivisi. In quel caso il carattere va messo a mano in
            <code>storage/fonts/</code>: il nome del file è quello della famiglia in
            minuscolo con i trattini, per esempio <code>playfair-display.woff2</code>.
        </p>
    </section>

    <section class="card">
        <h3>Forma e misura</h3>
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

    </div>

    <div class="gruppo-impostazioni">
        <h2 class="gruppo-titolo">Solo le pagine pubbliche</h2>
        <p class="gruppo-nota">
            Accesso, registrazione, recupero password, nuova password e cambio password
            obbligato. La scelta vale per tutte e cinque insieme: cambiare aspetto nel giro
            di tre clic si leggerebbe come un difetto.
        </p>

    <section class="card">
        <h3>Struttura</h3>

        <?php foreach ($choices as $valore => $etichetta): ?>
            <label class="checkbox-label">
                <input type="radio" name="AUTH_LAYOUT" value="<?= htmlspecialchars($valore) ?>"
                       <?= $layout === $valore ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($etichetta) ?></span>
            </label>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <h3>Ritocchi ai singoli elementi</h3>
        <p class="form-hint" style="margin-top: 0;">
            Per chi vuole scendere nel dettaglio: ogni elemento delle pagine pubbliche con
            le stesse sette possibilità. Tutto quello che si lascia su «Come adesso» non
            viene toccato, e un elemento mai aperto resta esattamente com'è.
            I colori passano dal controllo del contrasto, come altrove.
        </p>

        <?php foreach ($elementi as $chiave => $el): ?>
            <?php
            $suoi = $ritocchi[$chiave] ?? [];
            // Aperto se c'è già qualcosa dentro: chi torna sulla pagina deve
            // vedere subito dove ha messo le mani, senza aprire sei cassetti.
            $aperto = $suoi !== [];
            ?>
            <details class="ritocco" <?= $aperto ? 'open' : '' ?>>
                <summary>
                    <span class="ritocco-nome"><?= htmlspecialchars($el['nome']) ?></span>
                    <?php if ($aperto): ?>
                        <span class="badge badge-muted">modificato</span>
                    <?php endif; ?>
                    <?php if ($el['solo'] !== null): ?>
                        <span class="badge">solo affiancato</span>
                    <?php endif; ?>
                </summary>

                <p class="form-hint"><?= htmlspecialchars($el['descrizione']) ?></p>

                <div class="ritocco-campi">
                    <?php foreach ($proprieta as $nomeProp => $prop): ?>
                        <?php $id = 'el_' . $chiave . '_' . $nomeProp; ?>
                        <div class="ritocco-campo">
                            <label for="<?= $id ?>"><?= htmlspecialchars($prop['nome']) ?></label>
                            <?php if ($nomeProp === 'colore'): ?>
                                <input type="text" id="<?= $id ?>"
                                       name="elemento[<?= htmlspecialchars($chiave) ?>][colore]"
                                       value="<?= htmlspecialchars($suoi['colore'] ?? '') ?>"
                                       placeholder="come adesso" spellcheck="false">
                            <?php else: ?>
                                <select id="<?= $id ?>"
                                        name="elemento[<?= htmlspecialchars($chiave) ?>][<?= htmlspecialchars($nomeProp) ?>]">
                                    <?php foreach ($prop['valori'] as $valore => $etichetta): ?>
                                        <option value="<?= htmlspecialchars((string) $valore) ?>"
                                                <?= ($suoi[$nomeProp] ?? '') === (string) $valore ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($etichetta) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <h3>Testi della presentazione</h3>
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

        <?= CampoContato::html('AUTH_SPLIT_TITLE', 'Titolo', 'AUTH_SPLIT_TITLE', AuthLayout::MAX_TITOLO,
            (string) $values['AUTH_SPLIT_TITLE'], 2, false, (string) $defaults['AUTH_SPLIT_TITLE']) ?>
        <p class="form-hint">
            Gli a capo che scrivi qui valgono anche nella pagina: servono a decidere tu dove
            spezza il titolo, invece di lasciarlo alla larghezza della finestra.
        </p>

        <?= CampoContato::html('AUTH_SPLIT_TEXT', 'Testo sotto il titolo', 'AUTH_SPLIT_TEXT', AuthLayout::MAX_TESTO,
            (string) $values['AUTH_SPLIT_TEXT'], 3, false, (string) $defaults['AUTH_SPLIT_TEXT']) ?>
        <script src="/assets/js/quiz-open-count.js"></script>
    </section>

    </div>

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
