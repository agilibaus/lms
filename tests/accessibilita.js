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
const { execFileSync } = require('child_process');
const path = require('path');

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
                // Si guardano **tutti** i `details` antenati, non solo il
                // piu' vicino: il `summary` di un `details` chiuso si
                // raggiunge, ma se quel `details` sta dentro un altro chiuso
                // no (07/10: «Leggi il testo» dentro il benvenuto ridotto).
                for (let d = el.closest('details'); d !== null; d = d.parentElement ? d.parentElement.closest('details') : null) {
                    const eIlSuoSummary = el.tagName === 'SUMMARY' && el.parentElement === d;

                    if (!d.open && !eIlSuoSummary) {
                        return false;
                    }
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
    ['/reports/fermi', 'Studenti fermi'],
    ['/reports/fermi?giorni=7', 'Studenti fermi a 7 giorni'],
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
    ['/admin/users/importa', 'Importa utenti'],
    ['/admin/settings', 'Impostazioni'],
    ['/admin/settings/posta', 'Server di posta elettronica'],
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
    ['/reports/courses/1/griglia', 'Griglia del corso'],
    ['/tre-parole?corso=1', 'Tre parole'],
    ['/tre-parole?corso=1&vista=nomi', 'Tre parole con i nomi'],
    ['/reports/courses/1/griglia?modulo=1', 'Griglia del corso, modulo aperto'],
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

    // Le pagine di impostazioni che mancavano. Email ha preso il posto di
    // «Inviti sessioni live» il 12/10.
    ['/admin/settings/email', 'Messaggi email'],
    ['/admin/settings/bunny', 'Bunny Stream'],
    ['/admin/settings/meet', 'Google Meet'],
    ['/admin/settings/informazioni', 'Informazioni su Pistacchio LMS'],

    ['/catalogo', 'Catalogo'],
];

/*
 * LE PAGINE DEI DATI SEMINATI. Le pagine qui sopra con un id dentro (la
 * lezione 1, la domanda 1, il gruppo 1) mostrano quello che quell'id e'
 * nel database su cui si gira: nel database di prova un caso, in quello di
 * Elena un altro. Il 06/10, con il database di Elena, sono usciti sette
 * difetti che con i dati di prova non comparivano mai — una domanda
 * vero/falso, un materiale, un certificato, un gruppo con un corso, cioe'
 * cose che nella riga 1 di prova non c'erano.
 *
 * Qui le stesse pagine si aprono **sugli oggetti della semina**, che li
 * contiene apposta (`semina_permessi.php`, il blocco «i casi che i controlli
 * di accessibilita' non vedevano»). Gli id li dice la semina stessa, come per
 * `permessi.js`: nessun numero da indovinare.
 */
function semina() {
    const radice = path.join(__dirname, '..');
    const uscita = execFileSync('php', [path.join(radice, 'tests', 'semina_permessi.php')], {
        encoding: 'utf8',
        cwd: radice,
    });

    return JSON.parse(uscita);
}

const SEMINA = semina();
const SEMINATI = SEMINA.A;

PAGINE_INTERNE.push(
    ['/lessons/' + SEMINATI.lezione, 'Lezione con materiale (semina)'],
    ['/lessons/' + SEMINATI.lezione + '/fruizione', 'Fruizione senza durata (semina)'],
    ['/questions/' + SEMINATI.domanda_vero_falso + '/edit', 'Modifica domanda vero/falso (semina)'],
    ['/admin/groups/' + SEMINATI.gruppo + '/edit', 'Modifica gruppo con corso (semina)'],
    ['/reports/groups/' + SEMINATI.gruppo, 'Report per gruppo con certificato (semina)'],
    // Il gruppo da otto con il tutor al centro: e' il solo che fa un cerchio
    // vero, con nomi sui due fianchi, in alto e in basso, e uno lungo.
    ['/gruppi/' + SEMINA.gruppo_cerchio, 'Pagina del gruppo (semina)'],
    // Con il giro del cerchio (12/10): l'admin modera, quindi vede anche la
    // risposta nascosta, «Nascondi»/«Rimetti» e «Fai una nuova domanda».
    ['/gruppi/' + SEMINA.gruppo_cerchio + '/domande-passate', 'Domande passate del gruppo (semina)'],
    // La sezione dei benvenuti dei tutor (07/10), con un benvenuto caricato.
    ['/admin/courses/' + SEMINATI.corso + '/edit', 'Modifica corso con benvenuto (semina)'],
    // Gli obiettivi (11/10): lo staff li vede sempre completi.
    ['/courses/' + SEMINATI.corso, 'Corso con obiettivi, da staff (semina)'],
    // Le domande in attesa, per chi risponde (07/10): la semina ne lascia una
    // per mondo, e l'admin le vede tutte con il tutor assegnato.
    ['/domande', 'Domande in attesa (semina)'],
    // E con una pubblicata aperta per correggerla, come quando si arriva dal
    // «Modifica» dell'archivio (08/10): chiusa, il suo modulo non si misura.
    ['/domande?apri=' + SEMINATI.domanda_pubblicata, 'Domande con una pubblicata aperta (semina)'],
    // La Bacheca (17/10): l'admin vede ogni comando, la proposta da valutare
    // e i racconti nascosti; i moduli con il file e «In evidenza».
    ['/bacheca?corso=' + SEMINATI.corso, 'Bacheca (semina)'],
    ['/bacheca/categorie/' + SEMINATI.bacheca_categoria, 'Categoria della bacheca (semina)'],
    ['/bacheca/proposte?corso=' + SEMINATI.corso, 'Proposte da valutare (semina)'],
    ['/bacheca/nuova?corso=' + SEMINATI.corso, 'Nuova risorsa della bacheca (semina)'],
    ['/bacheca/risorse/' + SEMINATI.bacheca_risorsa + '/modifica', 'Modifica risorsa con file (semina)'],
    ['/bacheca/risorse/' + SEMINATI.bacheca_proposta + '/modifica', 'Correggi la proposta (semina)'],
);

async function entra(page) {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', ADMIN);
    await page.fill('input[name="password"]', PASS);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('load');
}

/*
 * COMANDI E COLLEGAMENTI (06/10, deciso con Elena). L'elenco dei comandi e'
 * lo stesso del foglio di stile, sotto `.link-btn`: un comando nuovo va
 * aggiunto anche qui, o i controlli non lo guardano.
 */
const COMANDI = '.link-btn, .data-table td a:not(.btn), .row-actions a:not(.btn), .module-card-actions a, '
    + '.assign-list li > a, .assign-list .assign-info > a, .material-name, .agenda-azioni a:not(.btn), .tutor-benvenuto-riascolta, '
    + '.tutor-benvenuto-contatti a, .qa-comando, .catalogo-leggi-comando, .tag-consentiti-comando, .obiettivo-comando, .moduli-comando, '
    + '.giro-cambia-comando, .giro-passate-comando, .bacheca-comando, .bacheca-filtro:not(.attivo)';
const NELLE_FRASI = ':is(p, .alert, .form-hint, .lesson-content) a:not([class])';

/**
 * Le tre regole lette dalla pagina com'e' adesso. `conMouse` dice se il
 * dispositivo ha il passaggio del mouse: con il mouse i comandi sono neutri
 * a riposo, senza devono essere sottolineati.
 */
/**
 * Il collegamento «indietro» (10/10, Alessandro): al posto della freccia «←»,
 * quasi invisibile, un triangolo pieno disegnato come forma davanti al testo
 * (`.back-link-testo::before`), alto quanto le maiuscole del carattere in uso.
 * Una pagina nuova scritta alla vecchia maniera — `&larr;` senza lo span —
 * avrebbe la freccia e nessun triangolo: qui diventa un rilievo.
 *
 * L'altezza si confronta con un segnaposto alto `1cap` appoggiato sulla
 * linea di base, misurato nello stesso punto; null se la pagina non ha il
 * collegamento.
 */
/**
 * «Annulla» accanto a ogni «Crea», «Salva», «Modifica» (14/10, Alessandro), e
 * poi anche a «Pubblica», «Condividi», «Poni la domanda» e «Invia»:
 * un collegamento che esce (`a.btn`) o un `button[type="reset"]` che rimette
 * i valori salvati, nello stesso modulo. Restano fuori le azioni senza campi
 * da compilare («Crea l'indirizzo del calendario», «Crea l'evento su Google
 * Calendar», «Invia messaggio di prova», «Invia inviti»): non c'e' niente da
 * annullare. Null se la pagina non ha
 * pulsanti cosi'.
 */
