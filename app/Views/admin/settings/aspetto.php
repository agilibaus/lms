<?php

declare(strict_types=1);

use App\Core\AuthLayout;
use App\Core\CampoContato;
use App\Core\Csrf;
use App\Core\FontLibrary;
use App\Core\Settings;
use App\Core\Theme;
use App\Core\Icona;

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
 * @var list<string> $fontScaricati
 * @var list<string> $fontNonRiconosciuti
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

<?php /* Al telefono (30/10, Alessandro, A) ogni sezione si apre e si chiude,
         e la pagina parte chiusa (aspetto.js); «Salva» resta in fondo allo
         schermo. Al computer tutto aperto, come prima, e i titoli non
         chiudono niente. */ ?>
<form action="/admin/settings/aspetto" method="post" class="form aspetto-modulo">
    <?= Csrf::field() ?>

    <div class="gruppo-impostazioni">
        <h2 class="gruppo-titolo">Tutta la piattaforma</h2>
        <p class="gruppo-nota">Valgono ovunque: pagine pubbliche, corsi, report, pannello.</p>

    <details class="card aspetto-sezione" open>
        <summary class="aspetto-sommario"><h3>Colori</h3></summary>
        <p class="form-hint" style="margin-top: 0;">
            Valgono su <strong>tutte</strong> le pagine, non solo su quelle pubbliche.
            Le otto tavolozze sono state verificate sui contrasti prima di essere offerte:
            qualunque si scelga, i testi restano leggibili.
        </p>

        <?php
        // Un colore scritto a mano copre la tavolozza (31/10, Alessandro): la
        // tavolozza scelta si salva, ma quasi non si vede. Lo si dice qui, e
        // si dice se il colore viene dal file `.env`, dove il campo non serve.
        foreach ([
            [Theme::KEY_PRIMARY, 'Colore principale', 'il colore di pulsanti, collegamenti e voci evidenziate'],
            [Theme::KEY_TEXT_COLOR, 'Colore del testo', 'il colore dei testi'],
        ] as [$chiave, $nomeCampo, $che]):
            $valore = Theme::coloreValido((string) Settings::get($chiave, ''));
            if ($valore === null) {
                continue;
            } ?>
            <p class="alert alert-warning" role="note">
                <?php if (Settings::source($chiave) === 'file'): ?>
                    La riga <code><?= $chiave ?></code> del file <code>.env</code>
                    (<code><?= htmlspecialchars($valore) ?></code>), decide <?= $che ?>, qualunque
                    tavolozza tu scelga. Per usare il colore della tavolozza, togli quella riga.
                <?php else: ?>
                    Il campo «<?= $nomeCampo ?>» più sotto (<code><?= htmlspecialchars($valore) ?></code>),
                    decide <?= $che ?>, qualunque tavolozza tu scelga. Per usare il colore della
                    tavolozza, <a href="#<?= $chiave ?>">svuota il campo</a> e salva.
                <?php endif; ?>
            </p>
        <?php endforeach; ?>
        <fieldset class="tavolozze">
            <legend class="sr-only">Tavolozza</legend>
            <?php foreach ($tavolozze as $chiave => $t): ?>
                <label class="tavolozza">
                    <?php /* Riquadro stretto (21/11): in alto la scelta e i
                             campioni, sotto il nome, che ha cosi' tutta la
                             larghezza. I quadratini sono decorazione: il nome
                             dice gia' di quale tavolozza si tratta, e un
                             lettore di schermo leggerebbe tre volte la stessa
                             cosa. */ ?>
                    <span class="tavolozza-riga">
                        <input type="radio" name="<?= Theme::KEY_PALETTE ?>"
                               value="<?= htmlspecialchars($chiave) ?>"
                               <?= $palette === $chiave ? 'checked' : '' ?>>
                        <span class="tavolozza-campioni" aria-hidden="true">
                            <span style="background: <?= htmlspecialchars($t['primary']) ?>"></span>
                            <span style="background: <?= htmlspecialchars($t['soft']) ?>"></span>
                            <span style="background: <?= htmlspecialchars($t['auth_bg']) ?>"></span>
                        </span>
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
            <strong>Sostituisce il colore principale di qualunque tavolozza</strong>, e da lui
            vengono anche le sue sfumature. Si scrive come <code>#rrggbb</code>, per esempio
            per avvicinarlo al colore di un marchio. Con il campo vuoto vale quello della
            tavolozza, che è <code><?= htmlspecialchars($primarioInVigore) ?></code>.
            Un colore troppo chiaro viene <strong>rifiutato al salvataggio</strong>, con
            l’indicazione di quanto manca: sotto 4,5:1 di contrasto la scritta bianca sui
            pulsanti non si leggerebbe più.
        </p>

        <label for="<?= Theme::KEY_TEXT_COLOR ?>">Colore del testo (facoltativo)</label>
        <input type="text" id="<?= Theme::KEY_TEXT_COLOR ?>" name="<?= Theme::KEY_TEXT_COLOR ?>"
               value="<?= htmlspecialchars($coloreTesto) ?>"
               placeholder="<?= htmlspecialchars($coloreTestoInVigore) ?>"
               inputmode="text" spellcheck="false"
               aria-describedby="testo_aiuto">
        <p class="form-hint" id="testo_aiuto">
            <strong>Sostituisce il colore del testo di qualunque tavolozza</strong>: per
            esempio un grigio molto scuro al posto del quasi-nero predefinito, che è
            <code><?= htmlspecialchars($coloreTestoInVigore) ?></code>.
            Il grigio dei testi secondari <strong>non si imposta</strong>: viene ricavato da
            questo, schiarito fin dove resta leggibile. Due campi che devono stare in
            rapporto fra loro sono due modi di sbagliare invece di uno.
            Il controllo è fatto su tutti i fondi su cui il testo finisce, <em>comprese le
            tinte tenui di tutte e otto le tavolozze</em>: così il colore resta valido
            anche cambiando tavolozza.
        </p>
    </details>

    <details class="card aspetto-sezione" open>
        <summary class="aspetto-sommario"><h3>Caratteri</h3></summary>
        <?php /* Il riquadro rifatto il 21/11 (Alessandro, variante A del
                 mockup): i due campi affiancati, ciascuno con la sua
                 anteprima; sotto, i caratteri gia' sul server. */ ?>
        <p class="form-hint caratteri-intro">
            Dal catalogo di Google Fonts. Quando si sceglie un carattere, è Pistacchio a scaricarlo una
            volta sola e poi a servirlo. Con il campo vuoto l'applicazione usa il carattere di sistema e le
            pagine pubbliche Albert Sans.
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

        <?php
        // La pila CSS di un carattere, come `Theme::bloccoFont()` scrive il
        // nome nel `@font-face`: solo lettere, cifre e spazi.
        $pila = static function (string $famiglia): string {
            return '"' . preg_replace('/[^A-Za-z0-9 ]/', '', $famiglia) . '", ' . FontLibrary::generico($famiglia);
        };
        $campiCarattere = [
            'font_interno' => [
                'etichetta' => 'Carattere dell’applicazione',
                'valore' => $fontInterno,
                'predefinito' => 'carattere di sistema',
                'pilaPredefinita' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
                'comando' => 'Torna al carattere di sistema',
                'aiuto' => 'Corsi, lezioni, report.',
            ],
            'font_pubbliche' => [
                'etichetta' => 'Carattere delle pagine pubbliche',
                'valore' => $fontPubbliche,
                'predefinito' => 'Albert Sans',
                'pilaPredefinita' => '"Albert Sans", sans-serif',
                'comando' => 'Torna ad Albert Sans',
                'aiuto' => 'Accesso, registrazione, password.',
            ],
        ];
        ?>
        <div class="caratteri-campi" data-caratteri-scaricati="<?= htmlspecialchars((string) json_encode($fontScaricati, JSON_UNESCAPED_UNICODE)) ?>">
            <?php foreach ($campiCarattere as $id => $c): ?>
                <?php $scelto = $c['valore'] !== '' && in_array($c['valore'], $fontScaricati, true); ?>
                <div class="caratteri-campo">
                    <label for="<?= $id ?>"><?= $c['etichetta'] ?></label>
                    <input type="text" id="<?= $id ?>" name="<?= $id ?>" list="catalogo-caratteri"
                           value="<?= htmlspecialchars($c['valore']) ?>"
                           placeholder="<?= htmlspecialchars($c['predefinito']) ?>" spellcheck="false"
                           aria-describedby="<?= $id ?>_aiuto" data-carattere>
                    <button type="button" class="link-btn comando-con-icona carattere-partenza" data-carattere-partenza="<?= $id ?>" hidden><?= Icona::svg('x-circle') ?><?= $c['comando'] ?></button>
                    <p class="form-hint" id="<?= $id ?>_aiuto"><?= $c['aiuto'] ?></p>
                    <?php /* L'anteprima: il carattere salvato, e con lo script
                             quello che si sta scegliendo (`caratteri-scelta.js`). */ ?>
                    <div class="carattere-anteprima" data-anteprima="<?= $id ?>"
                         data-predefinito="<?= htmlspecialchars($c['predefinito']) ?>"
                         data-pila-predefinita="<?= htmlspecialchars($c['pilaPredefinita']) ?>">
                        <small>Anteprima · <span data-anteprima-nome><?= htmlspecialchars($c['valore'] !== '' ? $c['valore'] : $c['predefinito']) ?></span></small>
                        <span class="carattere-anteprima-testo" data-anteprima-testo
                              style="font-family: <?= htmlspecialchars($scelto ? $pila($c['valore']) : $c['pilaPredefinita']) ?>">Il corso comincia lunedì alle 18:30</span>
                        <span class="carattere-anteprima-avviso" data-anteprima-avviso hidden></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php /* Che cosa c'e' davvero sul server (20/11): ogni carattere
                 scritto nel suo carattere; un file che non corrisponde a
                 nessuna famiglia si segnala, perche' Pistacchio lo ignora. */ ?>
        <div class="caratteri-server">
            <p class="caratteri-server-titolo">Sul server, pronti all’uso</p>
            <?php if ($fontScaricati === [] && $fontNonRiconosciuti === []): ?>
                <p class="form-hint">Ancora nessuno: il primo arriva quando scegli un carattere e salvi.</p>
            <?php else: ?>
                <p class="form-hint">Si scelgono nei due campi qui sopra e nel menu Formato › Caratteri dell’editor.</p>
                <ul class="caratteri-elenco">
                    <?php foreach ($fontScaricati as $f): ?>
                        <li class="caratteri-voce" style="font-family: <?= htmlspecialchars($pila($f)) ?>"><?= htmlspecialchars($f) ?></li>
                    <?php endforeach; ?>
                    <?php foreach ($fontNonRiconosciuti as $n): ?>
                        <li class="caratteri-voce caratteri-voce-sconosciuta"><code><?= htmlspecialchars($n) ?></code> <em>non riconosciuto</em></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="form-hint">
                Su molti hosting condivisi, il server non può uscire su internet per raggiungere Google. In quel
                caso il carattere va messo a mano nella directory <code>storage/fonts/</code>, in formato
                <code>.woff2</code> e con il nome della famiglia in minuscolo, con i trattini al posto degli spazi:
                Playfair Display diventa <code>playfair-display.woff2</code>.
            </p>
        </div>
    </details>

    <details class="card aspetto-sezione" open>
        <summary class="aspetto-sommario"><h3>Forma e misura</h3></summary>
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
    </details>

    </div>

    <div class="gruppo-impostazioni">
        <h2 class="gruppo-titolo">Solo le pagine pubbliche</h2>
        <p class="gruppo-nota">
            Accesso, registrazione, recupero password, nuova password e cambio password
            obbligato. La scelta vale per tutte e cinque insieme: cambiare aspetto nel giro
            di tre clic si leggerebbe come un difetto.
        </p>

    <details class="card aspetto-sezione" open>
        <summary class="aspetto-sommario"><h3>Struttura</h3></summary>

        <?php foreach ($choices as $valore => $etichetta): ?>
            <label class="checkbox-label">
                <input type="radio" name="AUTH_LAYOUT" value="<?= htmlspecialchars($valore) ?>"
                       <?= $layout === $valore ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($etichetta) ?></span>
            </label>
        <?php endforeach; ?>
    </details>

    <details class="card aspetto-sezione" open>
        <summary class="aspetto-sommario"><h3>Ritocchi ai singoli elementi</h3></summary>
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
    </details>

    <details class="card aspetto-sezione" open>
        <summary class="aspetto-sommario"><h3>Testi della presentazione</h3></summary>
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
            Gli a capo scritti qui valgono anche nella pagina: decidono dove si spezza il
            titolo, invece di lasciarlo alla larghezza della finestra.
        </p>

        <?= CampoContato::html('AUTH_SPLIT_TEXT', 'Testo sotto il titolo', 'AUTH_SPLIT_TEXT', AuthLayout::MAX_TESTO,
            (string) $values['AUTH_SPLIT_TEXT'], 3, false, (string) $defaults['AUTH_SPLIT_TEXT']) ?>
        <script src="/assets/js/quiz-open-count.js"></script>
    </details>

    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
        <a href="/admin/settings" class="btn btn-secondary">Annulla</a>
        <a href="/login" class="btn btn-secondary btn-con-icona" target="_blank" rel="noopener"><?= Icona::svg('box-arrow-up-right') ?>Vedi la pagina di accesso</a>
    </div>
</form>
<script src="/assets/js/aspetto.js"></script>
<script src="/assets/js/caratteri-scelta.js"></script>

<?php if ($lastUpdate !== null): ?>
    <p class="form-hint">
        Ultima modifica:
        <?= htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $lastUpdate['updated_at'])))
            . ($lastUpdate['full_name'] !== null
                ? ' — ' . htmlspecialchars((string) $lastUpdate['full_name'])
                : '') ?>
    </p>
<?php endif; ?>
