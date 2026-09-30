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

        const r = el.getBoundingClientRect();

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
];

async function entra(page) {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', ADMIN);
    await page.fill('input[name="password"]', PASS);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('load');
}

async function esamina(page, url, nome, minimoBersaglio) {
    const risposta = await page.goto(BASE + url);

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
}

// ---------------------------------------------------------------

(async () => {
    const browser = await chromium.launch();

    try {
        for (const [larghezza, altezza, etichetta] of [[1440, 900, 'desktop'], [390, 844, 'telefono']]) {
            console.log('\n=== ' + etichetta + ' (' + larghezza + '×' + altezza + ') ===');

            const ctx = await browser.newContext({ viewport: { width: larghezza, height: altezza } });
            const page = await ctx.newPage();

            console.log('\n--- pagine pubbliche');

            for (const [url, nome] of PAGINE_PUBBLICHE) {
                await esamina(page, url, nome, BERSAGLIO_MINIMO);
            }

            console.log('\n--- pagine interne');
            await entra(page);

            for (const [url, nome] of PAGINE_INTERNE) {
                await esamina(page, url, nome, BERSAGLIO_MINIMO);
            }

            await ctx.close();
        }
    } finally {
        await browser.close();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
