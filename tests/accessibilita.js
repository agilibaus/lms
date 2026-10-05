/**
 * Controlli di accessibilità sulle pagine renderizzate.
 *
 * Esecuzione (serve il server di sviluppo attivo):
 *   php -S 127.0.0.1:8123 -t public router-dev.php &
 *   node tests/accessibilita.js
 *
 * Variabili d'ambiente facoltative:
 *   LMS_URL     indirizzo di base            (predefinito http://127.0.0.1:8123)
 *   LMS_ADMIN   email dell'amministratore    (predefinito admin@test.it)
 *   LMS_PASS    password                     (predefinita Password1!)
 *
 * PERCHÉ NON È UN TEST PHP. Contrasto, fuoco da tastiera e dimensione dei
 * bersagli esistono solo dopo che il browser ha applicato il CSS: leggendo le
 * viste non si vedono. Serve quindi un browser vero, e questo file gira dove
 * girano le altre misure — nel container, prima di consegnare una patch —
 * non sul computer di Elena, che non ha Node e Playwright.
 *
 * COSA CONTROLLA, e perché proprio questo. Ogni regola qui dentro è
 * verificabile a macchina e ha una risposta sola: o il rapporto di contrasto
 * è 4,5 o non lo è. Se una persona capisca cosa deve fare è un'altra
 * domanda, e a quella rispondono solo le persone: questo file è il
 * pavimento, non la prova di usabilità.
 *
 * COSA NON VEDE, per non crederlo più bravo di quanto sia:
 *  - il contrasto è calcolato sul colore di sfondo, non sulle immagini né sui
 *    gradienti: dove lo sfondo è un gradiente (le pagine pubbliche) il
 *    risultato vale per il colore di base, che lì è il caso peggiore;
 *  - «il fuoco si vede» è verificato come "esiste un contorno o un'ombra",
 *    non come "si distingue abbastanza";
 *  - non legge la pagina con un lettore di schermo, e non sa se un testo
 *    alternativo descrive davvero l'immagine.
 */

'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const ADMIN = process.env.LMS_ADMIN || 'admin@test.it';
const PASS = process.env.LMS_PASS || 'Password1!';

/** Contrasto minimo: 4,5 per il testo normale, 3 per quello grande. */
const CONTRASTO_NORMALE = 4.5;
const CONTRASTO_GRANDE = 3.0;

/**
 * Lato minimo di un bersaglio toccabile, in pixel CSS. È il minimo delle
 * WCAG 2.2, non la misura consigliata (44): sopra il minimo si discute, sotto
 * no. Un valore alto qui riempirebbe il rapporto di rilievi opinabili e lo
 * renderebbe inutile.
 */
const BERSAGLIO_MINIMO = 24;

let ok = 0;
let fail = 0;
const problemi = [];

function check(etichetta, condizione, dettaglio) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);

    if (!condizione && dettaglio) {
        for (const riga of dettaglio.slice(0, 8)) {
            console.log('         · ' + riga);
        }

        if (dettaglio.length > 8) {
            console.log('         · … e altri ' + (dettaglio.length - 8));
        }

        problemi.push({ etichetta, dettaglio });
    }
}

// ---------------------------------------------------------------
// Le funzioni che girano dentro la pagina
// ---------------------------------------------------------------

/**
 * Raccoglie tutto quello che serve ai controlli in un giro solo: ogni
 * valutazione separata costa un viaggio fra Node e il browser, e su una
 * dozzina di pagine si sente.
 */
