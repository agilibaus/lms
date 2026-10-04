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
    ['/reports', 'Report'],
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
}

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