async function annullaAccanto(page) {
    return page.evaluate(() => {
        // «Invia il link» (Password dimenticata, Rinvia il link) ha gia'
        // «Torna all'accesso» sotto il modulo (14/10, Alessandro).
        const AZIONI = /^(Crea l(’|')(indirizzo del calendario|evento su Google Calendar)|Invia messaggio di prova|Invia inviti|Invia il link)/;
        const pulsanti = [...document.querySelectorAll('form button[type="submit"]:not([form])')]
            .filter((b) => /^(Crea|Salva|Modifica|Pubblica|Condividi|Poni la domanda|Invia)\b/.test(b.textContent.trim())
                && !AZIONI.test(b.textContent.trim()));

        if (pulsanti.length === 0) {
            return null;
        }

        return pulsanti.filter((b) => {
            const modulo = b.closest('form');
            const annulla = [...modulo.querySelectorAll('a.btn, button[type="reset"]')]
                .filter((a) => a.textContent.trim() === 'Annulla');

            return annulla.length !== 1;
        }).map((b) => b.textContent.trim() + ' (' + (b.closest('form').getAttribute('action') || '') + ')');
    });
}

/**
 * Le larghezze dei campi (13/10, Alessandro): un'area di testo arriva a
 * 780px, o a tutto il form se e' piu' stretto (il telefono, la scheda del
 * catalogo, un riquadro rientrato); un campo di una riga, o una scelta, si
 * ferma a 520. Il form
 * della lezione non conta: la sua misura e' quella dell'editor. Null se la
 * pagina non ha aree di testo ne' campi in un form.
 */
async function larghezzeCampi(page) {
    return page.evaluate(() => {
        const visibile = (e) => e.checkVisibility() && e.getBoundingClientRect().width > 0;
        const aree = [...document.querySelectorAll('textarea')]
            .filter((t) => t.closest('.lesson-form') === null && visibile(t));
        const righe = [...document.querySelectorAll('form.form, form.stacked-form:not(.lesson-form)')]
            .filter((f) => f.closest('.lesson-form') === null)
            .flatMap((f) => [...f.querySelectorAll('input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="file"], [type="submit"], [type="button"]), select')])
            .filter(visibile);

        if (aree.length === 0 && righe.length === 0) {
            return null;
        }

        const male = [];

        for (const t of aree) {
            // Lo spazio e' quello di chi contiene il campo (o il suo
            // contenitore con il contatore): in Aspetto e' un riquadro
            // rientrato dentro il form.
            const f = (t.closest('.quiz-open-wrap') || t).parentElement;
            const st = getComputedStyle(f);
            const spazio = f.clientWidth - parseFloat(st.paddingLeft) - parseFloat(st.paddingRight);
            const w = t.getBoundingClientRect().width;
            const atteso = Math.min(780, spazio);

            if (Math.abs(w - atteso) > 1) {
                male.push((t.id || t.name) + ': larga ' + Math.round(w) + ' px, attesi ' + Math.round(atteso));
            }
        }

        for (const c of righe) {
            const w = c.getBoundingClientRect().width;

            if (w > 520.5) {
                male.push((c.id || c.name) + ': campo di una riga largo ' + Math.round(w) + ' px, oltre 520');
            }
        }

        return male;
    });
}

/**
 * Un campo di testo con un limite ha sempre il contatore (10/10, la regola
 * del promemoria resa un controllo): un'area di testo con `maxlength` deve
 * essere `.quiz-open-answer` e avere il contatore come prima descrizione.
 * Che il contatore sia giusto lo dicono i controlli del contatore; qui si
 * guarda che ci sia, anche dove nessuno l'ha ancora messo. Null se la
 * pagina non ha aree di testo con un limite.
 */
async function campiSenzaContatore(page) {
    return page.evaluate(() => {
        // Anche i campi che contano il testo visibile (11/10): non hanno
        // `maxlength`, che conterebbe i tag, e lo dicono con `data-conta`.
        const campi = [...document.querySelectorAll('textarea[maxlength], textarea[data-conta]')];

        if (campi.length === 0) {
            return null;
        }

        return campi.filter((t) => {
            const primo = (t.getAttribute('aria-describedby') || '').split(/\s+/)[0];
            const contatore = primo === '' ? null : document.getElementById(primo);

            return !t.classList.contains('quiz-open-answer') || contatore === null
                || !contatore.classList.contains('quiz-open-count');
        }).map((t) => (t.id || t.name) + ' (maxlength ' + t.getAttribute('maxlength') + ')');
    });
}

async function collegamentoIndietro(page) {
    return page.evaluate(() => {
        const link = [...document.querySelectorAll('.back-link')];

        if (link.length === 0) {
            return null;
        }

        const problemi = [];

        link.forEach((a) => {
            const nome = '«' + a.textContent.trim().slice(0, 40) + '»';
            const testo = a.querySelector(':scope > .back-link-testo');

            if (/^[\s]*(←|⟵|&larr;)/.test(a.textContent)) {
                problemi.push(nome + ': c\'è ancora la freccia scritta');
            }

            if (testo === null) {
                problemi.push(nome + ': manca .back-link-testo, quindi manca il triangolo');
                return;
            }

            const prima = getComputedStyle(testo, '::before');

            if (prima.content === 'none' || parseFloat(prima.width) <= 0) {
                problemi.push(nome + ': il triangolo non c\'è');
                return;
            }

            const s = document.createElement('span');
            s.style.cssText = 'display:inline-block;width:0;height:1cap;vertical-align:baseline';
            testo.appendChild(s);
            const maiuscole = s.getBoundingClientRect().height;
            s.remove();

            if (Math.abs(parseFloat(prima.height) - maiuscole) > 0.25) {
                problemi.push(nome + ': triangolo alto ' + parseFloat(prima.height).toFixed(2)
                    + 'px, maiuscole ' + maiuscole.toFixed(2) + 'px');
            }
        });

        return problemi;
    });
}

async function comeSiRiconoscono(page) {
    // Il puntatore lontano da tutto: un comando sotto il mouse e'
    // sottolineato per regola, e il controllo lo scambierebbe per un errore.
    await page.mouse.move(0, 0);

    return page.evaluate(([COMANDI, NELLE_FRASI]) => {
        const visibile = (e) => e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
        const sottolineato = (e) => getComputedStyle(e).textDecorationLine.includes('underline');
        const testo = (e) => (e.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40);

        const frasi = [...document.querySelectorAll(NELLE_FRASI)].filter(visibile)
            .filter((a) => !sottolineato(a) || getComputedStyle(a).color === getComputedStyle(a.parentElement).color)
            .map((a) => '«' + testo(a) + '»');

        const comandi = [...document.querySelectorAll(COMANDI)].filter(visibile);

        // I comandi di una stessa fila: chi sta sulla stessa riga deve avere
        // il testo alla stessa altezza. «Elimina» nei comandi del modulo
        // stava 4 px piu' in basso degli altri, da prima del 06/10.
        const sfalsati = [];
        // E lo stesso carattere. Un pulsante non eredita il carattere della
        // pagina: «Elimina» era in Arial accanto a «Modifica» nel carattere di
        // sistema, e su Windows (Segoe UI) stava piu' in alto. Qui il
        // carattere di sistema ha le proporzioni di Arial e lo scarto non si
        // misura: il carattere diverso invece si legge ovunque.
        const caratteri = [];
        for (const fila of document.querySelectorAll('.row-actions, .module-card-actions')) {
            const famiglie = [...fila.querySelectorAll('a, button')].filter(visibile)
                .map((e) => ({ t: testo(e), f: getComputedStyle(e).fontFamily }));
            for (const v of famiglie.slice(1)) {
                if (v.f !== famiglie[0].f && /\S/.test(v.t) && /\S/.test(famiglie[0].t) && !/^[↑↓]$/.test(v.t) && !/^[↑↓]$/.test(famiglie[0].t)) {
                    caratteri.push('«' + v.t + '» in ' + v.f.split(',')[0] + ', «' + famiglie[0].t + '» in ' + famiglie[0].f.split(',')[0]);
                }
            }
        }
        for (const fila of document.querySelectorAll('.row-actions, .module-card-actions')) {
            const voci = [...fila.querySelectorAll('a, button')].filter(visibile).map((e) => {
                // Il testo, non l'icona (19/10): con un'icona davanti il primo
                // rettangolo era quello dell'svg, e in una fila con comandi
                // con e senza icona si confrontava un'icona con una parola.
                const rg = document.createRange();
                const parole = document.createTreeWalker(e, NodeFilter.SHOW_TEXT,
                    { acceptNode: (n) => (n.textContent.trim() !== '' && !n.parentElement.closest('.sr-only') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP) });
                const primo = parole.nextNode();
                if (primo === null) {
                    return null;
                }
                rg.selectNodeContents(primo);
                const r = rg.getClientRects()[0];
                return r ? { t: testo(e), alto: r.top, basso: r.bottom } : null;
            }).filter(Boolean);
            for (let i = 1; i < voci.length; i++) {
                const a = voci[0];
                const b = voci[i];
                const stessaRiga = Math.abs(a.alto - b.alto) < 12;
                if (stessaRiga && Math.abs(a.basso - b.basso) > 1) {
                    sfalsati.push('«' + b.t + '» ' + Math.round(b.basso - a.basso) + ' px rispetto a «' + a.t + '»');
                }
            }
        }

        // Un modulo subito dopo un altro modulo stacca di 1,5 rem (07/10):
        // «Rimuovi copertina» stava a 0 px da «Salva copertina».
        const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
        const attaccati = [...document.querySelectorAll('form.form + form')].filter(visibile).map((f) => {
            const sopra = f.previousElementSibling.getBoundingClientRect().bottom;
            return { t: testo(f), d: Math.round(f.getBoundingClientRect().top - sopra) };
        }).filter((x) => x.d < 1.5 * rem - 1).map((x) => '«' + x.t + '» a ' + x.d + ' px');

        // Nei moduli delle pagine pubbliche ogni etichetta sta alla stessa
        // distanza dal suo campo (07/10: «Nome» e «Cognome» affiancati
        // stavano a 0 px, gli altri a 4,8). Si misurano solo le coppie con il
        // campo sotto l'etichetta.
        const distanze = [];
        for (const f of document.querySelectorAll('form.auth-form')) {
            const misure = [...f.querySelectorAll('label[for]')].map((l) => {
                const c = document.getElementById(l.getAttribute('for'));
                if (!c || !visibile(c) || !visibile(l)) return null;
                const lr = l.getBoundingClientRect();
                const cr = c.getBoundingClientRect();
                return cr.top >= lr.bottom - 1 ? { t: testo(l), d: Math.round((cr.top - lr.bottom) * 10) / 10 } : null;
            }).filter(Boolean);
            if (misure.length > 1) {
                const minimo = Math.min(...misure.map((m) => m.d));
                const massimo = Math.max(...misure.map((m) => m.d));
                if (massimo - minimo > 1) {
                    distanze.push(misure.map((m) => '«' + m.t + '» ' + m.d + ' px').join(', '));
                }
            }
        }

        return {
            distanze,
            attaccati,
            conMouse: !matchMedia('(hover: none)').matches,
            frasi,
            sottolineatiARiposo: comandi.filter(sottolineato).map((e) => '«' + testo(e) + '»'),
            senzaSottolineatura: comandi.filter((e) => !sottolineato(e)).map((e) => '«' + testo(e) + '»'),
            sfalsati,
            caratteri: [...new Set(caratteri)],
        };
    }, [COMANDI, NELLE_FRASI]);
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

    // Ogni contatore di caratteri e' collegato al suo campo (17/10): il
    // riquadro della bacheca in Modifica corso veniva dopo lo script, e il
    // contatore della descrizione restava fermo.
    const slegati = await page.$$eval('.quiz-open-answer[aria-describedby]',
        (ts) => ts.filter((t) => !t.hasAttribute('data-contato')).map((t) => t.id));
    const contatori = await page.$$eval('.quiz-open-answer[aria-describedby]', (ts) => ts.length);
    if (contatori > 0) {
        check(nome + ': ogni contatore di caratteri si aggiorna mentre si scrive', slegati.length === 0, slegati);
    }

    const fuoco = await fuocoInvisibile(page);
    check(nome + ': fuoco visibile su ogni comando', fuoco.length === 0, fuoco);

    const ritagliati = await pannelliRitagliati(page);
    check(nome + ': i pannelli che si aprono non vengono ritagliati', ritagliati.length === 0, ritagliati);

    const segni = await comeSiRiconoscono(page);
    check(nome + ': i collegamenti nelle frasi sono sottolineati e colorati', segni.frasi.length === 0, segni.frasi);
    check(nome + ': i comandi di una fila hanno il testo sulla stessa riga', segni.sfalsati.length === 0, segni.sfalsati);
    check(nome + ': i comandi di una fila usano lo stesso carattere', segni.caratteri.length === 0, segni.caratteri);
    check(nome + ': un modulo che segue un altro modulo ne sta staccato', segni.attaccati.length === 0, segni.attaccati);

    const senzaContatore = await campiSenzaContatore(page);
    if (senzaContatore !== null) {
        check(nome + ': ogni campo di testo con un limite ha il contatore', senzaContatore.length === 0, senzaContatore);
    }

    const senzaAnnulla = await annullaAccanto(page);
    if (senzaAnnulla !== null) {
        check(nome + ': accanto a ogni «Crea», «Salva», «Invia»... c\'è «Annulla»', senzaAnnulla.length === 0, senzaAnnulla);
    }

    const larghezze = await larghezzeCampi(page);
    if (larghezze !== null) {
        check(nome + ': aree di testo fino a 780 px, campi di una riga fino a 520', larghezze.length === 0, larghezze);
    }

    const indietro = await collegamentoIndietro(page);
    if (indietro !== null) {
        check(nome + ': il collegamento «indietro» ha il triangolo alto quanto le maiuscole, e nessuna freccia scritta',
            indietro.length === 0, indietro);
    }
    check(nome + ': nei moduli pubblici ogni etichetta sta alla stessa distanza dal suo campo', segni.distanze.length === 0, segni.distanze);
    if (segni.conMouse) {
        check(nome + ': con il mouse i comandi sono neutri a riposo',
            segni.sottolineatiARiposo.length === 0, segni.sottolineatiARiposo);
    }

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
     * La presentazione nella pagina del gruppo (06/10): una scheda che si
     * apre sopra la pagina. Tutti gli altri controlli guardano la pagina
     * ferma, e una scheda chiusa non la vede nessuno — e' la lezione della
     * 0089. Qui le si apre tutte, una per una, alla larghezza del giro.
     *
     * Che cosa si guarda:
     *   - chiuse, non occupano spazio: e' la trappola di §5, un `display`
     *     scritto sulla scheda batterebbe la regola con cui il browser la
     *     tiene nascosta, e le presentazioni comparirebbero tutte sotto il
     *     cerchio;
     *   - aperta, sta tutta dentro lo schermo e non scorre in orizzontale —
     *     la semina ne ha una da mille caratteri con una parola senza spazi;
     *   - il comando per chiuderla e' un bersaglio da 24 px;
     *   - Esc la chiude e il fuoco torna sulla persona da cui si e' partiti.
     */
    'Pagina del gruppo (semina)': async (page, nome) => {
        const ids = await page.$$eval('.persona-apri', (bs) => bs.map((b) => b.getAttribute('popovertarget')));

        check(nome + ': chi ha una presentazione è un pulsante che la apre', ids.length >= 3,
            ['pulsanti trovati: ' + ids.length + ' (la semina ne prepara tre)']);

        const altezzeChiuse = await page.$$eval('.presentazione', (ss) => ss.map((s) => s.getBoundingClientRect().height));
        check(nome + ': le presentazioni chiuse non occupano spazio',
            altezzeChiuse.length > 0 && altezzeChiuse.every((h) => h === 0),
            ['altezze: ' + altezzeChiuse.join(', ')]);

        // Il bordo di 1 px del colore principale su tutte le foto, tutor
        // compreso (07/10, scelto da Elena su un mockup).
        const bordi = await page.evaluate(() => {
            const sonda = document.createElement('span');
            sonda.style.color = 'var(--color-primary)';
            document.body.appendChild(sonda);
            const verde = getComputedStyle(sonda).color;
            sonda.remove();
            const ombra = (e) => getComputedStyle(e).boxShadow;

            const giusto = (f) => ombra(f).includes(verde) && ombra(f).includes('0px 0px 0px 1px');
            const tutte = [...document.querySelectorAll('.persona .persona-foto')];

            return {
                quante: tutte.length,
                senza: tutte.filter((f) => !giusto(f)).map(ombra),
                tutor: [...document.querySelectorAll('.persona-tutor .persona-foto')].filter(giusto).length,
            };
        });
        check(nome + ': tutte le foto hanno il bordo sottile del colore principale',
            bordi.quante > 0 && bordi.senza.length === 0, bordi.senza.slice(0, 3));
        check(nome + ': anche quella del tutor', bordi.tutor === 1, ['foto del tutor con il bordo: ' + bordi.tutor]);

        // Nel cerchio foto e fumetto sono piu' grandi del 10%, e il cerchio
        // e' largo 33 rem (16/10, Alessandro, variante B del mockup); nella
        // griglia del telefono e del tablet le misure restano quelle di prima.
        const misure = await page.evaluate(() => {
            const lista = document.querySelector('.gruppo-cerchio');
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
            const largo = (s) => {
                const e = document.querySelector(s);
                return e === null ? null : Math.round(e.getBoundingClientRect().width);
            };
            const f = document.querySelector('.persona-fumetto');
            const cerchio = lista !== null && getComputedStyle(lista).display === 'block';

            return {
                cerchio,
                studente: largo('.persona:not(.persona-tutor) .persona-foto'),
                tutor: largo('.persona-tutor .persona-foto'),
                fumetto: f === null ? null : Math.round(parseFloat(getComputedStyle(f).width) / rem * 100) / 100,
                larghezza: cerchio ? Math.round(lista.getBoundingClientRect().width / rem * 100) / 100 : null,
            };
        });
        const attese = misure.cerchio
            ? { cerchio: true, studente: 70, tutor: 114, fumetto: 1.76, larghezza: 33 }
            : { cerchio: false, studente: 64, tutor: 104, fumetto: 1.6, larghezza: null };
        check(nome + (misure.cerchio
            ? ': nel cerchio le foto sono di 70 e 114 px, il fumetto di 1,76 rem e il cerchio di 33 rem'
            : ': nella griglia le foto restano di 64 e 104 px e il fumetto di 1,6 rem'),
            JSON.stringify(misure) === JSON.stringify(attese),
            ['misurato ' + JSON.stringify(misure) + ', atteso ' + JSON.stringify(attese)]);

        const problemi = { apre: [], fuori: [], scorre: [], scorreV: [], chiudi: [], esc: [], fuoco: [], virgolette: [], corsivo: [] };

        // Il fumetto e' verde, cioe' del colore principale (06/10).
        const fumetto = await page.evaluate(() => {
            const f = document.querySelector('.persona-fumetto');
            const sonda = document.createElement('span');
            sonda.style.color = 'var(--color-primary)';
            document.body.appendChild(sonda);
            const verde = getComputedStyle(sonda).color;
            sonda.remove();

            return f === null ? null : { colore: getComputedStyle(f, '::before').backgroundColor, verde };
        });
        check(nome + ': il fumetto ha il colore principale',
            fumetto !== null && fumetto.colore === fumetto.verde,
            [fumetto === null ? 'nessun fumetto' : fumetto.colore + ' invece di ' + fumetto.verde]);

        // Le persone in un riquadro bianco come l'incontro e il giro del
        // cerchio (13/10, Alessandro): stesso fondo, e nessun nome che esce
        // dal riquadro — nel cerchio i nomi sono posizionati e il riquadro
        // non cresce per loro. E il fumetto, bianco anche lui, ha un bordo
        // che sul fondo bianco si vede: almeno 3:1, la soglia degli elementi
        // grafici (WCAG 1.4.11).
        const riquadro = await page.evaluate(() => {
            const r = document.querySelector('.gruppo-persone');
            const giro = document.querySelector('.giro, .gruppo-incontro');

            if (r === null) {
                return null;
            }

            const rgb = (c) => c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);
            const lum = (c) => {
                const [R, G, B] = rgb(c).map((v) => {
                    const x = v / 255;
                    return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
                });
                return 0.2126 * R + 0.7152 * G + 0.0722 * B;
            };
            const rapporto = (a, b) => (Math.max(lum(a), lum(b)) + 0.05) / (Math.min(lum(a), lum(b)) + 0.05);
            const b = r.getBoundingClientRect();
            const fuori = [...r.querySelectorAll('*')]
                .filter((e) => !e.closest('.sr-only') && e.checkVisibility())
                .filter((e) => {
                    const q = e.getBoundingClientRect();
                    return q.width > 0 && (q.top < b.top - 0.5 || q.bottom > b.bottom + 0.5
                        || q.left < b.left - 0.5 || q.right > b.right + 0.5);
                })
                .map((e) => (e.textContent || e.className).trim().slice(0, 40));
            const fondo = getComputedStyle(r).backgroundColor;
            const f = r.querySelector('.persona-fumetto');

            return {
                fondo,
                comeGliAltri: giro === null || getComputedStyle(giro).backgroundColor === fondo,
                bordo: getComputedStyle(r).borderTopStyle,
                fuori,
                fumetto: f === null ? null : rapporto(getComputedStyle(f).borderTopColor, fondo),
            };
        });

        if (riquadro === null) {
            check(nome + ': le persone del gruppo stanno in un riquadro', false, ['manca .gruppo-persone']);
        } else {
            check(nome + ': le persone del gruppo stanno in un riquadro bianco come gli altri',
                riquadro.bordo === 'solid' && riquadro.comeGliAltri,
                [JSON.stringify(riquadro)]);
            check(nome + ': nessun nome esce dal riquadro delle persone', riquadro.fuori.length === 0, riquadro.fuori);
            check(nome + ': il bordo del fumetto si vede sul fondo del riquadro (almeno 3:1)',
                riquadro.fumetto !== null && riquadro.fumetto >= 3,
                [riquadro.fumetto === null ? 'nessun fumetto' : 'contrasto ' + riquadro.fumetto.toFixed(2) + ':1']);
        }

        for (const id of ids) {
            // Con un timeout breve e senza fermare il giro: se qualcosa copre
            // il pulsante — una scheda rimasta aperta, o le schede chiuse
            // che il CSS rende visibili sopra la pagina — e' un rilievo, non
            // un motivo per interrompere tutti gli altri controlli.
            try {
                await page.click('.persona-apri[popovertarget="' + id + '"]', { timeout: 3000 });
            } catch (e) {
                problemi.apre.push(id + ': ' + String(e.message).split('\n')[0]);
                continue;
            }

            const r = await page.evaluate((id) => {
                const s = document.getElementById(id);
                const b = s.getBoundingClientRect();
                const c = s.querySelector('.presentazione-chiudi').getBoundingClientRect();

                return {
                    aperta: s.matches(':popover-open'),
                    dentro: b.left >= 0 && b.top >= 0
                        && b.right <= window.innerWidth + 0.5 && b.bottom <= window.innerHeight + 0.5,
                    misure: [b.left, b.top, b.right, b.bottom].map(Math.round).join(','),
                    scorre: s.scrollWidth - s.clientWidth,
                    // In verticale una scheda lunga scorre, ed e' giusto; una
                    // che non ha raggiunto la sua altezza massima no.
                    scorreV: b.height < parseFloat(getComputedStyle(s).maxHeight) - 1
                        ? s.scrollHeight - s.clientHeight : 0,
                    chiudi: Math.min(c.width, c.height),
                    corsivo: getComputedStyle(s.querySelector('.presentazione-testo')).fontStyle === 'italic',
                    // Le virgolette (06/10): quella che apre accanto alla
                    // prima riga, quella che chiude accanto all'ultima. Sono
                    // forme con la scatola uguale al segno, quindi la loro
                    // posizione si misura dallo stile, per quante righe abbia
                    // il testo. La prima versione, fatta di caratteri, sul
                    // Windows di Elena metteva la chiusura a meta' testo.
                    virgolette: (() => {
                        const p = s.querySelector('.presentazione-testo');
                        const r = p.getBoundingClientRect();
                        const rg = document.createRange();
                        rg.selectNodeContents(p);
                        const righe = [...rg.getClientRects()];
                        const prima = righe[0];
                        const ultima = righe[righe.length - 1];

                        return {
                            apre: Math.round(r.top + parseFloat(getComputedStyle(p, '::before').top) - prima.top),
                            chiude: Math.round(r.bottom - parseFloat(getComputedStyle(p, '::after').bottom) - ultima.bottom),
                        };
                    })(),
                };
            }, id);

            if (!r.aperta || !r.dentro) problemi.fuori.push(id + ' [' + r.misure + ']');
            if (r.scorre > 0) problemi.scorre.push(id + ' di ' + r.scorre + ' px');
            if (r.scorreV > 0) problemi.scorreV.push(id + ' di ' + r.scorreV + ' px');
            if (r.chiudi < 24) problemi.chiudi.push(id + ': ' + r.chiudi + ' px');
            if (!r.corsivo) problemi.corsivo.push(id);
            if (Math.abs(r.virgolette.apre) > 6 || Math.abs(r.virgolette.chiude) > 6) {
                problemi.virgolette.push(id + ': apre a ' + r.virgolette.apre + ' px dalla prima riga, chiude a '
                    + r.virgolette.chiude + ' px dall\'ultima');
            }

            await page.keyboard.press('Escape');

            const dopo = await page.evaluate((id) => ({
                aperta: document.getElementById(id).matches(':popover-open'),
                fuoco: document.activeElement ? document.activeElement.getAttribute('popovertarget') : null,
            }), id);

            if (dopo.aperta) problemi.esc.push(id);
            if (dopo.fuoco !== id) problemi.fuoco.push(id + ' → ' + dopo.fuoco);
        }

        check(nome + ': ogni persona si può toccare per aprire la presentazione', problemi.apre.length === 0, problemi.apre);
        check(nome + ': ogni presentazione aperta sta dentro lo schermo', problemi.fuori.length === 0, problemi.fuori);
        check(nome + ': nessuna presentazione aperta scorre in orizzontale', problemi.scorre.length === 0, problemi.scorre);
        // Le virgolette giganti (06/10): il segno di chiusura portava sotto
        // di se' una riga vuota alta come lui, e una scheda di due righe
        // scorreva di 29 px — sul telefono si sente sotto il dito.
        check(nome + ': una presentazione corta non scorre in verticale', problemi.scorreV.length === 0, problemi.scorreV);
        check(nome + ': il comando per chiudere è almeno 24 px', problemi.chiudi.length === 0, problemi.chiudi);
        check(nome + ': le virgolette stanno accanto alla prima e all\'ultima riga', problemi.virgolette.length === 0, problemi.virgolette);
        // In corsivo (07/10, Elena): un racconto detto a voce.
        check(nome + ': il testo della presentazione è in corsivo', problemi.corsivo.length === 0, problemi.corsivo);
        check(nome + ': Esc chiude la presentazione', problemi.esc.length === 0, problemi.esc);
        check(nome + ': chiusa la presentazione, il fuoco torna sulla persona', problemi.fuoco.length === 0, problemi.fuoco);
    },

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
        // Tutti i campi con il contatore della pagina, non solo il primo
        // (10/10): nella modifica del corso ci sono la descrizione e il
        // benvenuto del tutor, nella pagina «Domande» domanda e risposta di
        // ogni domanda. Si dice quale campo non va.
        const esiti = await page.evaluate(() => [...document.querySelectorAll('.quiz-open-answer')].map((campo) => {
            // Il primo degli id: nel profilo il campo e' descritto anche dal
            // testo di aiuto, e la lista intera non e' un id.
            const contatore = document.getElementById((campo.getAttribute('aria-describedby') || '').split(/\s+/)[0]);
            const chi = campo.id || campo.name;

            if (contatore === null) {
                return { chi, collegato: false };
            }

            // Un campo chiuso in un `details` non ha misure: lo si apre per
            // misurarlo, come farebbe chi lo usa.
            for (let d = campo.closest('details'); d !== null; d = d.parentElement.closest('details')) {
                d.open = true;
            }

            const c = contatore.getBoundingClientRect();
            const t = campo.getBoundingClientRect();

            return {
                chi,
                collegato: true,
                sopra: Math.round(c.bottom) <= Math.round(t.top),
                aDestra: Math.abs(c.right - t.right) < 2,
                vivo: contatore.getAttribute('aria-live'),
                limite: campo.getAttribute('maxlength'),
                dichiarato: contatore.getAttribute('data-max'),
                conta: campo.getAttribute('data-conta'),
            };
        }));

        if (esiti.length === 0) {
            console.log('  --   ' + nome + ': nessun campo con il contatore in questa pagina');
            return;
        }

        const male = (prova) => esiti.filter((e) => !prova(e)).map((e) => e.chi + ': ' + JSON.stringify(e));

        const scollegati = male((e) => e.collegato === true);
        check(nome + ': il contatore è descrizione del campo', scollegati.length === 0,
            scollegati.length === 0 ? [] : ['nessun elemento puntato da aria-describedby'].concat(scollegati));

        if (scollegati.length > 0) {
            return;
        }

        const fuori = male((e) => e.sopra === true && e.aDestra === true);
        check(nome + ': il contatore sta sopra il campo, a destra', fuori.length === 0, fuori);

        const vivi = male((e) => e.vivo === null || e.vivo === 'off');
        check(nome + ': il contatore non è una regione viva', vivi.length === 0, vivi);

        // La domanda aperta del questionario (18/10, Alessandro): la domanda
        // e' l'etichetta del campo e il campo le sta sotto come le opzioni
        // alla loro domanda (prima 34 px, ora 8). Quando il contatore va a
        // capo, sul telefono, si conta anche la sua riga.
        const aperte = await page.evaluate(() => [...document.querySelectorAll('.quiz-open-domanda')].map((d) => {
            const etichetta = d.querySelector('label.quiz-question-text');
            const campo = d.querySelector('textarea');
            const contatore = d.querySelector('.quiz-open-count');
            const e = etichetta.getBoundingClientRect();
            const c = contatore.getBoundingClientRect();
            const sotto = c.top >= e.bottom - 1 ? c.bottom : e.bottom;
            return { per: etichetta.getAttribute('for') === campo.id, distanza: Math.round(campo.getBoundingClientRect().top - sotto) };
        }));
        const chiuse = await page.evaluate(() => [...document.querySelectorAll('fieldset.quiz-question-group')].map((f) =>
            Math.round(f.querySelector('.quiz-option').getBoundingClientRect().top - f.querySelector('legend').getBoundingClientRect().bottom)));
        if (aperte.length > 0) {
            check(nome + ': la domanda aperta è l\'etichetta del campo, che le sta sotto come le opzioni alla loro domanda',
                aperte.every((a) => a.per && a.distanza <= 12 && (chiuse.length === 0 || Math.abs(a.distanza - chiuse[0]) <= 4)),
                [JSON.stringify({ aperte, chiuse })]);
        }

        // Fra il modulo e quello che lo segue (il riquadro «I tuoi
        // tentativi») c'e' spazio: si toccavano (18/10).
        const dopo = await page.evaluate(() => {
            const f = document.querySelector('.quiz-form');
            let n = f ? f.nextElementSibling : null;
            while (n !== null && n.tagName === 'SCRIPT') {
                n = n.nextElementSibling;
            }
            return n === null ? null : Math.round(n.getBoundingClientRect().top - f.getBoundingClientRect().bottom);
        });
        if (dopo !== null) {
            check(nome + ': fra il questionario e il riquadro sotto c\'è spazio', dopo >= 16, [String(dopo)]);
        }

        // Un campo che conta il testo visibile (obiettivo e descrizione con i
        // tag, 11/10) non dichiara il limite al browser: `maxlength`
        // conterebbe anche i tag e fermerebbe la scrittura prima del limite.
        const senzaLimite = male((e) => (e.conta === 'testo'
            ? e.limite === null && Number(e.dichiarato) > 0
            : e.limite !== null && Number(e.limite) > 0 && e.limite === e.dichiarato));
        check(nome + ': il campo dichiara il limite al browser, lo stesso del contatore', senzaLimite.length === 0, senzaLimite);

        // E il contatore di quei campi conta il testo, non i tag, e oltre il
        // limite dice quanti caratteri sono in piu', con le migliaia
        // separate come le scrive il server. Il campo torna com'era.
        const contaTesto = await page.evaluate(() => [...document.querySelectorAll('textarea[data-conta="testo"]')].map((campo) => {
            const contatore = document.getElementById(campo.id + '-resta');
            const massimo = Number(contatore.getAttribute('data-max'));
            const prima = campo.value;
            const leggi = (valore) => {
                campo.value = valore;
                campo.dispatchEvent(new Event('input'));
                return contatore.textContent.trim();
            };
            const esito = {
                chi: campo.id,
                conTag: leggi('<strong>ab</strong> &amp;'),
                attesoConTag: (massimo - 4) + ' caratteri rimasti',
                oltre: leggi('x'.repeat(massimo + 1200)),
                attesoOltre: '1.200 caratteri in più',
            };
            leggi(prima);
            return esito;
        }));
        // «Tag HTML consentiti» (11/10): chiuso l'elenco non si vede; un clic
        // lo apre sulla stessa riga, subito dopo il comando; un secondo clic
        // lo richiude. Con il clic vero, come lo fa chi usa la pagina.
        const comandiTag = await page.$$('.tag-consentiti summary');
        const tagStorti = [];
        for (const comando of comandiTag) {
            const leggi = () => comando.evaluate((s) => {
                const elenco = s.parentElement.querySelector(':scope > span');
                const r = elenco.getClientRects()[0];
                const c = s.getBoundingClientRect();
                return {
                    chi: s.closest('.tag-consentiti').id,
                    visibile: elenco.checkVisibility(),
                    // Il comando e' alto 24 px, il testo 18: sulla stessa riga vuol dire
                    // dentro l'altezza del comando.
                    stessaRiga: r ? r.top >= c.top - 1 && r.bottom <= c.bottom + 1 : null,
                    subitoDopo: r ? Math.abs(r.left - c.right) < 2 : null,
                    testo: (s.textContent + elenco.textContent).trim(),
                    // 0,85 rem, piu' piccolo degli aiuti (13/10, Alessandro).
                    misura: Math.abs(parseFloat(getComputedStyle(s).fontSize)
                        - 0.85 * parseFloat(getComputedStyle(document.documentElement).fontSize)) < 0.1,
                    // Attaccato al suo campo (11/10): dal fondo del campo alla
                    // cima del testo del comando al massimo 6 px, e meno della
                    // distanza da cio' che viene dopo.
                    ...(() => {
                        const aiuto = s.closest('.tag-consentiti');
                        const campo = aiuto.previousElementSibling.querySelector('textarea');
                        const rg = document.createRange();
                        rg.selectNodeContents(s);
                        const t = rg.getBoundingClientRect();
                        let dopo = aiuto.nextElementSibling;
                        while (dopo !== null && (dopo.tagName === 'SCRIPT' || dopo.getBoundingClientRect().height === 0)) {
                            dopo = dopo.nextElementSibling;
                        }
                        const sopra = Math.round(t.top - campo.getBoundingClientRect().bottom);
                        // Fino all'etichetta del campo dopo, se c'e': il suo
                        // riquadro comincia piu' in alto del testo che si vede.
                        const prossimo = dopo === null ? null : (dopo.querySelector('label') || dopo);
                        const sotto = prossimo === null ? null : Math.round(prossimo.getBoundingClientRect().top - t.bottom);
                        return { sopra, sotto, vicino: sopra >= 0 && sopra <= 6 && (sotto === null || sopra < sotto) };
                    })(),
                };
            });
            const chiuso = await leggi();
            await comando.click();
            const aperto = await leggi();
            await comando.click();
            const richiuso = await leggi();
            if (chiuso.visibile || !chiuso.vicino || !chiuso.misura || !aperto.visibile || !aperto.stessaRiga || !aperto.subitoDopo || richiuso.visibile
                || aperto.testo !== 'Tag HTML consentiti: <strong>, <em>, <u>, <ul>, <ol>, <li>, <a href="…">.') {
                tagStorti.push(JSON.stringify({ chiuso, aperto, richiuso }));
            }
        }
        if (comandiTag.length > 0) {
            check(nome + ': «Tag HTML consentiti», a 0,85 rem, sta attaccato al campo, apre l\'elenco sulla stessa riga, e un secondo clic lo chiude',
                tagStorti.length === 0, tagStorti);
        }

        if (contaTesto.length > 0) {
            const storti = contaTesto.filter((e) => e.conTag !== e.attesoConTag || e.oltre !== e.attesoOltre)
                .map((e) => JSON.stringify(e));
            check(nome + ': il contatore dei campi con i tag conta il testo, e oltre il limite dice quanti in più',
                storti.length === 0, storti);
        }
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
        // I comandi del modulo con l'icona (19/10): quello che dice l'icona
        // (crea o modifica) il lettore di schermo lo sente come parola.
        const comandi = await page.evaluate(() => [...document.querySelectorAll('.module-card-actions .comando-con-icona')]
            .map((a) => ({ visto: a.innerText.trim(), nome: a.textContent.replace(/\s+/g, ' ').trim(), icona: a.querySelector('svg.icona') !== null })));
        if (comandi.length > 0) {
            const male = comandi.filter((c) => !c.icona || (c.visto !== 'Elimina' && !/^(Aggiungi|Modifica) /.test(c.nome)));
            check(nome + ': i comandi del modulo hanno l\'icona, e il lettore di schermo sente «Aggiungi» o «Modifica»',
                male.length === 0, male.map((c) => JSON.stringify(c)));
        }

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

// Il campo della presentazione nel profilo ha lo stesso contatore delle
// risposte aperte (06/10), e si guarda con lo stesso controllo.
EXTRA['Profilo'] = EXTRA['Quiz da svolgere'];

/*
 * L'Agenda (10/10, Alessandro): dal titolo di un gruppo alla sua prima voce,
 * e da «Storico» aperto alla sua, la stessa distanza a occhio. Si misura dal
 * fondo della riga di testo del titolo, non dal suo riquadro: lo Storico e'
 * un `summary` con un'altezza minima, e due margini uguali sui due h2 non
 * davano la stessa distanza (erano 8 e 26 px). E chiuso, «Storico» resta una
 * riga sola, alta quanto il bersaglio, con il titolo al centro.
 */
EXTRA['Agenda'] = async (page, nome) => {
    const esito = await page.evaluate(() => {
        const storico = document.querySelector('details.agenda-passati');

        if (storico === null) {
            return null;
        }

        const fondoTesto = (h) => {
            const r = document.createRange();
            r.selectNodeContents(h);
            const rr = [...r.getClientRects()];
            return rr[rr.length - 1].bottom;
        };
        const s = storico.querySelector(':scope > summary');
        const chiusa = Math.round(s.getBoundingClientRect().height);
        const gruppi = [...document.querySelectorAll('.agenda-gruppo')]
            .map((g) => [g.querySelector('h2'), g.querySelector('.agenda-voce')])
            .filter(([h, v]) => h && v)
            .map(([h, v]) => Math.round(v.getBoundingClientRect().top - fondoTesto(h)));

        storico.open = true;
        const voce = storico.querySelector('.agenda-voce');
        const daStorico = voce ? Math.round(voce.getBoundingClientRect().top - fondoTesto(s.querySelector('h2'))) : null;
        storico.open = false;

        return { gruppi, daStorico, chiusa };
    });

    if (esito === null || esito.gruppi.length === 0 || esito.daStorico === null) {
        console.log('  --   ' + nome + ': manca un gruppo o lo Storico con delle voci, distanze non confrontate');
        return;
    }

    check(nome + ': dal titolo alla prima voce, la stessa distanza nei gruppi e nello Storico',
        esito.gruppi.every((d) => Math.abs(d - esito.daStorico) <= 1), [JSON.stringify(esito)]);
    check(nome + ': «Storico» chiuso resta una riga sola', esito.chiusa <= 26, ['alta ' + esito.chiusa + ' px']);
};

// Gli obiettivi da staff (11/10): quello del corso nel suo riquadro, quelli
// dei moduli sotto i titoli, tutti completi e nessuno ridotto a una riga.
EXTRA['Corso con obiettivi, da staff (semina)'] = async (page, nome) => {
    const stato = await page.evaluate(() => ({
        corso: document.querySelectorAll('section.obiettivo-corso').length,
        ridotti: document.querySelectorAll('details.obiettivo-ridotto, details.obiettivo-modulo-ridotto').length,
        moduli: [...document.querySelectorAll('.module-card')].map((m) => !!m.querySelector(':scope > .obiettivo-modulo')),
        // La gerarchia dei moduli (11/10) e' solo per lo studente.
        gerarchia: document.querySelectorAll('.moduli-barra, details.modulo-riga, .etichetta-modulo, .lezioni-fatte, .da-qui, .modulo-in-corso').length,
    }));
    check(nome + ': obiettivo del corso e di ogni modulo completi', stato.corso === 1 && stato.ridotti === 0
        && stato.moduli.length === 3 && stato.moduli.every(Boolean), [JSON.stringify(stato)]);
    check(nome + ': tutti i moduli aperti, senza etichette né «Riprendi»', stato.gerarchia === 0, [JSON.stringify(stato)]);
};

// E la descrizione del corso (10/10), nella creazione e nella modifica, e
// l'obiettivo del corso e del modulo (11/10).
EXTRA['Nuovo modulo'] = EXTRA['Quiz da svolgere'];
EXTRA['Modifica modulo'] = EXTRA['Quiz da svolgere'];
EXTRA['Nuovo corso'] = EXTRA['Quiz da svolgere'];
EXTRA['Modifica corso'] = EXTRA['Quiz da svolgere'];
EXTRA['Modifica corso con benvenuto (semina)'] = EXTRA['Quiz da svolgere'];

// E «Due righe su di te» nella richiesta di iscrizione (10/10). Il campo c'e'
// solo sotto un corso con l'iscrizione su richiesta: la semina ne crea uno, il
// corso del mondo B; dove non ce n'e' nessuno il controllo lo dice e salta.
// Nell'editor della lezione niente capolettera, e il testo largo quanto il
// riquadro (14/10, Alessandro): l'editor
// carica il foglio di stile con la classe del testo della lezione, e il
// capolettera compariva mentre si scriveva o incollava, come se fosse stato
// aggiunto al testo. Si scrive un testo nell'editor (senza salvarlo) e si
// guarda la prima lettera del primo paragrafo.
EXTRA['Modifica lezione'] = async (page, nome) => {
    const lettera = await page.evaluate(async () => {
        for (let i = 0; i < 50 && !(window.tinymce && tinymce.activeEditor && tinymce.activeEditor.initialized); i++) {
            await new Promise((r) => setTimeout(r, 100));
        }

        if (!(window.tinymce && tinymce.activeEditor && tinymce.activeEditor.initialized)) {
            return null;
        }

        const ed = tinymce.activeEditor;
        const prima = ed.getContent();
        ed.setContent('<p>Respirare insieme.</p><p>Secondo paragrafo.</p>');
        const doc = ed.getDoc();
        const p = doc.body.querySelector('p');
        const s = doc.defaultView.getComputedStyle(p, '::first-letter');
        const esito = {
            dimensione: s.fontSize,
            testo: doc.defaultView.getComputedStyle(p).fontSize,
            righe: (s.getPropertyValue('initial-letter') || s.getPropertyValue('-webkit-initial-letter') || 'normal').trim(),
            float: s.float,
            // E la larghezza (14/10): il testo occupa tutto il riquadro,
            // meno il margine interno ai lati.
            riga: Math.round(p.getBoundingClientRect().width),
            riquadro: doc.documentElement.clientWidth,
            sinistra: Math.round(p.getBoundingClientRect().left),
        };
        ed.setContent(prima);
        ed.undoManager.clear();
        ed.setDirty(false);

        return esito;
    });

    if (lettera === null) {
        console.log('  --   ' + nome + ': editor non pronto, capolettera non controllato');
        return;
    }

    check(nome + ': nell\'editor il primo paragrafo non ha il capolettera',
        lettera.dimensione === lettera.testo && lettera.righe === 'normal' && lettera.float === 'none', [JSON.stringify(lettera)]);
    check(nome + ': nell\'editor il testo occupa tutta la larghezza, con un margine interno ai lati',
        lettera.sinistra >= 12 && Math.abs(lettera.riquadro - lettera.riga - 2 * lettera.sinistra) <= 2, [JSON.stringify(lettera)]);
};

EXTRA['Catalogo'] = async (page, nome) => {
    await EXTRA['Quiz da svolgere'](page, nome);

    /*
     * La descrizione nelle schede (10/10). Chiusa, una descrizione lunga si
     * vede come in «I miei corsi»: l'estratto con i tre puntini, e niente del
     * resto. «Leggi tutto» la apre dentro la scheda: l'estratto sparisce, il
     * testo intero si vede, e «Chiudi» sta sotto il testo. Era il difetto
     * segnalato da Alessandro: qui la descrizione si vedeva tutta.
     */
    const esito = await page.evaluate(async () => {
        const schede = [...document.querySelectorAll('.catalog-card')];
        const problemi = [];
        let lunghe = 0;

        for (const c of schede) {
            const titolo = c.querySelector('h2').textContent.trim();
            const leggi = c.querySelector('.catalogo-leggi');
            // `.card-meta` e non `p.card-meta`: dall'11/10 la descrizione e'
            // un `div`, perche' dentro ci sono i suoi paragrafi.
            const visibili = [...c.querySelectorAll('.card-meta')].filter((p) => p.checkVisibility())
                .map((p) => p.textContent.trim());
            const piuLunga = Math.max(0, ...visibili.map((t) => t.length));

            if (leggi === null) {
                // Senza «Leggi tutto» la descrizione deve stare nell'estratto.
                if (piuLunga > 91) {
                    problemi.push(titolo + ': descrizione di ' + piuLunga + ' caratteri, intera e senza «Leggi tutto»');
                }
                continue;
            }

            lunghe++;
            const estratto = c.querySelector('.catalogo-estratto').textContent.trim();

            if (!estratto.endsWith('…') || piuLunga > 91) {
                problemi.push(titolo + ': chiusa non mostra solo l\'estratto con i puntini (visibili ' + piuLunga + ' caratteri)');
            }

            // «Leggi tutto» e «Chiudi» stanno con il testo che aprono, non con
            // le lezioni sotto (variante C, 10/10): prima erano a 14 px dalla
            // descrizione e a 3 dalle lezioni.
            const lezioni = c.querySelector('.catalogo-lezioni');
            const vicini = (sopra, comando) => {
                const a = sopra.getBoundingClientRect(), b = comando.getBoundingClientRect(), l = lezioni.getBoundingClientRect();
                return { col_testo: Math.round(b.top - a.bottom), con_lezioni: Math.round(l.top - b.bottom) };
            };
            const chiusa = vicini(c.querySelector('.catalogo-estratto'), leggi.querySelector(':scope > summary'));

            if (lezioni === null || chiusa.col_testo >= chiusa.con_lezioni) {
                problemi.push(titolo + ': «Leggi tutto» non sta piu\' vicino alla descrizione che alle lezioni ' + JSON.stringify(chiusa));
            }

            leggi.open = true;
            const testo = leggi.querySelector(':scope > .card-meta');
            const s = leggi.querySelector(':scope > summary');
            const aperta = vicini(testo, s);

            if (aperta.col_testo >= aperta.con_lezioni) {
                problemi.push(titolo + ': «Chiudi» non sta piu\' vicino al testo che alle lezioni ' + JSON.stringify(aperta));
            }

            if (c.querySelector('.catalogo-estratto').checkVisibility()) {
                problemi.push(titolo + ': aperta, l\'estratto resta sopra il testo intero');
            }
            if (!testo.checkVisibility() || testo.textContent.trim().length <= estratto.length) {
                problemi.push(titolo + ': aperta, il testo intero non si vede');
            }
            if (s.getBoundingClientRect().top < testo.getBoundingClientRect().bottom - 1) {
                problemi.push(titolo + ': aperta, «Chiudi» non sta sotto il testo');
            }

            leggi.open = false;
        }

        return { lunghe, problemi };
    });

    check(nome + ': le descrizioni lunghe sono tagliate, e «Leggi tutto» le apre, vicino al testo', esito.problemi.length === 0, esito.problemi);
    check(nome + ': c\'è almeno una descrizione lunga da provare', esito.lunghe > 0,
        ['nessuna scheda con «Leggi tutto»: la prova qui sopra non ha visto niente']);
};

// E la pagina «Domande» dell'esperto: domanda e risposta, in attesa e
// pubblicate (10/10).
EXTRA['Domande in attesa (semina)'] = EXTRA['Quiz da svolgere'];

// E i campi che hanno avuto il limite il 10/10: descrizione del gruppo e
// della sessione dal vivo, testo della domanda del questionario, titolo e
// testo della pagina d'accesso, testi delle email (pagina Messaggi email, 12/10).
for (const pagina of ['Nuovo gruppo', 'Modifica gruppo', 'Modifica gruppo con corso (semina)',
    'Nuovo incontro', 'Modifica incontro', 'Quiz: domande', 'Modifica domanda',
    'Modifica domanda vero/falso (semina)', 'Aspetto', 'Messaggi email']) {
    EXTRA[pagina] = EXTRA['Quiz da svolgere'];
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

/*
 * UN TELEFONO VERO, cioe' senza mouse (06/10). Il giro «telefono» qui sopra
 * cambia solo la larghezza: il browser ha ancora il passaggio del mouse, e la
 * regola che sul telefono sottolinea i comandi non scatta. Qui il contesto
 * dichiara schermo tattile, e ogni comando di ogni pagina dev'essere
 * sottolineato: senza mouse e' l'unico segnale di «questo si tocca».
 */
async function giroSenzaMouse(browser) {
    console.log('\n=== telefono senza mouse (390×844, tattile) — come si riconoscono i comandi ===');

    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const page = await ctx.newPage();
    await entra(page);

    for (const [url, nome] of PAGINE_INTERNE) {
        const risposta = await page.goto(BASE + url);

        if (risposta && risposta.status() >= 400) {
            continue;
        }

        const segni = await comeSiRiconoscono(page);
        check(nome + ': senza mouse ogni comando è sottolineato', !segni.conMouse && segni.senzaSottolineatura.length === 0,
            segni.conMouse ? ['il contesto dice di avere il mouse'] : segni.senzaSottolineatura);
    }

    await ctx.close();
}

/*
 * IL BENVENUTO DEL TUTOR VISTO DALLO STUDENTE (07/10). Tutti gli altri giri
 * entrano come admin, e l'admin il benvenuto in cima al corso non lo vede:
 * e' per lo studente del gruppo del tutor. Qui entra lo studente del mondo
 * A, a due larghezze, e guarda la pagina del corso completa (prima visita) e
 * ridotta (quarta visita), poi la riapre. Ogni larghezza riparte da una
 * semina nuova, perche' le visite si contano e la seconda larghezza
 * troverebbe il benvenuto gia' ridotto.
 */
async function giroBenvenuto(browser) {
    for (const [larghezza, altezza, daTelefono] of [[1280, 900, false], [390, 844, true]]) {
        console.log('\n=== benvenuto del tutor, da studente (' + larghezza + '×' + altezza + ') ===');

        const seme = semina();
        const corso = seme.A.corso;
        const ctx = await browser.newContext({ viewport: { width: larghezza, height: altezza } });
        const page = await ctx.newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'stud@test.it');
        await page.fill('input[name="password"]', PASS);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('load');

        // Il saluto dopo l'accesso (08/10): c'e', con il nome e il punto
        // esclamativo, si annuncia ai lettori di schermo (il fiore no: e'
        // decorativo), non esce dallo schermo, e dopo 4 secondi e' sparito
        // anche per loro (visibility, non solo opacita').
        const saluto = () => page.evaluate(() => {
            const s = document.querySelector('.saluto');
            if (!s) return null;
            const r = s.getBoundingClientRect();
            const fiore = s.querySelector('[aria-hidden="true"]');
            return {
                testo: s.textContent.trim(), ruolo: s.getAttribute('role'),
                fioreDecorativo: fiore !== null && fiore.textContent.trim() === '🌸',
                visibile: getComputedStyle(s).visibility,
                dentro: r.left >= 0 && r.right <= document.documentElement.clientWidth,
            };
        });
        const appena = await saluto();
        check('dopo l\'accesso lo studente trova «Che bello rivederti» con il suo nome, annunciato',
            appena !== null && /^Che bello rivederti, \S.*! 🌸$/u.test(appena.testo) && appena.ruolo === 'status'
                && appena.fioreDecorativo && appena.dentro,
            [JSON.stringify(appena)]);
        // La durata si chiede all'animazione, e si aspetta fino a dopo la sua
        // fine contando dal punto in cui e' davvero: misurare a occhio da fuori
        // sbagliava di qualche decimo, e non distingueva 3 secondi da 4.
        // L'ingresso e' morbido (08/10, Elena: «appare un po' bruscamente»)
        // e arriva dopo 2 secondi (12/10, Alessandro: prima si guarda la
        // pagina): a 1.900 ms e' ancora invisibile; a 2.250 ms sta entrando.
        // Il tempo dell'animazione conta anche il ritardo. Si ferma l'animazione in
        // quei due istanti e si legge l'opacita', poi la si rimette dov'era.
        const ingresso = await page.evaluate(() => {
            const s = document.querySelector('.saluto');
            const a = s.getAnimations()[0];
            if (!a) return null;
            const dove = a.currentTime;
            a.pause();
            const opacita = (ms) => { a.currentTime = ms; return +parseFloat(getComputedStyle(s).opacity).toFixed(2); };
            const misure = { a1900: opacita(1900), a2250: opacita(2250) };
            a.currentTime = dove;
            a.play();
            return misure;
        });
        check('il saluto arriva dopo 2 secondi, con calma: invisibile a 1.900 ms, ancora in arrivo a 2.250',
            ingresso !== null && ingresso.a1900 === 0 && ingresso.a2250 > 0 && ingresso.a2250 < 0.75, [JSON.stringify(ingresso)]);

        const tempi = await page.evaluate(() => {
            const a = document.querySelector('.saluto').getAnimations()[0];
            return a ? { durata: a.effect.getTiming().duration, fine: a.effect.getComputedTiming().endTime, adesso: a.currentTime } : null;
        });
        check('il saluto dura 5 secondi (Elena)', tempi !== null && tempi.durata === 5000, [JSON.stringify(tempi)]);
        await page.waitForTimeout(Math.max(0, (tempi ? tempi.fine - tempi.adesso : 7000) + 300));
        const dopo = await saluto();
        check('alla fine il saluto è sparito', dopo !== null && dopo.visibile === 'hidden', [JSON.stringify(dopo)]);

        // Il profilo dello studente (07/10): nome e cognome separati, e la
        // scelta di come compare agli altri studenti, con l'anteprima.
        // La pagina del gruppo che si scalda (12/10, Alessandro). Nel gruppo da
        // otto: la domanda del tutor, il campo per rispondere (lo studente di
        // A non ha ancora risposto), le risposte dei compagni, ma non quella
        // nascosta dal tutor, e le domande passate. Nel gruppo di A: il
        // prossimo incontro con «Ci sarò». Gli id sono quelli della semina di
        // questo giro: la semina ricrea i gruppi.
        await esamina(page, '/gruppi/' + seme.gruppo_cerchio, 'Pagina del gruppo con il giro (studente)', BERSAGLIO_MINIMO, daTelefono);
        const giro = await page.evaluate(() => {
            const g = document.querySelector('section.giro');
            return g ? {
                domanda: (g.querySelector('.giro-domanda') || {}).textContent,
                campo: !!g.querySelector('form[action$="/risposta"] textarea[name="answer"]'),
                risposte: g.querySelectorAll('.giro-risposta').length,
                nascosta: g.textContent.includes('nascosto'),
                moderazione: g.querySelectorAll('.giro-modera').length,
                nuovaDomanda: !!g.querySelector('form[action$="/domanda"]'),
                passate: (g.querySelector('a.giro-passate-comando') || {}).textContent,
                dopoIlCerchio: !!document.querySelector('.gruppo-persone ~ section.giro'),
            } : null;
        });
        check('nel gruppo, sotto il cerchio, la domanda del tutor con il campo per rispondere e le risposte dei compagni',
            giro !== null && giro.domanda === 'Che cosa ti porti via dal secondo modulo?' && giro.campo && giro.risposte === 2
                && giro.dopoIlCerchio && /^Domande passate \(1\)$/.test((giro.passate || '').trim()),
            [JSON.stringify(giro)]);
        check('lo studente non vede la risposta nascosta, né i comandi di chi modera',
            giro !== null && !giro.nascosta && giro.moderazione === 0 && !giro.nuovaDomanda, [JSON.stringify(giro)]);
        await esamina(page, '/gruppi/' + seme.gruppo_cerchio + '/domande-passate', 'Domande passate del gruppo (studente)', BERSAGLIO_MINIMO, daTelefono);
        await esamina(page, '/gruppi/' + seme.A.gruppo, 'Pagina del gruppo con l\'incontro (studente)', BERSAGLIO_MINIMO, daTelefono);
        const incontroGruppo = await page.evaluate(() => {
            const s = document.querySelector('section.gruppo-incontro');
            return s ? {
                primo: s === document.querySelector('.page-header ~ section.card'),
                titolo: !!s.querySelector('h3 a[href^="/live/"]'),
                bottone: (s.querySelector('form[action$="/ci-saro"] button') || {}).textContent,
            } : null;
        });
        check('nel gruppo di A, in cima, il prossimo incontro con «Ci sarò»',
            incontroGruppo !== null && incontroGruppo.primo && incontroGruppo.titolo && (incontroGruppo.bottone || '').trim() === 'Ci sarò',
            [JSON.stringify(incontroGruppo)]);

        await esamina(page, '/profilo', 'Profilo (studente)', BERSAGLIO_MINIMO, daTelefono);
        const scelte = await page.locator('fieldset.scelta-nome input[name="name_display"]').count();
        check('nel profilo dello studente ci sono le tre scelte di come compare', scelte === 3, ['scelte: ' + scelte]);
        // Nello stesso ordine della pagina «Primo accesso» (09/10, Elena):
        // da «Nome e cognome» a «Solo le iniziali».
        const ordineProfilo = await page.$$eval('fieldset.scelta-nome input[name="name_display"]', (i) => i.map((x) => x.value));
        check('nel profilo le scelte vanno da «Nome e cognome» a «Solo le iniziali», come al primo accesso',
            JSON.stringify(ordineProfilo) === JSON.stringify(['full', 'first', 'initials']), [JSON.stringify(ordineProfilo)]);

        // In un riquadro suo, con il titolo sopra le scelte per misura e per
        // peso (07/10: prima la `legend` era a 0,90 rem e le scelte a 0,95,
        // e in grassetto tutte e due).
        const gerarchia = await page.evaluate(() => {
            const riquadro = document.querySelector('fieldset.scelta-nome').closest('.card');
            const h = getComputedStyle(riquadro.querySelector('h2'));
            const o = getComputedStyle(riquadro.querySelector('.checkbox-label'));
            return {
                soloLei: riquadro.querySelectorAll('input:not([type=hidden]):not([type=radio])').length === 0,
                titolo: parseFloat(h.fontSize), opzione: parseFloat(o.fontSize),
                pesoTitolo: parseInt(h.fontWeight, 10), pesoOpzione: parseInt(o.fontWeight, 10),
            };
        });
        check('la scelta sta in un riquadro suo, senza i dati anagrafici', gerarchia.soloLei, [JSON.stringify(gerarchia)]);
        check('il titolo del riquadro è più grande e più marcato delle scelte',
            gerarchia.titolo > gerarchia.opzione && gerarchia.pesoTitolo > gerarchia.pesoOpzione, [JSON.stringify(gerarchia)]);

        // Le stesse spaziature del riquadro «Dati personali» (08/10): dal titolo al
        // primo elemento, e dall'ultimo elemento all'aiuto. Prima erano 29 e
        // 21 px contro 14 e 8.
        const spazi = await page.evaluate(() => {
            const riquadro = (titolo) => [...document.querySelectorAll('main .card')]
                .find((c) => c.querySelector('h2') && c.querySelector('h2').textContent.trim() === titolo);
            const dati = riquadro('Dati personali');
            const scelta = document.querySelector('fieldset.scelta-nome').closest('.card');
            const sopra = (c, el) => Math.round(el.getBoundingClientRect().top - c.querySelector('h2').getBoundingClientRect().bottom);
            const etichette = scelta.querySelectorAll('.checkbox-label');
            const primoAiutoDati = dati.querySelector('input[type="email"] ~ .form-hint, .form-hint');
            const campoDati = primoAiutoDati.previousElementSibling;
            return {
                titoloDati: sopra(dati, dati.querySelector('form label')),
                titoloScelta: sopra(scelta, etichette[0]),
                aiutoDati: Math.round(primoAiutoDati.getBoundingClientRect().top - campoDati.getBoundingClientRect().bottom),
                aiutoScelta: Math.round(scelta.querySelector('.form-hint').getBoundingClientRect().top
                    - etichette[etichette.length - 1].getBoundingClientRect().bottom),
            };
        });
        check('«Come ti vedono gli altri studenti» ha le spaziature del riquadro «Dati personali»',
            Math.abs(spazi.titoloDati - spazi.titoloScelta) <= 1 && Math.abs(spazi.aiutoDati - spazi.aiutoScelta) <= 1,
            [JSON.stringify(spazi)]);

        const url = '/courses/' + corso;

        await esamina(page, url, 'Corso con benvenuto completo (studente)', BERSAGLIO_MINIMO, daTelefono);
        const completo = await page.evaluate(() => ({
            scheda: document.querySelectorAll('section.tutor-benvenuto').length,
            ridotto: document.querySelectorAll('details.tutor-benvenuto-ridotto').length,
            audio: !!document.querySelector('section.tutor-benvenuto audio[controls][aria-label]'),
            testo: !!document.querySelector('section.tutor-benvenuto details.tutor-benvenuto-trascrizione'),
            // I contatti (07/10): l'email del tutor e il link del gruppo, che
            // porta fuori da Pistacchio e si apre in una scheda nuova.
            email: !!document.querySelector('.tutor-benvenuto-contatti a[href^="mailto:"]'),
            whatsapp: !!document.querySelector('.tutor-benvenuto-contatti a[href^="https://chat.whatsapp.com/"][target="_blank"][rel~="noopener"]'),
        }));
        // La testa del corso in una colonna sola (07/10, scelto da Elena):
        // copertina, benvenuto e moduli con gli stessi bordi. Prima erano
        // 569, 900 e 1016 px, allineati solo a sinistra.
        const bordi = await page.evaluate(() => [
            ['copertina', '.course-hero'],
            ['obiettivo del corso', 'section.obiettivo-corso'],
            ['benvenuto', 'section.tutor-benvenuto'],
            ['primo modulo', '.module-card'],
        ].map(([nome, sel]) => {
            const e = document.querySelector(sel);
            const r = e ? e.getBoundingClientRect() : null;
            return { nome, sinistra: r ? Math.round(r.left) : null, destra: r ? Math.round(r.right) : null };
        }));
        const riferimento = bordi[bordi.length - 1];
        const storti = bordi.filter((b) => b.sinistra === null
            || Math.abs(b.sinistra - riferimento.sinistra) > 1 || Math.abs(b.destra - riferimento.destra) > 1);
        check('copertina, benvenuto e moduli hanno gli stessi bordi', storti.length === 0,
            bordi.map((b) => b.nome + ' ' + b.sinistra + '–' + b.destra));

        check('prima visita: il benvenuto è completo, con il lettore e il testo',
            completo.scheda === 1 && completo.ridotto === 0 && completo.audio && completo.testo, [JSON.stringify(completo)]);
        check('nel benvenuto completo ci sono l\'email del tutor e il link al gruppo WhatsApp',
            completo.email && completo.whatsapp, [JSON.stringify(completo)]);

        // «Tutor del tuo gruppo in questo corso» (12/10, Alessandro): «tuo
        // gruppo» porta alla pagina del gruppo, che lo studente puo' aprire.
        const tuoGruppo = await page.evaluate(() => {
            const a = document.querySelector('section.tutor-benvenuto .tutor-benvenuto-sotto a');
            return a ? { testo: a.textContent.trim(), href: a.getAttribute('href'),
                frase: a.closest('p').textContent.replace(/\s+/g, ' ').trim() } : null;
        });
        let paginaGruppo = null;
        if (tuoGruppo && /^\/gruppi\/\d+$/.test(tuoGruppo.href)) {
            const risposta = await page.request.get(BASE + tuoGruppo.href);
            paginaGruppo = { stato: risposta.status(), cerchio: (await risposta.text()).includes('gruppo-cerchio') };
        }
        check('nel benvenuto «tuo gruppo» porta alla pagina del gruppo dello studente',
            tuoGruppo !== null && tuoGruppo.testo === 'tuo gruppo' && tuoGruppo.frase === 'Tutor del tuo gruppo in questo corso.'
                && paginaGruppo !== null && paginaGruppo.stato === 200 && paginaGruppo.cerchio,
            [JSON.stringify({ tuoGruppo, paginaGruppo })]);

        // Gli obiettivi (11/10). Prima visita: quello del corso completo, nel
        // suo riquadro fra la descrizione e il benvenuto; quello del modulo
        // in corso (il primo: lo studente non ha fatto niente) completo,
        // quello del modulo chiuso ridotto a una riga con «Mostra».
        const obiettivi = await page.evaluate(() => {
            const sezione = document.querySelector('section.obiettivo-corso');
            const moduli = [...document.querySelectorAll('.module-card')];
            const ridotto = moduli[1] ? moduli[1].querySelector(':scope > details.obiettivo-modulo-ridotto:not([open])') : null;
            return {
                corso: sezione !== null && sezione.closest('details') === null,
                ordine: sezione !== null
                    && sezione.previousElementSibling !== null && sezione.previousElementSibling.matches('.course-description')
                    && sezione.nextElementSibling !== null && sezione.nextElementSibling.matches('section.tutor-benvenuto'),
                elenco: sezione !== null && sezione.querySelectorAll('.testo-breve ul > li').length === 2,
                inCorso: moduli[0] ? !!moduli[0].querySelector(':scope > .obiettivo-modulo') : false,
                chiuso: ridotto !== null,
                comando: ridotto ? ridotto.querySelector('.obiettivo-comando').innerText.trim() : null,
                testoChiusoVisibile: ridotto ? ridotto.querySelector('.obiettivo-modulo').checkVisibility() : null,
            };
        });
        check('prima visita: l\'obiettivo del corso è completo, fra la descrizione e il benvenuto, con il suo elenco',
            obiettivi.corso && obiettivi.ordine && obiettivi.elenco, [JSON.stringify(obiettivi)]);
        check('l\'obiettivo del modulo in corso è completo, quello del modulo chiuso è una riga con «Mostra»',
            obiettivi.inCorso && obiettivi.chiuso && obiettivi.comando === 'Mostra' && obiettivi.testoChiusoVisibile === false,
            [JSON.stringify(obiettivi)]);

        // La gerarchia dei moduli (11/10). Lo studente non ha fatto niente:
        // il primo modulo e' in corso, con «In corso», «0 di 1 lezioni fatte»
        // e, sulla riga della lezione, «Inizia da qui» (13/10); quello
        // chiuso e' il prossimo, aperto, con «Prossimo» e la data; il terzo e'
        // una riga «1 lezione» che si apre. Sopra, solo «Mostra tutti i
        // moduli» (22/10).
        const gerarchiaModuli = await page.evaluate(() => {
            const carte = [...document.querySelectorAll('.module-card')];
            const riga = document.querySelector('details.modulo-riga:not([open])');
            const barra = document.querySelector('.moduli-barra');
            const conto = carte[0] ? carte[0].querySelector('.lezioni-fatte') : null;
            const daQui = carte[0] ? carte[0].querySelector('.lesson-list-item.da-qui') : null;
            return {
                barra: barra ? barra.textContent.replace(/\s+/g, ' ').trim() : null,
                // La domanda all'esperto stacca dall'ultimo modulo piu' di
                // quanto un modulo stacchi dall'altro (23/10): non e' un modulo
                // del corso. (La riga del certificato in fondo e' stata tolta
                // il 23/10; il suo controllo con lei: lo studente della semina
                // ha il certificato, e la riga qui non sarebbe mai comparsa.)
                stacchi: (() => {
                    const l = document.querySelector('.module-list');
                    const qa = document.querySelector('section.qa');
                    if (l === null || qa === null) {
                        return null;
                    }
                    const figli = [...l.children].filter((e) => e.getBoundingClientRect().height > 0);
                    const fraModuli = [];
                    for (let i = 1; i < figli.length; i++) {
                        fraModuli.push(Math.round(figli[i].getBoundingClientRect().top - figli[i - 1].getBoundingClientRect().bottom));
                    }
                    const ultimo = figli[figli.length - 1].getBoundingClientRect().bottom;
                    return { fraModuli, domanda: Math.round(qa.getBoundingClientRect().top - ultimo) };
                })(),
                // La tinta tenue del tema solo sulla prossima cosa da fare
                // (23/10): l'obiettivo del corso e la domanda all'esperto
                // sono bianchi, ciascuno con il suo segno a sinistra del
                // titolo (`bullseye`, `question-circle`).
                tinta: (() => {
                    const vale = (v) => {
                        const e = document.createElement('div');
                        e.style.background = 'var(' + v + ')';
                        document.body.append(e);
                        const c = getComputedStyle(e).backgroundColor;
                        e.remove();
                        return c;
                    };
                    // Il fondo che si vede: il primo antenato non trasparente.
                    const fondo = (el) => {
                        for (let e = el; e; e = e.parentElement) {
                            const c = getComputedStyle(e).backgroundColor;
                            if (c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') {
                                return c;
                            }
                        }
                        return null;
                    };
                    const tenue = vale('--color-primary-soft');
                    const bianco = vale('--color-surface');
                    const obiettivo = document.querySelector('section.obiettivo-corso');
                    const riga = document.querySelector('section.qa .qa-riga');
                    const daQui = document.querySelector('.lesson-list-item.da-qui, .module-quiz-row.da-qui');
                    if (obiettivo === null || riga === null || daQui === null) {
                        return null;
                    }
                    const primo = (el) => el.firstElementChild ? el.firstElementChild.getAttribute('class') : null;
                    return {
                        obiettivoBianco: fondo(obiettivo) === bianco,
                        obiettivoIcona: primo(obiettivo.querySelector('.obiettivo-corso-testa')),
                        espertoBianco: fondo(riga) === bianco,
                        espertoIcona: primo(riga),
                        daQuiTenue: fondo(daQui) === tenue,
                    };
                })(),
                comando: barra && barra.querySelector('.moduli-comando') ? barra.querySelector('.moduli-comando').textContent.trim() : null,
                carte: carte.length,
                inCorso: carte[0] ? carte[0].classList.contains('modulo-in-corso')
                    && carte[0].querySelector('h2 .etichetta-modulo').textContent.trim() === 'In corso' : false,
                conto: conto ? conto.textContent.replace(/\s+/g, ' ').trim() : null,
                daQui: daQui ? (daQui.querySelector('a.da-qui-comando') || {}).textContent : null,
                daQuiVa: daQui ? daQui.querySelector('a.da-qui-comando').getAttribute('href')
                    === daQui.querySelector('a:not(.da-qui-comando)').getAttribute('href') : false,
                riquadro: document.querySelectorAll('.riprendi').length,
                prossimo: carte[1] ? carte[1].querySelector('h2 .etichetta-modulo').textContent.trim() : null,
                prossimoChiuso: carte[1] ? carte[1].classList.contains('module-locked') : false,
                riga: riga ? riga.querySelector('.modulo-riga-stato').textContent.trim() : null,
                rigaComando: riga ? riga.querySelector('.obiettivo-comando').innerText.trim() : null,
                rigaLezioneVisibile: riga ? riga.querySelector('.lesson-list').checkVisibility() : null,
            };
        });
        check('gerarchia: il primo modulo «In corso» con «Inizia da qui» sulla lezione, il chiuso «Prossimo», il terzo una riga',
            gerarchiaModuli.carte === 2 && gerarchiaModuli.inCorso && gerarchiaModuli.daQuiVa
                && gerarchiaModuli.conto === '0 di 1 lezioni fatte' && (gerarchiaModuli.daQui || '').trim() === 'Inizia da qui'
                && gerarchiaModuli.riquadro === 0
                && gerarchiaModuli.prossimo === 'Prossimo' && gerarchiaModuli.prossimoChiuso
                && gerarchiaModuli.riga === '1 lezione' && gerarchiaModuli.rigaComando === 'Mostra' && gerarchiaModuli.rigaLezioneVisibile === false,
            [JSON.stringify(gerarchiaModuli)]);
        check('gerarchia: sopra i moduli solo «Mostra tutti i moduli», senza conti (22/10)',
            gerarchiaModuli.barra === 'Mostra tutti i moduli' && gerarchiaModuli.comando === 'Mostra tutti i moduli', [JSON.stringify(gerarchiaModuli)]);
        check('pagina del corso: «Fai una domanda all\'esperto» stacca dall\'ultimo modulo più dei moduli fra loro (23/10)',
            gerarchiaModuli.stacchi !== null && gerarchiaModuli.stacchi.fraModuli.length > 0
                && gerarchiaModuli.stacchi.domanda > Math.max(...gerarchiaModuli.stacchi.fraModuli),
            [JSON.stringify(gerarchiaModuli.stacchi)]);
        check('pagina del corso: la tinta tenue solo sulla prossima lezione; obiettivo e domanda all\'esperto bianchi (23/10)',
            gerarchiaModuli.tinta !== null && gerarchiaModuli.tinta.daQuiTenue
                && gerarchiaModuli.tinta.obiettivoBianco && gerarchiaModuli.tinta.espertoBianco,
            [JSON.stringify(gerarchiaModuli.tinta)]);
        check('pagina del corso: bullseye accanto a «Obiettivo del corso», question-circle accanto a «Fai una domanda all\'esperto» (23/10)',
            gerarchiaModuli.tinta !== null
                && /\bicona-bullseye\b/.test(gerarchiaModuli.tinta.obiettivoIcona || '')
                && /\bicona-question-circle\b/.test(gerarchiaModuli.tinta.espertoIcona || ''),
            [JSON.stringify(gerarchiaModuli.tinta)]);
        if (gerarchiaModuli.riga !== null) {
            await page.click('details.modulo-riga > summary');
            const aperta = await page.evaluate(() => ({
                lezione: document.querySelector('details.modulo-riga[open] .lesson-list').checkVisibility(),
                comando: document.querySelector('details.modulo-riga[open] .obiettivo-comando').innerText.trim(),
            }));
            await page.click('details.modulo-riga > summary');
            check('gerarchia: la riga si apre con le sue lezioni e il comando diventa «Nascondi»',
                aperta.lezione && aperta.comando === 'Nascondi', [JSON.stringify(aperta)]);
        }
        // «Mostra tutti i moduli» in una scheda a parte, per non toccare le
        // visite che contano qui sotto: tutto aperto, e il comando torna indietro.
        {
            const altra = await page.context().newPage();
            await altra.goto(BASE + url + '?moduli=tutti');
            const tutti = await altra.evaluate(() => ({
                carte: document.querySelectorAll('.module-card').length,
                righe: document.querySelectorAll('details.modulo-riga').length,
                comando: document.querySelector('.moduli-comando').textContent.trim(),
            }));
            await altra.close();
            check('gerarchia: «Mostra tutti i moduli» apre tutto, e il comando diventa «Mostra solo il modulo in corso»',
                tutti.carte === 3 && tutti.righe === 0 && tutti.comando === 'Mostra solo il modulo in corso', [JSON.stringify(tutti)]);
        }

        await page.goto(BASE + url);
        await page.goto(BASE + url);
        await esamina(page, url, 'Corso con benvenuto ridotto (studente)', BERSAGLIO_MINIMO, daTelefono);
        // Senza riga ridotta si segnala e si prosegue: un controllo che si
        // interrompe fa perdere tutti quelli dopo (§5 del promemoria).
        const ridotto = await page.evaluate(() => {
            const riga = document.querySelector('details.tutor-benvenuto-ridotto:not([open]) > summary');
            const contatti = document.querySelector('.tutor-benvenuto-contatti');
            return {
                riga: riga !== null,
                altezza: riga ? Math.round(riga.getBoundingClientRect().height) : null,
                // `innerText`, non `textContent`: conta solo la parola che si
                // vede, «Mostra» da chiuso, e non anche «Nascondi», che c'e' ma
                // e' nascosta.
                comando: riga ? riga.querySelector('.tutor-benvenuto-riascolta').innerText.trim() : null,
                // Nella riga i contatti non si vedono: si vedono riaprendo (Elena).
                // `checkVisibility()` e non i rettangoli: Chromium dispone il
                // contenuto di un `details` chiuso anche se non lo mostra.
                contattiVisibili: contatti !== null && contatti.checkVisibility(),
            };
        });
        check('quarta visita: il benvenuto è ridotto a una riga', ridotto.riga && ridotto.altezza < 80,
            [JSON.stringify(ridotto)]);

        // Quarta visita anche per l'obiettivo del corso: una riga, «Mostra»
        // lo riapre, «Nascondi» lo richiude (11/10). Il modulo in corso
        // resta completo: la sua regola non conta le visite.
        const obiettivoRidotto = await page.evaluate(() => {
            const riga = document.querySelector('details.obiettivo-ridotto:not([open]) > summary');
            return {
                riga: riga !== null,
                altezza: riga ? Math.round(riga.getBoundingClientRect().height) : null,
                comando: riga ? riga.querySelector('.obiettivo-comando').innerText.trim() : null,
                completoFuori: document.querySelectorAll('section.obiettivo-corso').length
                    - document.querySelectorAll('details.obiettivo-ridotto section.obiettivo-corso').length,
                inCorso: !!document.querySelector('.module-card > .obiettivo-modulo'),
            };
        });
        let obiettivoRiaperto = null;
        if (obiettivoRidotto.riga) {
            await page.click('details.obiettivo-ridotto > summary');
            obiettivoRiaperto = await page.evaluate(() => ({
                visibile: document.querySelector('details.obiettivo-ridotto[open] section.obiettivo-corso').checkVisibility(),
                comando: document.querySelector('details.obiettivo-ridotto[open] .obiettivo-comando').innerText.trim(),
            }));
            await page.click('details.obiettivo-ridotto > summary');
        }
        check('quarta visita: l\'obiettivo del corso è una riga con «Mostra», che lo riapre e diventa «Nascondi»',
            obiettivoRidotto.riga && obiettivoRidotto.altezza < 80 && obiettivoRidotto.comando === 'Mostra'
                && obiettivoRidotto.completoFuori === 0 && obiettivoRiaperto !== null
                && obiettivoRiaperto.visibile && obiettivoRiaperto.comando === 'Nascondi',
            [JSON.stringify({ obiettivoRidotto, obiettivoRiaperto })]);
        // I segni dei riquadri «in piu'» (23/10): obiettivo ridotto, foto del
        // tutor nel benvenuto ridotto e domanda all'esperto hanno la stessa
        // misura (2,4 rem) e partono dalla stessa linea dei titoli dei moduli
        // (bordo del riquadro + 1,25 rem).
        const segni = await page.evaluate(() => {
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
            const lista = document.querySelector('.module-list');
            const linea = lista ? lista.getBoundingClientRect().left + 1 + 1.25 * rem : null;
            const misura = (sel) => {
                const e = document.querySelector(sel);
                if (e === null) {
                    return null;
                }
                const r = e.getBoundingClientRect();
                return { lato: Math.round(r.width * 10) / 10, scarto: linea === null ? null : Math.round((r.left - linea) * 10) / 10 };
            };
            // Dal segno al testo che gli sta accanto, la stessa distanza.
            const distanza = (segno, testo) => {
                const a = document.querySelector(segno);
                const b = document.querySelector(testo);
                return a && b ? Math.round((b.getBoundingClientRect().left - a.getBoundingClientRect().right) * 10) / 10 : null;
            };
            return {
                distanze: [
                    distanza('details.obiettivo-ridotto > summary .icona-riquadro', 'details.obiettivo-ridotto > summary .obiettivo-ridotto-nome'),
                    distanza('details.obiettivo-ridotto section.obiettivo-corso .icona-riquadro', 'details.obiettivo-ridotto section.obiettivo-corso h2'),
                    distanza('details.tutor-benvenuto-ridotto > summary .tutor-benvenuto-miniatura', 'details.tutor-benvenuto-ridotto > summary .tutor-benvenuto-ridotto-nome'),
                    distanza('section.qa .qa-riga .icona-riquadro', 'section.qa .qa-riga .qa-riga-testo'),
                ],
                attesa: Math.round(2.4 * rem * 10) / 10,
                obiettivo: misura('details.obiettivo-ridotto > summary .icona-riquadro'),
                tutor: misura('details.tutor-benvenuto-ridotto > summary .tutor-benvenuto-miniatura'),
                esperto: misura('section.qa .qa-riga .icona-riquadro'),
            };
        });
        check('quarta visita: obiettivo, foto del tutor ed esperto hanno un segno della stessa misura, allineato ai titoli dei moduli (23/10)',
            ['obiettivo', 'tutor', 'esperto'].every((k) => segni[k] !== null
                && Math.abs(segni[k].lato - segni.attesa) <= 0.5 && Math.abs(segni[k].scarto) <= 0.5),
            [JSON.stringify(segni)]);
        check('quarta visita: il titolo sta alla stessa distanza dal suo segno nell\'obiettivo (ridotto e completo), nel benvenuto e nell\'esperto (23/10)',
            segni.distanze.every((d) => d !== null) && Math.max(...segni.distanze) - Math.min(...segni.distanze) <= 0.5,
            [JSON.stringify(segni.distanze)]);
        check('quarta visita: l\'obiettivo del modulo in corso resta completo', obiettivoRidotto.inCorso,
            [JSON.stringify(obiettivoRidotto)]);

        // Le due righe una sotto l'altra (12/10, Alessandro): «Mostra» alla
        // stessa distanza dal bordo destro in tutte e due. Si misura la
        // parola che si vede, non la cella, che nell'obiettivo e' larga
        // quanto «Nascondi».
        const mostraDalBordo = await page.evaluate(() => [
            ['details.obiettivo-ridotto:not([open]) > summary', '.obiettivo-se-chiuso'],
            ['details.tutor-benvenuto-ridotto:not([open]) > summary', '.tutor-benvenuto-se-chiuso'],
        ].map(([riga, parola]) => {
            const r = document.querySelector(riga);
            if (!r) return null;
            const testo = document.createRange();
            testo.selectNodeContents(r.querySelector(parola));
            return +(r.getBoundingClientRect().right - testo.getBoundingClientRect().right).toFixed(1);
        }));
        check('«Mostra» dell\'obiettivo e del benvenuto alla stessa distanza dal bordo destro',
            mostraDalBordo[0] !== null && mostraDalBordo[1] !== null && Math.abs(mostraDalBordo[0] - mostraDalBordo[1]) <= 0.5,
            ['obiettivo, benvenuto: ' + JSON.stringify(mostraDalBordo)]);
        check('nella riga il comando si chiama «Mostra», e i contatti non si vedono',
            ridotto.comando === 'Mostra' && !ridotto.contattiVisibili, [JSON.stringify(ridotto)]);

        if (ridotto.riga) {
            // «Riascolta» lo riapre, senza JavaScript: e' un `details`.
            await page.click('details.tutor-benvenuto-ridotto > summary');
            const riaperto = await page.evaluate(() => ({
                aperto: !!document.querySelector('details.tutor-benvenuto-ridotto[open] section.tutor-benvenuto audio'),
                sfora: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                comando: document.querySelector('details.tutor-benvenuto-ridotto[open] .tutor-benvenuto-riascolta').innerText.trim(),
            }));
            check('«Mostra» riapre la scheda completa, senza sforare', riaperto.aperto && riaperto.sfora <= 0,
                [JSON.stringify(riaperto)]);
            check('da aperto il comando dice «Nascondi»', riaperto.comando === 'Nascondi', [JSON.stringify(riaperto)]);

            // E «Nascondi» la richiude, tornando a «Mostra».
            await page.click('details.tutor-benvenuto-ridotto > summary');
            const richiuso = await page.evaluate(() => {
                const d = document.querySelector('details.tutor-benvenuto-ridotto');
                return { aperto: d.open, comando: d.querySelector('.tutor-benvenuto-riascolta').innerText.trim() };
            });
            check('«Nascondi» la richiude e torna «Mostra»', !richiuso.aperto && richiuso.comando === 'Mostra',
                [JSON.stringify(richiuso)]);
        }

        // L'introduzione del corso larga quanto i moduli, e il capolettera
        // (09/10, Elena): alto tre righe, nel verde del progetto, all'inizio
        // della descrizione del corso e del testo della lezione, e solo li':
        // non all'inizio del secondo paragrafo.
        const capo = async () => page.evaluate(() => {
            const verde = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim();
            const prova = document.createElement('span');
            prova.style.color = verde;
            document.body.appendChild(prova);
            const rgbVerde = getComputedStyle(prova).color;
            prova.remove();
            const lettera = (el) => {
                if (!el) return null;
                const s = getComputedStyle(el, '::first-letter');
                const righe = s.getPropertyValue('initial-letter') || s.getPropertyValue('-webkit-initial-letter');
                return { verde: s.color === rgbVerde, tre: righe.trim().startsWith('3') || s.float === 'left' };
            };
            // Dall'11/10 la descrizione e' divisa in paragrafi: il capolettera
            // sta sul primo, se comincia con un paragrafo.
            const descr = document.querySelector('.course-description > p:first-child');
            const modulo = document.querySelector('.module-card');
            const paragrafi = document.querySelectorAll('.lesson-content > p');
            return {
                larghezza: descr && modulo ? [Math.round(descr.getBoundingClientRect().width), Math.round(modulo.getBoundingClientRect().width)] : null,
                descrizione: lettera(descr),
                lezione: lettera(paragrafi[0] || null),
                secondo: paragrafi[1] ? getComputedStyle(paragrafi[1], '::first-letter').color === getComputedStyle(paragrafi[1]).color : null,
            };
        });
        await page.goto(BASE + url);
        const nelCorso = await capo();
        check('l\'introduzione del corso è larga quanto i moduli',
            nelCorso.larghezza !== null && Math.abs(nelCorso.larghezza[0] - nelCorso.larghezza[1]) <= 1, [JSON.stringify(nelCorso)]);
        check('la descrizione del corso comincia con il capolettera verde, alto tre righe',
            nelCorso.descrizione !== null && nelCorso.descrizione.verde && nelCorso.descrizione.tre, [JSON.stringify(nelCorso)]);
        await page.goto(BASE + '/lessons/' + seme.A.lezione);
        const nellaLezione = await capo();
        check('il testo della lezione comincia con il capolettera, e il secondo paragrafo no',
            nellaLezione.lezione !== null && nellaLezione.lezione.verde && nellaLezione.lezione.tre && nellaLezione.secondo === true,
            [JSON.stringify(nellaLezione)]);

        // «Fai una domanda al tutor» in fondo al corso (dal 09/10 senza
        // l'archivio, che ha una pagina sua): chiusa all'arrivo, senza numero,
        // «Mostra» la apre e diventa «Nascondi», la riga non cambia altezza, il
        // titolo e' grande come quello di un modulo, e dentro c'e' il
        // collegamento all'archivio di questo corso.
        const qa = () => page.evaluate(() => {
            const d = document.querySelector('details.qa-apri');
            return d ? { aperta: d.open, titolo: d.querySelector('.qa-riga h2').innerText.trim(),
                         comando: d.querySelector('.qa-comando').innerText.trim() } : null;
        });
        await page.goto(BASE + url);
        const chiusa = await qa();
        check('in fondo al corso «Fai una domanda all\'esperto», chiusa, con «Mostra»',
            chiusa !== null && !chiusa.aperta && chiusa.titolo === 'Fai una domanda all\'esperto' && chiusa.comando === 'Mostra',
            [JSON.stringify(chiusa)]);
        const altezzaRiga = () => page.evaluate(() => Math.round(document.querySelector('.qa-riga').getBoundingClientRect().height));
        const primaDiAprire = await altezzaRiga();
        await page.click('details.qa-apri > summary');
        const aperta = await qa();
        check('«Mostra» la apre e il comando diventa «Nascondi»', aperta.aperta && aperta.comando === 'Nascondi', [JSON.stringify(aperta)]);
        const dopoAverAperto = await altezzaRiga();
        check('aprendola, la riga non cambia altezza', primaDiAprire === dopoAverAperto,
            ['chiusa ' + primaDiAprire + ' px, aperta ' + dopoAverAperto + ' px']);
        const forma = await page.evaluate(() => ({
            titolo: getComputedStyle(document.querySelector('.qa-riga h2')).fontSize,
            titoloModulo: getComputedStyle(document.querySelector('.module-card-header h2')).fontSize,
            archivioQui: document.querySelectorAll('#domande .qa-voce').length,
            collegamento: (document.querySelector('.qa-vai-archivio a') || {}).getAttribute
                ? document.querySelector('.qa-vai-archivio a').getAttribute('href') : null,
        }));
        check('il titolo è grande come quello di un modulo, e l\'archivio non è più qui',
            forma.titolo === forma.titoloModulo && forma.archivioQui === 0, [JSON.stringify(forma)]);
        check('dentro, il collegamento all\'archivio di questo corso',
            forma.collegamento === '/domande-e-risposte?corso=' + corso, [JSON.stringify(forma)]);

        // La pagina «Domande e risposte» (09/10): voce del menu prima di
        // «Certificati»; l'archivio alle misure dei moduli. La ricerca, dal
        // 21/10, sta in cima e solo da cinque domande in su (o se si sta
        // cercando); in fondo «Non hai trovato quello che cercavi?». Si
        // misura com'e', e aperta da una ricerca.
        await page.goto(BASE + '/domande-e-risposte?corso=' + corso);
        const pagina = await page.evaluate(() => {
            const voci = [...document.querySelectorAll('.sidebar-nav a.nav-link')].map((a) => a.textContent.trim());
            const archivio = document.querySelector('.qa-archivio');
            const domanda = document.querySelector('.qa-voce > summary');
            return {
                // Dal 17/10 nel ramo del corso, sotto «I miei corsi».
                nelRamo: document.querySelector('.nav-corso a.nav-link-active') !== null,
                attiva: (document.querySelector('.nav-link-active') || {}).textContent,
                // La ricerca, se c'e', prima del primo modulo; in fondo il rimando.
                cercaInCima: (() => {
                    const form = document.querySelector('.qa-archivio form.qa-cerca');
                    const modulo = document.querySelector('.qa-archivio .qa-modulo');
                    return form === null || modulo === null
                        || (form.compareDocumentPosition(modulo) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
                })(),
                rimandoInFondo: archivio !== null && archivio.lastElementChild.matches('p.qa-non-trovato'),
                domanda: domanda ? getComputedStyle(domanda).fontSize : null,
                testo: getComputedStyle(document.documentElement).fontSize,
            };
        });
        check('nel menu «L\'esperto risponde», nel ramo del corso, ed evidenziata',
            pagina.nelRamo && (pagina.attiva || '').trim() === 'L\'esperto risponde', [JSON.stringify(pagina)]);
        check('l\'archivio con la ricerca in cima, «Non hai trovato…?» in fondo, e le domande alla misura delle lezioni (0,9 rem)',
            pagina.cercaInCima && pagina.rimandoInFondo && pagina.domanda !== null
                && Math.abs(parseFloat(pagina.domanda) - 0.9 * parseFloat(pagina.testo)) < 0.5,
            [JSON.stringify(pagina)]);
        await esamina(page, '/domande-e-risposte', 'L\'esperto risponde (studente)', BERSAGLIO_MINIMO, daTelefono);
        await esamina(page, '/domande-e-risposte?corso=' + corso + '&cerca=Domanda', 'L\'esperto risponde dopo una ricerca (studente)',
            BERSAGLIO_MINIMO, daTelefono);
        // Mentre si cerca il campo resta, anche con poche domande: serve a
        // cambiare la ricerca e a tornare a «Mostra tutte».
        await page.goto(BASE + '/domande-e-risposte?corso=' + corso + '&cerca=Domanda');
        const cercando = await page.evaluate(() => {
            const form = document.querySelector('.qa-archivio form.qa-cerca');
            const modulo = document.querySelector('.qa-archivio .qa-modulo');
            return {
                campo: form !== null,
                inCima: form !== null && (modulo === null || (form.compareDocumentPosition(modulo) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0),
                mostraTutte: document.querySelector('.qa-cerca a.link-btn') !== null,
            };
        });
        check('cercando, il campo c\'è, in cima, con «Mostra tutte»',
            cercando.campo && cercando.inCima && cercando.mostraTutte, [JSON.stringify(cercando)]);

        // La Bacheca (17/10), come la vede lo studente: i riquadri, una
        // categoria con il racconto nascosto e il modulo del racconto, le sue
        // risorse e «Condividi una risorsa» con le sue proposte.
        await esamina(page, '/bacheca?corso=' + corso, 'Bacheca (studente)', BERSAGLIO_MINIMO, daTelefono);
        await esamina(page, '/bacheca/categorie/' + seme.A.bacheca_categoria, 'Categoria della bacheca (studente)', BERSAGLIO_MINIMO, daTelefono);
        await page.evaluate(() => document.querySelectorAll('.bacheca-risorsa details').forEach((d) => { d.open = true; }));
        {
            const r = await page.evaluate(raccogli, BERSAGLIO_MINIMO);
            check('Categoria della bacheca (studente), con racconti e modulo aperti: campi con etichetta, contrasto, bersagli',
                r.senzaEtichetta.length === 0 && r.contrasti.length === 0 && r.bersagli.length === 0 && r.idRipetuti.length === 0,
                [...r.senzaEtichetta, ...r.contrasti, ...r.bersagli, ...r.idRipetuti]);
            const riquadro = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
            check('Categoria della bacheca (studente), aperta: niente scorrimento orizzontale', riquadro);
        }
        await esamina(page, '/bacheca/mie?corso=' + corso, 'Le mie risorse della bacheca (studente)', BERSAGLIO_MINIMO, daTelefono);
        await esamina(page, '/bacheca/condividi?corso=' + corso, 'Condividi una risorsa (studente)', BERSAGLIO_MINIMO, daTelefono);
        await page.goto(BASE + url);

        // Il contatore sopra il campo della domanda (08/10, Elena): «1000
        // caratteri rimasti» all'apertura, sopra l'angolo in alto a destra,
        // sulla riga dell'etichetta, e scende mentre si scrive.
        await page.click('details.qa-apri > summary');
        const contatore = async () => page.evaluate(() => {
            const k = document.querySelector('#qa-domanda-resta');
            const c = document.querySelector('#qa-domanda');
            const l = document.querySelector('label[for="qa-domanda"]');
            if (!k || !c || !l) return null;
            const kr = k.getBoundingClientRect(), cr = c.getBoundingClientRect(), lr = l.getBoundingClientRect();
            return {
                testo: k.textContent.trim(),
                allineato: Math.abs(kr.right - cr.right) <= 1,
                sopra: kr.bottom <= cr.top + 1,
                rigaEtichetta: Math.abs((kr.top + kr.bottom) / 2 - (lr.top + lr.bottom) / 2) < 4,
            };
        });
        const iniziale = await contatore();
        check('sopra la domanda «1000 caratteri rimasti», allineato al campo a destra, sulla riga dell\'etichetta',
            iniziale !== null && iniziale.testo === '1000 caratteri rimasti' && iniziale.allineato && iniziale.sopra && iniziale.rigaEtichetta,
            [JSON.stringify(iniziale)]);
        await page.fill('#qa-domanda', 'Una domanda di prova.');
        const scritto = await contatore();
        check('il contatore scende mentre si scrive', scritto !== null && scritto.testo === '979 caratteri rimasti', [JSON.stringify(scritto)]);

        await ctx.close();

        // Il primo accesso (08/10). Chi non ha ancora scelto come lo vedono
        // gli altri finisce sulla pagina «Primo accesso», senza saluto, da
        // qualunque pagina; con una password temporanea la pagina chiede anche
        // quella. Le due versioni si misurano come le altre pagine.
        // Poi, dopo «Continua», lo studente che non ha visto il video di
        // benvenuto rientra: con il video configurato va al video, senza, ha
        // il saluto (il caso di Carlo, 0150).
        for (const [email, conPassword] of [['primo@test.it', true], ['nuovo@test.it', false]]) {
            const ctxPrimo = await browser.newContext({ viewport: { width: larghezza, height: altezza } });
            const pp = await ctxPrimo.newPage();
            const accedi = async (password) => {
                await pp.goto(BASE + '/login');
                await pp.fill('input[name="email"]', email);
                await pp.fill('input[name="password"]', password);
                await pp.click('form.auth-form button[type="submit"]');
                await pp.waitForLoadState('load');
            };
            await accedi(PASS);
            const dove = () => new URL(pp.url()).pathname;
            check(email + ': al primo accesso finisce su «Primo accesso», senza saluto',
                dove() === '/primo-accesso' && await pp.locator('.saluto').count() === 0, ['pagina: ' + dove()]);
            if (dove() !== '/primo-accesso') {
                // Segnalato qui sopra; il resto del percorso non ha senso, e
                // un controllo che si interrompe fa perdere tutti quelli dopo.
                await ctxPrimo.close();
                continue;
            }
            const campi = await pp.locator('form.auth-form input[type="password"]').count();
            check(email + ': ' + (conPassword ? 'con' : 'senza') + ' i campi della password',
                campi === (conPassword ? 3 : 0), ['campi: ' + campi]);
            await esamina(pp, '/primo-accesso', 'Primo accesso ' + (conPassword ? 'con' : 'senza') + ' password', BERSAGLIO_MINIMO, daTelefono);
            // Le modifiche del 08/10 (Elena): la scelta parte da «Solo il
            // nome»; le frasi sono quelle corte; le tre opzioni stanno vicine
            // (il pallino prendeva l'altezza di un campo, 40 px, e ogni
            // opzione era alta 43).
            const modulo = await pp.evaluate(() => {
                const opzioni = [...document.querySelectorAll('.primo-accesso-opzione')];
                const aiuti = [...document.querySelectorAll('form.auth-form .form-hint')].map((p) => p.textContent.trim());
                return {
                    scelta: (document.querySelector('input[name="name_display"]:checked') || {}).value,
                    // Il pallino e lo spazio fra le opzioni, non l'altezza: con
                    // un nome lungo l'anteprima va a capo, ed e' giusto.
                    pallini: opzioni.map((o) => Math.round(o.querySelector('input').getBoundingClientRect().height)),
                    spazi: opzioni.slice(1).map((o, i) => Math.round(o.getBoundingClientRect().top - opzioni[i].getBoundingClientRect().bottom)),
                    aiuti,
                };
            });
            check(email + ': la scelta parte da «Solo il nome»', modulo.scelta === 'first', [JSON.stringify(modulo)]);
            const ordinePrimo = await pp.$$eval('input[name="name_display"]', (i) => i.map((x) => x.value));
            check(email + ': le scelte vanno da «Nome e cognome» a «Solo le iniziali», come nel profilo',
                JSON.stringify(ordinePrimo) === JSON.stringify(['full', 'first', 'initials']), [JSON.stringify(ordinePrimo)]);
            check(email + ': le tre opzioni sono vicine: pallini non più alti di 20 px, spazi non più di 8',
                modulo.pallini.length === 3 && modulo.pallini.every((h) => h <= 20) && modulo.spazi.every((s) => s <= 8),
                [JSON.stringify({ pallini: modulo.pallini, spazi: modulo.spazi })]);
            check(email + ': le frasi corte',
                modulo.aiuti.includes('Tutor e admin vedono sempre nome e cognome. Scelta modificabile dal profilo.')
                && (!conPassword || modulo.aiuti.includes('Minimo 8 caratteri: almeno una lettera e un numero.')),
                [JSON.stringify(modulo.aiuti)]);
            await pp.goto(BASE + '/profilo');
            check(email + ': le altre pagine riportano al primo accesso', dove() === '/primo-accesso', ['pagina: ' + dove()]);

            if (conPassword) {
                await pp.fill('#current_password', PASS);
                await pp.fill('#new_password', 'NuovaPass9');
                await pp.fill('#confirm_password', 'NuovaPass9');
            }
            await pp.click('form.auth-form button[type="submit"]');
            await pp.waitForLoadState('load');
            check(email + ': «Continua» porta avanti', dove() !== '/primo-accesso', ['pagina: ' + dove()]);

            await ctxPrimo.clearCookies();
            await accedi(conPassword ? 'NuovaPass9' : PASS);
            const sulVideo = dove() === '/benvenuto';
            const haSaluto = await pp.locator('.saluto').count() === 1;
            check(email + ': rientrando, saluto se il video non c\'è, pagina del video se c\'è',
                sulVideo ? !haSaluto : (dove() === '/' && haSaluto), ['pagina: ' + dove() + ', saluto: ' + haSaluto]);
            await ctxPrimo.close();
        }

        // Il tutor (07/10): carica il proprio benvenuto dalla pagina di
        // modifica del corso, e nel profilo ha il campo dell'email per gli
        // studenti. Due pagine che l'admin vede diverse.
        const ctxTutor = await browser.newContext({ viewport: { width: larghezza, height: altezza } });
        const tp = await ctxTutor.newPage();
        await tp.goto(BASE + '/login');
        await tp.fill('input[name="email"]', 'tutor1@test.it');
        await tp.fill('input[name="password"]', PASS);
        await tp.click('form.auth-form button[type="submit"]');
        await tp.waitForLoadState('load');

        check('il tutor non riceve il saluto, che è per gli studenti',
            await tp.locator('.saluto').count() === 0);

        // Il tutor legge «L'esperto risponde» come uno studente (09/10).
        await esamina(tp, '/domande-e-risposte', 'L\'esperto risponde (tutor)', BERSAGLIO_MINIMO, daTelefono);
        await esamina(tp, '/bacheca?corso=' + corso, 'Bacheca (tutor)', BERSAGLIO_MINIMO, daTelefono);
        await esamina(tp, '/bacheca/proposte?corso=' + corso, 'Proposte da valutare (tutor)', BERSAGLIO_MINIMO, daTelefono);

        // «Informazioni su Pistacchio LMS» (18/10, variante A): logo, versione
        // e collegamento al centro della pagina, anche in verticale.
        if (!daTelefono) {
            const cx = await browser.newContext({ viewport: { width: 1366, height: 760 } });
            const ap = await cx.newPage();
            await ap.goto(BASE + '/login');
            await ap.fill('input[name="email"]', ADMIN);
            await ap.fill('input[name="password"]', PASS);
            await ap.click('form.auth-form button[type="submit"]');
            await ap.waitForLoadState('load');
            for (const [w, h] of [[1366, 760], [1280, 1000]]) {
                await ap.setViewportSize({ width: w, height: h });
                await ap.goto(BASE + '/admin/settings/informazioni');
                const m = await ap.evaluate(() => {
                    const b = document.querySelector('.informazioni-blocco').getBoundingClientRect();
                    const main = document.querySelector('.main-content').getBoundingClientRect();
                    return {
                        dy: Math.round((b.top + b.bottom) / 2 - innerHeight / 2),
                        // Il centro dello spazio a destra della barra laterale.
                        dx: Math.round((b.left + b.right) / 2 - (main.left + innerWidth) / 2),
                        scorre: document.documentElement.scrollHeight > innerHeight + 1,
                        logo: Math.round(document.querySelector('.informazioni-logo').getBoundingClientRect().width),
                    };
                });
                check('Informazioni a ' + w + '×' + h + ': il blocco, con i dati, è al centro in verticale e in orizzontale, senza scorrere; il logo è largo 169 px',
                    Math.abs(m.dy) <= 12 && Math.abs(m.dx) <= 2 && !m.scorre && m.logo === 169, [JSON.stringify(m)]);
            }
            // I dati (18/10): su computer in una riga, etichetta e valore
            // affiancati (variante B); sul telefono uno sotto l'altro, con
            // l'etichetta sopra il valore (variante A). Separatori grigio chiaro.
            const disposizione = () => ap.evaluate(() => {
                const d = [...document.querySelectorAll('.informazioni-dato')];
                const e = d.map((x) => [x.querySelector('.informazioni-etichetta').getBoundingClientRect(), x.querySelector('.informazioni-valore').getBoundingClientRect()]);
                const cs = d.map((x) => getComputedStyle(x));
                const riquadro = getComputedStyle(document.querySelector('.informazioni-dati'));
                return {
                    inRiga: d.length === 3 && new Set(d.map((x) => Math.round(x.getBoundingClientRect().top))).size === 1,
                    affiancati: e.every(([a, b]) => Math.abs(a.top + a.height / 2 - (b.top + b.height / 2)) <= 3 && b.left > a.right),
                    sopra: e.every(([a, b]) => b.top >= a.bottom - 1),
                    separatori: [cs[1].borderLeftWidth, cs[1].borderLeftColor, cs[1].borderTopWidth, cs[1].borderTopColor],
                    fondo: riquadro.backgroundColor,
                    bordo: getComputedStyle(document.documentElement).getPropertyValue('--color-border').trim(),
                    scorre: document.documentElement.scrollWidth > innerWidth,
                };
            });
            await ap.setViewportSize({ width: 1366, height: 760 });
            await ap.goto(BASE + '/admin/settings/informazioni');
            const pc = await disposizione();
            check('Informazioni su computer: i tre dati in una riga, etichetta e valore affiancati, separatori verticali grigio chiaro, fondo bianco',
                pc.inRiga && pc.affiancati && pc.separatori[0] === '1px' && pc.separatori[2] === '0px' && pc.fondo === 'rgb(255, 255, 255)', [JSON.stringify(pc)]);
            await ap.setViewportSize({ width: 390, height: 844 });
            await ap.goto(BASE + '/admin/settings/informazioni');
            const tel = await disposizione();
            check('Informazioni sul telefono: i dati uno sotto l\'altro, etichetta sopra il valore, separatori orizzontali, niente scorrimento',
                !tel.inRiga && tel.sopra && tel.separatori[0] === '0px' && tel.separatori[2] === '1px' && !tel.scorre, [JSON.stringify(tel)]);
            await cx.close();
        }

        // Il colore delle categorie (17/10, strada 2): una tavolozza continua
        // con un cerchio da spostare, e l'intensità. Aperta, su computer e a
        // 320 px: disegnata, dentro lo schermo, con le etichette; un clic
        // nella zona chiara sceglie un colore chiaro, il pulsante e
        // l'anteprima lo seguono e il titolo dell'anteprima si legge; le
        // frecce lo spostano; l'intensità a zero dà un grigio. Salvando, la
        // categoria ha quel colore. Una volta sola, non a ogni giro.
        if (!daTelefono) {
            for (const [w, h] of [[1280, 900], [320, 568]]) {
                const cx = await browser.newContext({ viewport: { width: w, height: h } });
                const ap = await cx.newPage();
                await ap.goto(BASE + '/login');
                await ap.fill('input[name="email"]', ADMIN);
                await ap.fill('input[name="password"]', PASS);
                await ap.click('form.auth-form button[type="submit"]');
                await ap.waitForLoadState('load');
                await ap.goto(BASE + '/admin/courses/' + corso + '/edit');
                await ap.click('#bacheca .bacheca-cat-admin details > summary');
                const sel = '#bacheca .bacheca-cat-admin li:first-child .bacheca-colore';
                const prima = await ap.inputValue(sel + ' input[type="color"]');
                await ap.click(sel + ' > summary');
                // Si disegna all'apertura (l'evento `toggle` arriva subito dopo).
                await ap.waitForFunction((s) => {
                    const cv = document.querySelector(s + ' canvas');
                    return cv.getContext('2d').getImageData(Math.floor(cv.width / 2), Math.floor(cv.height / 2), 1, 1).data[3] === 255;
                }, sel, { timeout: 3000 }).catch(() => {});
                await ap.evaluate((s) => document.querySelector(s + ' .bacheca-colore-pannello').scrollIntoView({ block: 'center' }), sel);
                const t = await ap.evaluate(([s, minimo]) => {
                    const c = document.querySelector(s);
                    const p = c.querySelector('.bacheca-colore-pannello').getBoundingClientRect();
                    const area = c.querySelector('.bacheca-colore-area').getBoundingClientRect();
                    const cv = c.querySelector('canvas');
                    const px = cv.getContext('2d').getImageData(Math.floor(cv.width / 2), Math.floor(cv.height / 2), 1, 1).data;
                    return {
                        pronta: c.classList.contains('pronta'),
                        disegnata: px[3] === 255,
                        dentro: p.left >= 0 && p.right <= innerWidth && p.top >= 0 && p.bottom <= innerHeight,
                        area: [Math.round(area.width), Math.round(area.height)],
                        scorre: document.documentElement.scrollWidth > innerWidth,
                    };
                }, [sel, BERSAGLIO_MINIMO]);
                check('colore a ' + w + ' px: la tavolozza si apre disegnata, dentro lo schermo, senza scorrimento orizzontale',
                    t.pronta && t.disegnata && t.dentro && !t.scorre && t.area[0] >= 200 && t.area[1] >= 100, [JSON.stringify(t)]);
                const r = await ap.evaluate(raccogli, BERSAGLIO_MINIMO);
                check('colore a ' + w + ' px, aperta: campi con etichetta, contrasto, bersagli, identificativi non ripetuti',
                    r.senzaEtichetta.length === 0 && r.idRipetuti.length === 0 && r.contrasti.length === 0 && r.bersagli.length === 0,
                    [...r.senzaEtichetta, ...r.idRipetuti, ...r.contrasti, ...r.bersagli]);

                const stato = () => ap.evaluate((s) => {
                    const c = document.querySelector(s);
                    const lum = (rgb) => rgb.match(/\d+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
                        .reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
                    const tit = c.querySelector('.bacheca-colore-anteprima-titolo');
                    const fondo = getComputedStyle(c.querySelector('.bacheca-colore-anteprima-riquadro')).backgroundColor;
                    const [l1, l2] = [lum(getComputedStyle(tit).color), lum(fondo)];
                    return {
                        valore: c.querySelector('input[type="color"]').value.toUpperCase(),
                        campione: getComputedStyle(c.querySelector('.bacheca-colore-campione')).backgroundColor,
                        filo: getComputedStyle(c.querySelector('.bacheca-colore-anteprima-riquadro')).borderTopColor,
                        contrasto: Math.round((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05) * 100) / 100,
                        aperta: c.open,
                    };
                }, sel);
                const rgb = (hex) => 'rgb(' + [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(', ') + ')';
                const box = await ap.locator(sel + ' .bacheca-colore-area').boundingBox();
                await ap.mouse.click(box.x + box.width * 0.28, box.y + box.height * 0.08);
                const chiaro = await stato();
                const luce = [1, 3, 5].map((i) => parseInt(chiaro.valore.slice(i, i + 2), 16));
                check('colore a ' + w + ' px: un clic in alto, sui gialli, sceglie un giallo chiaro; pulsante e filo dell\'anteprima lo seguono, il titolo si legge',
                    chiaro.valore !== prima.toUpperCase() && luce[0] > 200 && luce[1] > 180 && luce[2] < luce[1]
                        && chiaro.campione === rgb(chiaro.valore) && chiaro.filo === rgb(chiaro.valore) && chiaro.contrasto >= 4.5,
                    [JSON.stringify(chiaro)]);
                await ap.focus(sel + ' .bacheca-colore-area');
                await ap.keyboard.press('ArrowRight');
                await ap.keyboard.press('ArrowRight');
                await ap.keyboard.press('ArrowDown');
                const frecce = await stato();
                check('colore a ' + w + ' px: con le frecce il colore cambia', frecce.valore !== chiaro.valore && frecce.aperta, [JSON.stringify(frecce)]);
                await ap.fill(sel + ' .bacheca-colore-intensita input', '0');
                await ap.dispatchEvent(sel + ' .bacheca-colore-intensita input', 'input');
                const grigio = await stato();
                const g = [1, 3, 5].map((i) => parseInt(grigio.valore.slice(i, i + 2), 16));
                check('colore a ' + w + ' px: con l\'intensità a zero il colore è un grigio',
                    Math.max(...g) - Math.min(...g) <= 3, [JSON.stringify(grigio)]);
                if (w === 1280) {
                    await ap.mouse.click(box.x + box.width * 0.28, box.y + box.height * 0.08);
                    await ap.fill(sel + ' .bacheca-colore-intensita input', '100');
                    await ap.dispatchEvent(sel + ' .bacheca-colore-intensita input', 'input');
                    await ap.mouse.click(box.x + box.width * 0.28, box.y + box.height * 0.08);
                    const scelto = (await stato()).valore;
                    const titoloAnteprima = await ap.evaluate((s) => document.querySelector(s + ' .bacheca-colore-anteprima-riquadro').style.getPropertyValue('--cat-titolo'), sel);
                    // Esc chiude la tavolozza, che copre il resto del modulo.
                    await ap.focus(sel + ' .bacheca-colore-area');
                    await ap.keyboard.press('Escape');
                    const chiusa = await ap.evaluate((s) => !document.querySelector(s).open, sel);
                    check('colore: Esc chiude la tavolozza', chiusa);
                    await Promise.all([ap.waitForNavigation(), ap.click('#bacheca .bacheca-cat-admin li:first-child form.form button.btn-primary')]);
                    await ap.goto(BASE + '/bacheca?corso=' + corso);
                    const riq = await ap.evaluate(() => {
                        const r = document.querySelector('.bacheca-riquadro');
                        return [r.style.getPropertyValue('--cat'), r.style.getPropertyValue('--cat-titolo')];
                    });
                    check('colore: salvato, la categoria ha il colore scelto nella tavolozza (' + scelto + '), e il titolo dell\'anteprima è quello vero',
                        riq[0] === scelto && riq[1] === titoloAnteprima, [JSON.stringify(riq), titoloAnteprima]);
                }
                await cx.close();
            }
        }

        // Il tutor arriva alla pagina dei suoi gruppi (12/10): non ne e'
        // membro, e il profilo non li elenca. Dall'elenco Gruppi, in ogni
        // riga «Vai al gruppo»; da Modifica gruppo, in alto. Si segue il
        // collegamento: la pagina si apre, ed e' quella del gruppo giusto,
        // con i comandi di chi modera.
        await esamina(tp, '/admin/groups', 'Gruppi (tutor)', BERSAGLIO_MINIMO, daTelefono);
        const righeGruppi = await tp.evaluate(() => [...document.querySelectorAll('.data-table tbody tr')].map((tr) => ({
            gestione: (tr.querySelector('a.group-name') || {}).getAttribute ? tr.querySelector('a.group-name').getAttribute('href') : null,
            pagina: [...tr.querySelectorAll('.row-actions a')].filter((a) => a.textContent.trim() === 'Vai al gruppo')
                .map((a) => a.getAttribute('href'))[0] || null,
        })));
        check('nell\'elenco Gruppi del tutor ogni riga porta anche alla pagina del gruppo',
            righeGruppi.length > 0 && righeGruppi.every((r) => r.pagina !== null
                && r.gestione === r.pagina.replace('/gruppi/', '/admin/groups/') + '/edit'),
            [JSON.stringify(righeGruppi)]);
        await esamina(tp, '/admin/groups/' + seme.gruppo_cerchio + '/edit', 'Modifica gruppo (tutor)', BERSAGLIO_MINIMO, daTelefono);
        const daModifica = await tp.evaluate(() => {
            const a = [...document.querySelectorAll('.page-header a')].find((x) => x.textContent.trim() === 'vai al gruppo');
            return a ? a.getAttribute('href') : null;
        });
        let paginaTutor = null;
        if (daModifica !== null) {
            await tp.goto(BASE + daModifica);
            paginaTutor = await tp.evaluate(() => ({
                titolo: document.querySelector('h1').textContent.trim(),
                modera: !!document.querySelector('section.giro form[action$="/domanda"]'),
            }));
        }
        check('da Modifica gruppo il tutor apre la pagina del gruppo, dove pone la domanda',
            daModifica === '/gruppi/' + seme.gruppo_cerchio && paginaTutor !== null
                && paginaTutor.titolo === 'Gruppo [prova-permessi] cerchio' && paginaTutor.modera,
            [JSON.stringify({ daModifica, paginaTutor })]);

        await esamina(tp, '/profilo', 'Profilo (tutor)', BERSAGLIO_MINIMO, daTelefono);
        check('nel profilo del tutor c\'è il campo «Email per gli studenti»',
            await tp.locator('input#contact_email').count() === 1);

        await esamina(tp, '/admin/courses/' + corso + '/edit', 'Modifica corso (tutor)', BERSAGLIO_MINIMO, daTelefono);
        const blocchi = await tp.locator('#benvenuti .tutor-benvenuto-admin').count();
        check('nella pagina del corso il tutor vede solo il proprio benvenuto', blocchi === 1, ['blocchi: ' + blocchi]);
        // Il collegamento a TurboScribe per trascrivere l'audio (09/10, Elena):
        // in una scheda nuova, senza dire da dove si arriva.
        const turbo = await tp.locator('#benvenuti a[href="https://turboscribe.ai/it/"][target="_blank"][rel~="noopener"][rel~="noreferrer"]').count();
        check('sotto il testo del benvenuto, il collegamento a TurboScribe in una scheda nuova', turbo >= 1, ['trovati: ' + turbo]);

        await ctxTutor.close();
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
        await giroSenzaMouse(browser);
        await giroBenvenuto(browser);
    } finally {
        await browser.close();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