function raccogli(minimoBersaglio) {
    const visibile = (el) => {
        const r = el.getBoundingClientRect();
        const s = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
    };

    /**
     * Firma dell'elemento: tag e classi, senza identificativo ne' testo. E'
     * cio' su cui i rilievi vengono raggruppati — la stessa regola CSS
     * sbagliata si ripete su ogni riga di una tabella, e seicento righe
     * identiche nel rapporto lo rendono illeggibile invece che utile.
     */
    const firma = (el) => {
        const cls = typeof el.className === 'string' && el.className
            ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.')
            : '';
        return el.tagName.toLowerCase() + cls;
    };

    const descrivi = (el) => {
        const testo = (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30);
        return firma(el) + (testo ? ' «' + testo + '»' : '');
    };

    /**
     * Raggruppa per firma: una riga per pattern, con quante volte compare e
     * un esempio. Chi legge deve sapere che regola correggere, non quante
     * volte l'ha gia' applicata.
     */
    const raggruppa = (rilievi) => {
        const gruppi = new Map();

        for (const r of rilievi) {
            const g = gruppi.get(r.firma) || { n: 0, esempio: r.riga };
            g.n++;
            gruppi.set(r.firma, g);
        }

        return [...gruppi.entries()].map(([f, g]) =>
            g.esempio + (g.n > 1 ? '  (' + g.n + ' volte)' : ''));
    };

    // --- colori ---------------------------------------------------
    const canale = (c) => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    const luminanza = ([r, g, b]) => 0.2126 * canale(r) + 0.7152 * canale(g) + 0.0722 * canale(b);
    const leggiColore = (valore) => {
        const m = valore.match(/rgba?\(([^)]+)\)/);
        if (!m) return null;
        const p = m[1].split(',').map((x) => parseFloat(x));
        return { rgb: [p[0], p[1], p[2]], alpha: p.length > 3 ? p[3] : 1 };
    };
    const sovrapponi = (sopra, sotto) =>
        sopra.rgb.map((c, i) => c * sopra.alpha + sotto[i] * (1 - sopra.alpha));

    /** Sfondo effettivo: si risale finché non si trova un colore coprente. */
    const sfondoDi = (el) => {
        let nodo = el;
        let sopra = null;

        while (nodo && nodo.nodeType === 1) {
            const c = leggiColore(getComputedStyle(nodo).backgroundColor);

            if (c && c.alpha > 0) {
                if (c.alpha >= 1) {
                    return sopra ? sovrapponi(sopra, c.rgb) : c.rgb;
                }
                sopra = sopra ? { rgb: sovrapponi(sopra, c.rgb), alpha: 1 } : c;
            }

            nodo = nodo.parentElement;
        }

        // Nessun antenato con uno sfondo coprente: resta il bianco della pagina.
        return sopra ? sovrapponi(sopra, [255, 255, 255]) : [255, 255, 255];
    };

    const rapporto = (a, b) => {
        const la = luminanza(a);
        const lb = luminanza(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    };

    // --- testo con poco contrasto ---------------------------------
    const contrasti = [];

    for (const el of document.querySelectorAll('body *')) {
        if (!visibile(el)) continue;

        // Solo gli elementi che portano testo proprio: altrimenti lo stesso
        // testo verrebbe contato una volta per ogni antenato.
        const proprio = [...el.childNodes]
            .filter((n) => n.nodeType === 3 && n.textContent.trim() !== '')
            .map((n) => n.textContent.trim())
            .join(' ');

        if (proprio === '') continue;

        const s = getComputedStyle(el);
        const colore = leggiColore(s.color);
        if (!colore || colore.alpha === 0) continue;

        const sfondo = sfondoDi(el);
        const rgbTesto = colore.alpha < 1 ? sovrapponi(colore, sfondo) : colore.rgb;
        const valore = rapporto(rgbTesto, sfondo);

        const px = parseFloat(s.fontSize);
        const grassetto = parseInt(s.fontWeight, 10) >= 700;
        const grande = px >= 24 || (grassetto && px >= 18.66);
        const soglia = grande ? 3.0 : 4.5;

        if (valore < soglia) {
            contrasti.push({
                firma: firma(el) + '|' + valore.toFixed(2),
                riga: descrivi(el) + ' — ' + valore.toFixed(2) + ':1 a ' + px.toFixed(1) + 'px'
                    + ' (minimo ' + soglia + ')',
            });
        }
    }

    // --- campi senza etichetta ------------------------------------
    const senzaEtichetta = [];

    for (const el of document.querySelectorAll('input, select, textarea')) {
        const tipo = (el.getAttribute('type') || 'text').toLowerCase();
        if (['hidden', 'submit', 'button', 'reset', 'image'].includes(tipo)) continue;
        if (!visibile(el)) continue;

        const haEtichetta = !!el.closest('label')
            || (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]'))
            || (el.getAttribute('aria-label') || '').trim() !== ''
            || (el.getAttribute('aria-labelledby') || '').trim() !== '';

        if (!haEtichetta) senzaEtichetta.push(descrivi(el));
    }

    // --- immagini senza testo alternativo -------------------------
    const senzaAlt = [];

    for (const img of document.querySelectorAll('img')) {
        // L'attributo deve esserci; vuoto e' lecito e vuol dire "decorativa".
        if (!img.hasAttribute('alt')) senzaAlt.push(descrivi(img) + ' src=' + (img.getAttribute('src') || ''));
    }

    // --- comandi senza nome ---------------------------------------
    const senzaNome = [];

    for (const el of document.querySelectorAll('a[href], button')) {
        if (!visibile(el)) continue;

        const nome = (el.textContent || '').trim()
            || (el.getAttribute('aria-label') || '').trim()
            || (el.querySelector('img[alt]')?.getAttribute('alt') || '').trim()
            || (el.getAttribute('title') || '').trim();

        if (nome === '') senzaNome.push(descrivi(el));
    }

    // --- identificativi ripetuti ----------------------------------
    // Un id doppio spezza il legame fra etichetta e campo: il browser prende
    // il primo, e l'etichetta finisce sul campo sbagliato.
    const visti = new Set();
    const idRipetuti = [];

    for (const el of document.querySelectorAll('[id]')) {
        if (visti.has(el.id)) idRipetuti.push(el.id);
        visti.add(el.id);
    }

    // --- struttura dei titoli -------------------------------------
    const titoli = [...document.querySelectorAll('h1, h2, h3, h4, h5, h6')]
        .filter(visibile)
        .map((h) => parseInt(h.tagName.substring(1), 10));

    const salti = [];

    for (let i = 1; i < titoli.length; i++) {
        if (titoli[i] > titoli[i - 1] + 1) {
            salti.push('da h' + titoli[i - 1] + ' a h' + titoli[i]);
        }
    }

    // --- bersagli troppo piccoli ----------------------------------
    const bersagli = [];

    for (const el of document.querySelectorAll('a[href], button, input[type="checkbox"], input[type="radio"], summary')) {
        if (!visibile(el)) continue;

        // Un collegamento dentro un paragrafo segue la riga di testo: non e'
        // un bersaglio a se' e la regola non lo riguarda.
        if (el.tagName === 'A' && el.closest('p, li, td') && getComputedStyle(el).display === 'inline') continue;

        /*
         * I comandi dentro TinyMCE (`.tox`) non sono nostri: sono l'interfaccia
         * dell'editor, copiata in casa senza modifiche come arriva dal
         * progetto. Ritoccarne il CSS vorrebbe dire mettere le mani in una
         * interfaccia di terzi che al prossimo aggiornamento cambia, per
         * guadagnare qualche pixel su un contatore di parole che non e'
         * nemmeno un comando vero.
         *
         * **E' un'esclusione, non un'assoluzione**: se un giorno l'editor
         * diventasse uno strumento che gli studenti usano davvero — oggi lo
         * aprono solo admin e tutor, al computer — la cosa andrebbe
         * riaperta, probabilmente scegliendo un'altra barra di stato.
         */
        if (el.closest('.tox')) continue;

        // Una casella dentro un'etichetta si preme anche toccando l'etichetta:
        // il bersaglio e' quello, non il quadratino. Misurare il quadratino
        // segnalerebbe un difetto che non c'e'.
        const etichetta = (el.tagName === 'INPUT' && el.closest('label')) || null;
        const bersaglio = etichetta || el;
        const r = bersaglio.getBoundingClientRect();

        if (r.width < minimoBersaglio || r.height < minimoBersaglio) {
            bersagli.push({
                firma: firma(el),
                riga: descrivi(el) + ' — ' + Math.round(r.width) + '×' + Math.round(r.height),
            });
        }
    }

    return {
        lang: document.documentElement.getAttribute('lang') || '',
        titolo: (document.title || '').trim(),
        h1: document.querySelectorAll('h1').length,
        contrasti: raggruppa(contrasti),
        senzaEtichetta,
        senzaAlt,
        senzaNome,
        idRipetuti,
        salti,
        bersagli: raggruppa(bersagli),
    };
}

/**
 * Percorre la pagina con il tabulatore e riporta i comandi che prendono il
 * fuoco senza mostrarlo. «Si vede» qui vuol dire: un contorno, o un'ombra, o
 * un bordo diverso da quello che aveva prima.
 */
/**
 * I pannelli che si aprono non devono finire dentro a un contenitore che
 * scorre.
 *
 * DA DOVE VIENE QUESTO CONTROLLO. Negli elenchi dei report la tendina
 * «Scarica» stava dentro a un `div` con `overflow-x: auto`. Un contenitore
 * che scorre **ritaglia** quello che esce dai suoi bordi: aprendo la
 * tendina comparivano dei bordi e una barra di scorrimento verticale
 * attorno alla tabella — l'aria di un riquadro incastrato nella pagina — e
 * sull'ultima riga il menu restava tagliato, 74 px fuori. Non lo vedeva
 * nessun controllo: la pagina chiusa e' perfetta, e il difetto compare
 * solo dopo un clic.
 *
 * Qui si apre ogni `details` e si guarda se il suo pannello esce dal primo
 * antenato che scorre. Nota: `overflow-x: auto` da solo basta a creare il
 * problema, perche' il browser rende `auto` anche l'altro asse.
 */
async function pannelliRitagliati(page) {
    return page.evaluate(() => {
        const guai = [];
        const tendine = [...document.querySelectorAll('details.dropdown, details.ritocco')];

        for (const d of tendine) {
            const eraAperta = d.open;
            d.open = true;

            const pannello = d.querySelector('.dropdown-menu, :scope > *:not(summary)');

            if (pannello !== null) {
                let nodo = d.parentElement;

                while (nodo !== null && nodo !== document.body) {
                    const st = getComputedStyle(nodo);
                    const scorre = ['auto', 'scroll'].includes(st.overflowX)
                        || ['auto', 'scroll'].includes(st.overflowY);

                    if (scorre) {
                        const p = pannello.getBoundingClientRect();
                        const c = nodo.getBoundingClientRect();

                        if (p.bottom > c.bottom + 1 || p.right > c.right + 1) {
                            guai.push({
                                firma: 'ritaglio|' + (d.className || '') + '|' + (nodo.className || nodo.tagName),
                                riga: 'il pannello di «' + (d.querySelector('summary') || { textContent: '?' })
                                    .textContent.trim() + '» esce di '
                                    + Math.round(Math.max(p.bottom - c.bottom, p.right - c.right))
                                    + ' px da un contenitore che scorre (' + (nodo.className || nodo.tagName) + ')',
                            });
                        }

                        break;
                    }

                    nodo = nodo.parentElement;
                }
            }

            d.open = eraAperta;
        }

        return guai.map((g) => g.riga);
    });
}

async function fuocoInvisibile(page) {
    return page.evaluate(() => {
        const invisibili = [];
        const comandi = [...document.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]')]
            .filter((el) => {
                const r = el.getBoundingClientRect();
                const s = getComputedStyle(el);

                // Dentro un `<details>` chiuso il browser **rifiuta il
                // fuoco**: `focus()` non fa niente e nessuno stato cambia,
                // quindi il comando risulterebbe "senza fuoco visibile".
                // Non e' un difetto — non e' raggiungibile nemmeno con il
                // tabulatore — ma attenzione: un rettangolo di dimensione
                // zero non basta a riconoscerlo, perche' Chromium quei
                // comandi li dispone lo stesso. Va guardato l'antenato.
                const dettaglio = el.closest('details');

                if (dettaglio !== null && !dettaglio.open && el.tagName !== 'SUMMARY') {
                    return false;
                }

                return r.width > 0 && r.height > 0 && s.visibility !== 'hidden'
                    && el.getAttribute('tabindex') !== '-1' && !el.disabled;
            });

        for (const el of comandi) {
            // Il primo campo di un modulo ha spesso `autofocus`: misurato
            // com'e', risulterebbe "senza fuoco visibile" perche' lo stato
            // di partenza e' gia' quello a fuoco. Si toglie il fuoco prima.
            if (document.activeElement instanceof HTMLElement) {
                document.activeElement.blur();
            }

            const prima = getComputedStyle(el);
            const bordoPrima = prima.borderColor + '|' + prima.boxShadow + '|' + prima.backgroundColor;

            el.focus();

            const dopo = getComputedStyle(el);
            const contorno = dopo.outlineStyle !== 'none' && parseFloat(dopo.outlineWidth) > 0;
            const cambiato = (dopo.borderColor + '|' + dopo.boxShadow + '|' + dopo.backgroundColor) !== bordoPrima;

            if (!contorno && !cambiato) {
                const id = el.id ? '#' + el.id : '';
                const testo = (el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 30);
                invisibili.push(el.tagName.toLowerCase() + id + (testo ? ' «' + testo + '»' : ''));
            }

            el.blur();
        }

        return invisibili;
    });
}

// ---------------------------------------------------------------
// Le pagine da guardare
// ---------------------------------------------------------------

const PAGINE_PUBBLICHE = [
    ['/login', 'Accesso'],
    ['/register', 'Registrazione'],
    ['/password/dimenticata', 'Password dimenticata'],
    ['/register/verifica-inviata', 'Verifica inviata'],
    ['/register/rinvia', 'Rinvia il link di conferma'],
    /*
     * La pagina della nuova password si apre da un collegamento ricevuto per
     * email, quindi spesso dal telefono. Il token qui sotto e' un dato di
     * prova: in tabella sta solo il suo hash, e dove quel dato non c'e' la
     * pagina risponde comunque — mostra la variante «collegamento non piu'
     * valido», che vale la pena controllare lo stesso.
     */
    ['/password/reimposta/token-di-prova-per-i-controlli', 'Nuova password'],
];

const PAGINE_INTERNE = [
    ['/', 'Corsi'],
    ['/profilo', 'Profilo'],
    ['/profilo/password', 'Cambia password'],
    // L'agenda in tutte e due le viste: la griglia del mese e' la cosa
    // piu' difficile da far stare in 320 px di tutta la piattaforma.
    ['/agenda', 'Agenda'],
    ['/agenda?vista=mese', 'Agenda del mese'],
    ['/reports', 'Report'],
    // Gli elenchi dei report, dal 04/10 pagine a sé. Due su cinque: quello
    // degli studenti è il più lungo (è lui che ha la paginazione) e quello
    // della fruizione ha le intestazioni più larghe, cioè i due casi in cui
    // le colonne e lo scorrimento orizzontale possono rompersi.
    ['/reports/elenco/students', 'Elenco studenti'],
    ['/reports/elenco/fruizione', 'Elenco fruizione'],
    ['/live', 'Sessioni live'],
    ['/certificates', 'Certificati'],
    ['/admin/courses', 'Gestione corsi'],
    ['/admin/groups', 'Gruppi'],
    ['/admin/users', 'Utenti'],
    ['/admin/settings', 'Impostazioni'],
    ['/admin/settings/posta', 'Posta elettronica'],
    ['/admin/settings/aspetto', 'Aspetto'],
    ['/admin/permissions', 'Permessi'],
    // Pagina di una lezione, quindi dipende dai dati: se la lezione 1 non
    // c'è, la si salta invece di far fallire tutto (vedi `apri`).
    ['/lessons/1/fruizione', 'Fruizione del video'],
    ['/reports/fruizione/1', 'Fruizione per corso'],
    // Pagine di dettaglio: dipendono dai dati, e se la riga non c'è vengono
    // saltate. Stanno in elenco perché hanno tabelle larghe, ed è lì che le
    // regressioni sullo scorrimento orizzontale si nascondono.
    ['/reports/courses/1', 'Report per corso'],
    ['/reports/students/2', 'Report per studente'],
    ['/reports/groups/1', 'Report per gruppo'],
    ['/lessons/1/edit', 'Modifica lezione'],
    ['/courses/1', 'Corso'],
    // Il percorso del quiz: e' quello che uno studente fa dal telefono piu'
    // di qualunque altra cosa, ed era rimasto fuori dai controlli.
    ['/quizzes/1', 'Quiz da svolgere'],
    ['/quizzes/1/edit', 'Quiz: domande'],
    ['/attempts/1', 'Esito del quiz'],
    ['/modules/1/quiz/create', 'Nuovo quiz'],
    ['/questions/1/edit', 'Modifica domanda'],
    ['/lessons/1', 'Lezione'],

    // Incontri dal vivo.
    ['/live/create', 'Nuovo incontro'],
    ['/live/1', 'Incontro dal vivo'],
    ['/live/1/edit', 'Modifica incontro'],
    ['/reports/live/1', 'Report dell\'incontro'],

    // I moduli di creazione e modifica: sono i piu' lunghi della
    // piattaforma, ed e' dove un'impaginazione storta si sente di piu'.
    ['/admin/courses/create', 'Nuovo corso'],
    ['/admin/courses/1/edit', 'Modifica corso'],
    ['/admin/groups/create', 'Nuovo gruppo'],
    ['/admin/groups/1/edit', 'Modifica gruppo'],
    ['/admin/users/create', 'Nuovo utente'],
    ['/admin/users/2/edit', 'Modifica utente'],
    ['/courses/1/modules/create', 'Nuovo modulo'],
    ['/modules/1/edit', 'Modifica modulo'],
    ['/modules/1/lessons/create', 'Nuova lezione'],

    // Le tre pagine di impostazioni che mancavano.
    ['/admin/settings/inviti', 'Inviti sessioni live'],
    ['/admin/settings/bunny', 'Bunny Stream'],
    ['/admin/settings/meet', 'Google Meet'],

    ['/catalogo', 'Catalogo'],
];

async function entra(page) {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', ADMIN);
    await page.fill('input[name="password"]', PASS);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('load');
}

async function esamina(page, url, nome, minimoBersaglio, daTelefono) {
    const risposta = await page.goto(BASE + url);

    if (risposta && risposta.status() === 404) {
        // Una pagina che dipende dai dati (quella di una lezione, per
        // esempio) può non esserci in questa installazione. Saltarla è
        // giusto; far fallire i controlli di accessibilità perché manca una
        // riga nel database non lo è.
        console.log('  --   ' + nome + ': non presente in questa installazione, saltata');
        return;
    }

    if (risposta && risposta.status() >= 400) {
        check(nome + ': la pagina risponde', false, ['stato ' + risposta.status()]);
        return;
    }

    const r = await page.evaluate(raccogli, minimoBersaglio);

    check(nome + ': lingua dichiarata', r.lang !== '');
    check(nome + ': titolo della scheda', r.titolo !== '');
    check(nome + ': un solo titolo principale', r.h1 === 1, ['trovati ' + r.h1]);
    check(nome + ': contrasto del testo', r.contrasti.length === 0, r.contrasti);
    check(nome + ': campi con etichetta', r.senzaEtichetta.length === 0, r.senzaEtichetta);
    check(nome + ': immagini con testo alternativo', r.senzaAlt.length === 0, r.senzaAlt);
    check(nome + ': comandi con un nome', r.senzaNome.length === 0, r.senzaNome);
    check(nome + ': identificativi non ripetuti', r.idRipetuti.length === 0, r.idRipetuti);
    check(nome + ': titoli senza salti di livello', r.salti.length === 0, r.salti);
    check(nome + ': bersagli almeno ' + minimoBersaglio + 'px', r.bersagli.length === 0, r.bersagli);

    const fuoco = await fuocoInvisibile(page);
    check(nome + ': fuoco visibile su ogni comando', fuoco.length === 0, fuoco);

    const ritagliati = await pannelliRitagliati(page);
    check(nome + ': i pannelli che si aprono non vengono ritagliati', ritagliati.length === 0, ritagliati);

    /*
     * Solo alla larghezza del telefono, e non per pignoleria: Pistacchio si
     * usa molto da li', e una pagina che scorre in orizzontale su un
     * telefono e' una pagina in cui le colonne non stanno dove ci si
     * aspetta e meta' del contenuto e' fuori vista. Il controllo dice anche
     * QUALE elemento sfora, perche' «la pagina e' larga» da sola non si sa
     * da dove prenderla.
     *
     * Non vale a 1440 px: li' lo scorrimento orizzontale non c'e' mai, e il
     * controllo direbbe sempre di si' senza guardare niente.
     */
    if (daTelefono) {
        await controllaSforamento(page, nome);
    }

    if (EXTRA[nome] !== undefined) {
        await EXTRA[nome](page, nome);
    }
}

/**
 * Controlli che valgono per **una** pagina sola.
 *
 * Le regole qui sopra sono generali: valgono ovunque, e per questo non
 * possono dire niente su come una certa pagina usa il colore. Questo e'
 * il posto per le cose che riguardano una pagina e basta.
 */
const EXTRA = {
    /*
     * Il contatore della risposta aperta: dove sta, e che non parli.
     *
     * Deve stare **sopra** il campo e allineato al suo bordo destro — e'
     * quello che si e' chiesto — e deve essere collegato al campo con
     * `aria-describedby`, cosi' chi usa un lettore di schermo sente il
     * limite entrando nel campo.
     *
     * E **non** deve essere una regione viva: un contatore che si annuncia
     * a ogni tasto coprirebbe con la propria voce quello che la persona sta
     * scrivendo. E' il caso che le linee guida chiamano «passivo», da
     * leggere solo andandoci sopra, e un `aria-live` aggiunto per
     * distrazione non si vede guardando la pagina.
     */
    'Quiz da svolgere': async (page, nome) => {
        const esito = await page.evaluate(() => {
            const campo = document.querySelector('.quiz-open-answer');

            if (campo === null) {
                return null;
            }

            const contatore = document.getElementById(campo.getAttribute('aria-describedby') || '');

            if (contatore === null) {
                return { collegato: false };
            }

            const c = contatore.getBoundingClientRect();
            const t = campo.getBoundingClientRect();

            return {
                collegato: true,
                sopra: Math.round(c.bottom) <= Math.round(t.top),
                aDestra: Math.abs(c.right - t.right) < 2,
                vivo: contatore.getAttribute('aria-live'),
                limite: campo.getAttribute('maxlength'),
            };
        });

        if (esito === null) {
            console.log('  --   ' + nome + ': nessuna risposta aperta in questo quiz');
            return;
        }

        check(nome + ': il contatore è descrizione del campo', esito.collegato === true,
            ['nessun elemento puntato da aria-describedby']);

        if (esito.collegato !== true) {
            return;
        }

        check(nome + ': il contatore sta sopra il campo, a destra',
            esito.sopra === true && esito.aDestra === true,
            ['sopra: ' + esito.sopra + ', allineato a destra: ' + esito.aDestra]);

        check(nome + ': il contatore non è una regione viva',
            esito.vivo === null || esito.vivo === 'off',
            ['aria-live: ' + esito.vivo]);

        check(nome + ': il campo dichiara il limite al browser',
            esito.limite !== null && Number(esito.limite) > 0,
            ['maxlength: ' + esito.limite]);
    },

    /*
     * Dentro al riquadro di un modulo, il quiz e' una voce come le lezioni
     * e deve cominciare dove cominciano loro. Era disallineato in due modi
     * diversi: di 9,6 px per tutti — il riempimento orizzontale che le
     * righe delle lezioni hanno e la riga del quiz non aveva — e di 73,6
     * da staff, dove i titoli sono spinti a destra dalle frecce di
     * riordino che il quiz non ha.
     *
     * Si confrontano le ascisse **renderizzate**, non il CSS scritto: lo
     * scalino viene da una variabile e dal passo della riga, e due regole
     * che "sembrano" uguali possono cadere in due punti diversi. Questa
     * pagina gira con un utente dello staff, cioe' nel caso peggiore.
     */
    'Corso': async (page, nome) => {
        const scarto = await page.evaluate(() => {
            const sezioni = [...document.querySelectorAll('section')]
                .filter((s) => s.querySelector('.module-quiz-row') !== null
                    && s.querySelector('.lesson-list-item a') !== null);

            return sezioni.map((s) => Math.round((
                s.querySelector('.module-quiz-row .quiz-link').getBoundingClientRect().x
                - s.querySelector('.lesson-list-item a').getBoundingClientRect().x
            ) * 10) / 10);
        });

        if (scarto.length === 0) {
            console.log('  --   ' + nome + ': nessun modulo con lezioni e quiz, allineamento non verificabile');
            return;
        }

        check(
            nome + ': il quiz è incolonnato con le lezioni del modulo',
            scarto.every((s) => Math.abs(s) < 1),
            ['scarti misurati, in px: ' + JSON.stringify(scarto)]
        );
    },

    /*
     * Nella griglia del mese i due tipi di evento sono pastiglie colorate.
     * Il colore da solo non e' un'informazione (criterio 1.4.1 delle
     * WCAG): chi non distingue quelle due tinte, o chi stampa in bianco e
     * nero, deve poter capire lo stesso. A dirlo e' la legenda sotto alla
     * griglia, e questo controllo verifica che ci sia e che nomini
     * **tutti** i tipi presenti nel mese — non solo che esista.
     */
    'Agenda del mese': async (page, nome) => {
        const esito = await page.evaluate(() => {
            const tipi = [...new Set([...document.querySelectorAll('.agenda-pillola')]
                .map((a) => [...a.classList].find((c) => c.startsWith('agenda-') && c !== 'agenda-pillola')))]
                .filter((c) => c !== undefined);

            const voci = [...document.querySelectorAll('.agenda-legenda li')];

            return {
                tipi,
                spiegati: tipi.filter((t) => voci.some((li) => li.querySelector('.' + t) !== null)),
                voci: voci.length,
                // Una voce senza testo sarebbe un quadrato colorato e basta,
                // cioe' una legenda che spiega il colore col colore.
                etichette: voci.map((li) => li.textContent.trim()).filter((t) => t !== ''),
            };
        });

        if (esito.tipi.length === 0) {
            console.log('  --   ' + nome + ': nessun evento in questo mese, legenda non verificabile');
            return;
        }

        check(
            nome + ': la legenda spiega ogni colore presente',
            esito.spiegati.length === esito.tipi.length,
            ['tipi nel mese: ' + JSON.stringify(esito.tipi), 'spiegati: ' + JSON.stringify(esito.spiegati)]
        );

        check(
            nome + ': ogni voce della legenda ha un testo, non solo un colore',
            esito.voci > 0 && esito.etichette.length === esito.voci,
            ['voci: ' + esito.voci + ', con un testo: ' + JSON.stringify(esito.etichette)]
        );

        /*
         * Il giorno corrente non si segna con lo stesso colore di un
         * evento. Usava `--color-primary-soft`, cioe' **lo stesso
         * valore** dello sfondo delle pastiglie degli incontri: la cella
         * si leggeva come un incontro largo quanto il giorno, e le
         * pastiglie dentro sparivano nel proprio sfondo.
         *
         * Si confrontano i colori **calcolati**, non il CSS scritto: i
         * due valori arrivano da variabili che cambiano con la
         * tavolozza, e confrontare due nomi di variabile non direbbe
         * niente su come la pagina appare davvero.
         */
        const tinte = await page.evaluate(() => {
            const oggi = document.querySelector('.agenda-griglia td.oggi');

            if (oggi === null) {
                return null;
            }

            const sfondo = (el) => getComputedStyle(el).backgroundColor;

            return {
                oggi: sfondo(oggi),
                pastiglie: [...new Set([...document.querySelectorAll('.agenda-pillola')].map(sfondo))],
            };
        });

        if (tinte === null) {
            console.log('  --   ' + nome + ': oggi non cade in questo mese, tinte non verificabili');
            return;
        }

        check(
            nome + ': il giorno di oggi non ha il colore di un evento',
            !tinte.pastiglie.includes(tinte.oggi),
            ['oggi: ' + tinte.oggi, 'pastiglie: ' + JSON.stringify(tinte.pastiglie)]
        );
    },
};

/**
 * Nessuno scorrimento orizzontale, e quando c'e' il nome dell'elemento che
 * lo causa: «la pagina e' larga» da sola non si sa da dove prenderla.
 */
async function controllaSforamento(page, nome) {
    const largo = await page.evaluate(() => {
        const d = document.documentElement;
        const scroll = d.scrollWidth - d.clientWidth;

        if (scroll <= 0) {
            return { scroll: 0, colpevoli: [] };
        }

        const colpevoli = [];

        document.querySelectorAll('body *').forEach((el) => {
            const b = el.getBoundingClientRect();

            if (b.width > 0 && b.right > d.clientWidth + 1) {
                const classe = typeof el.className === 'string' && el.className.trim() !== ''
                    ? '.' + el.className.trim().split(/\s+/)[0]
                    : '';
                colpevoli.push(el.tagName.toLowerCase() + classe + ' arriva a ' + Math.round(b.right) + 'px');
            }
        });

        return { scroll, colpevoli: [...new Set(colpevoli)].slice(0, 5) };
    });

    check(
        nome + ': nessuno scorrimento orizzontale',
        largo.scroll === 0,
        largo.scroll === 0 ? [] : ['sfora di ' + largo.scroll + 'px'].concat(largo.colpevoli)
    );
}

/**
 * Giro veloce sulle due larghezze estreme: **solo** lo sforamento, non tutti
 * i controlli.
 *
 * I 320 px sono il minimo che le WCAG chiedono di reggere (1.4.10), e
 * 844×390 e' un telefono girato di lato — la misura in cui la barra laterale
 * torna visibile e al contenuto resta pochissimo. Sono le due larghezze in
 * cui si rompono cose diverse da quelle che si rompono a 390: a 320 le
 * tabelle di gruppi e modifica corso, di lato la pagina dei permessi, che
 * spingeva l'intera area principale invece di far scorrere la propria
 * tabella.
 *
 * Qui non si rifanno contrasto, bersagli e fuoco: a quelle larghezze
 * darebbero le stesse risposte di 390 px, e triplicare il tempo di ogni
 * patch per sentirsele ripetere non conviene.
 */
async function giroLarghezzeEstreme(browser) {
    for (const [larghezza, altezza, etichetta] of [[320, 568, 'minimo WCAG'], [844, 390, 'telefono di lato']]) {
        console.log('\n=== ' + etichetta + ' (' + larghezza + '×' + altezza + ') — solo scorrimento orizzontale ===');

        const ctx = await browser.newContext({ viewport: { width: larghezza, height: altezza } });
        const page = await ctx.newPage();

        for (const [url, nome] of PAGINE_PUBBLICHE) {
            const risposta = await page.goto(BASE + url);

            if (risposta && risposta.status() >= 400) {
                continue;
            }

            await controllaSforamento(page, nome);
        }

        await entra(page);

        for (const [url, nome] of PAGINE_INTERNE) {
            const risposta = await page.goto(BASE + url);

            if (risposta && risposta.status() >= 400) {
                continue;
            }

            await controllaSforamento(page, nome);
        }

        await ctx.close();
    }
}

// ---------------------------------------------------------------

(async () => {
    const browser = await chromium.launch();

    try {
        for (const [larghezza, altezza, etichetta] of [[1440, 900, 'desktop'], [390, 844, 'telefono']]) {
            const daTelefono = larghezza <= 500;
            console.log('\n=== ' + etichetta + ' (' + larghezza + '×' + altezza + ') ===');

            const ctx = await browser.newContext({ viewport: { width: larghezza, height: altezza } });
            const page = await ctx.newPage();

            console.log('\n--- pagine pubbliche');

            for (const [url, nome] of PAGINE_PUBBLICHE) {
                await esamina(page, url, nome, BERSAGLIO_MINIMO, daTelefono);
            }

            console.log('\n--- pagine interne');
            await entra(page);

            for (const [url, nome] of PAGINE_INTERNE) {
                await esamina(page, url, nome, BERSAGLIO_MINIMO, daTelefono);
            }

            await ctx.close();
        }

        await giroLarghezzeEstreme(browser);
    } finally {
        await browser.close();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
