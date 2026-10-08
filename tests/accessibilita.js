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
    // La sezione dei benvenuti dei tutor (07/10), con un benvenuto caricato.
    ['/admin/courses/' + SEMINATI.corso + '/edit', 'Modifica corso con benvenuto (semina)'],
    // Le domande in attesa, per chi risponde (07/10): la semina ne lascia una
    // per mondo, e l'admin le vede tutte con il tutor assegnato.
    ['/domande', 'Domande in attesa (semina)'],
    // E con una pubblicata aperta per correggerla, come quando si arriva dal
    // «Modifica» dell'archivio (08/10): chiusa, il suo modulo non si misura.
    ['/domande?apri=' + SEMINATI.domanda_pubblicata, 'Domande con una pubblicata aperta (semina)'],
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
    + '.tutor-benvenuto-contatti a, .qa-modifica a, .qa-comando';
const NELLE_FRASI = ':is(p, .alert, .form-hint, .lesson-content) a:not([class])';

/**
 * Le tre regole lette dalla pagina com'e' adesso. `conMouse` dice se il
 * dispositivo ha il passaggio del mouse: con il mouse i comandi sono neutri
 * a riposo, senza devono essere sottolineati.
 */
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
                const rg = document.createRange();
                rg.selectNodeContents(e);
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

    const fuoco = await fuocoInvisibile(page);
    check(nome + ': fuoco visibile su ogni comando', fuoco.length === 0, fuoco);

    const ritagliati = await pannelliRitagliati(page);
    check(nome + ': i pannelli che si aprono non vengono ritagliati', ritagliati.length === 0, ritagliati);

    const segni = await comeSiRiconoscono(page);
    check(nome + ': i collegamenti nelle frasi sono sottolineati e colorati', segni.frasi.length === 0, segni.frasi);
    check(nome + ': i comandi di una fila hanno il testo sulla stessa riga', segni.sfalsati.length === 0, segni.sfalsati);
    check(nome + ': i comandi di una fila usano lo stesso carattere', segni.caratteri.length === 0, segni.caratteri);
    check(nome + ': un modulo che segue un altro modulo ne sta staccato', segni.attaccati.length === 0, segni.attaccati);
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
        const esito = await page.evaluate(() => {
            const campo = document.querySelector('.quiz-open-answer');

            if (campo === null) {
                return null;
            }

            // Il primo degli id: nel profilo il campo e' descritto anche dal
            // testo di aiuto, e la lista intera non e' un id.
            const contatore = document.getElementById((campo.getAttribute('aria-describedby') || '').split(/\s+/)[0]);

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

// Il campo della presentazione nel profilo ha lo stesso contatore delle
// risposte aperte (06/10), e si guarda con lo stesso controllo.
EXTRA['Profilo'] = EXTRA['Quiz da svolgere'];

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

        const corso = semina().A.corso;
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
        // L'ingresso e' morbido (08/10, Elena: «appare un po' bruscamente»):
        // a 150 ms e' ancora invisibile, perche' aspetta che la pagina si sia
        // disegnata; a 450 ms sta ancora entrando. Si ferma l'animazione in
        // quei due istanti e si legge l'opacita', poi la si rimette dov'era.
        const ingresso = await page.evaluate(() => {
            const s = document.querySelector('.saluto');
            const a = s.getAnimations()[0];
            if (!a) return null;
            const dove = a.currentTime;
            a.pause();
            const opacita = (ms) => { a.currentTime = ms; return +parseFloat(getComputedStyle(s).opacity).toFixed(2); };
            const misure = { a150: opacita(150), a450: opacita(450) };
            a.currentTime = dove;
            a.play();
            return misure;
        });
        check('il saluto entra con calma: invisibile a 150 ms, ancora in arrivo a 450',
            ingresso !== null && ingresso.a150 === 0 && ingresso.a450 > 0 && ingresso.a450 < 0.75, [JSON.stringify(ingresso)]);

        const tempi = await page.evaluate(() => {
            const a = document.querySelector('.saluto').getAnimations()[0];
            return a ? { durata: a.effect.getTiming().duration, adesso: a.currentTime } : null;
        });
        check('il saluto dura 4 secondi (Elena)', tempi !== null && tempi.durata === 4000, [JSON.stringify(tempi)]);
        await page.waitForTimeout(Math.max(0, (tempi ? tempi.durata - tempi.adesso : 4000) + 300));
        const dopo = await saluto();
        check('alla fine il saluto è sparito', dopo !== null && dopo.visibile === 'hidden', [JSON.stringify(dopo)]);

        // Il profilo dello studente (07/10): nome e cognome separati, e la
        // scelta di come compare agli altri studenti, con l'anteprima.
        await esamina(page, '/profilo', 'Profilo (studente)', BERSAGLIO_MINIMO, daTelefono);
        const scelte = await page.locator('fieldset.scelta-nome input[name="name_display"]').count();
        check('nel profilo dello studente ci sono le tre scelte di come compare', scelte === 3, ['scelte: ' + scelte]);

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

        // Domande e risposte ripiegate in una riga (08/10): chiuse all'arrivo,
        // «Mostra» le apre e diventa «Nascondi»; una ricerca le apre da sola.
        // Aperte con la ricerca, l'archivio si misura come il resto: chiuso,
        // i controlli non lo vedrebbero.
        const qa = () => page.evaluate(() => {
            const d = document.querySelector('details.qa-apri');
            return d ? { aperta: d.open, comando: d.querySelector('.qa-comando').innerText.trim(),
                         conteggio: d.querySelector('.qa-conteggio').innerText.trim() } : null;
        });
        await page.goto(BASE + url);
        const chiusa = await qa();
        check('domande e risposte: chiuse all\'arrivo, con il numero e «Mostra»',
            chiusa !== null && !chiusa.aperta && chiusa.comando === 'Mostra' && /^\d+ domand[ae]$/.test(chiusa.conteggio),
            [JSON.stringify(chiusa)]);
        const altezzaRiga = () => page.evaluate(() => Math.round(document.querySelector('.qa-riga').getBoundingClientRect().height));
        const primaDiAprire = await altezzaRiga();
        await page.click('details.qa-apri > summary');
        const aperta = await qa();
        check('«Mostra» le apre e il comando diventa «Nascondi»', aperta.aperta && aperta.comando === 'Nascondi', [JSON.stringify(aperta)]);
        // La riga non cambia forma aprendola: «Nascondi», piu' lungo, la
        // faceva crescere sul telefono proprio mentre la si toccava (08/10).
        const dopoAverAperto = await altezzaRiga();
        check('aprendole, la riga non cambia altezza', primaDiAprire === dopoAverAperto,
            ['chiusa ' + primaDiAprire + ' px, aperta ' + dopoAverAperto + ' px']);
        await esamina(page, url + '?cerca=Domanda#domande', 'Corso con le domande aperte da una ricerca (studente)', BERSAGLIO_MINIMO, daTelefono);
        const cercata = await qa();
        check('una ricerca le apre da sola', cercata.aperta, [JSON.stringify(cercata)]);

        await ctx.close();

        // Lo studente che non ha mai visto il video di benvenuto (08/10): con
        // il video configurato finisce sulla pagina del video, senza saluto;
        // senza video riceve il saluto come tutti. Prima riceveva il saluto
        // solo chi il video l'aveva visto, e senza video nessuno lo vede mai:
        // gli studenti arrivati dopo la 0112 restavano senza (Elena).
        if (!daTelefono) {
            const ctxNuovo = await browser.newContext({ viewport: { width: larghezza, height: altezza } });
            const np = await ctxNuovo.newPage();
            await np.goto(BASE + '/login');
            await np.fill('input[name="email"]', 'nuovo@test.it');
            await np.fill('input[name="password"]', PASS);
            await np.click('form.auth-form button[type="submit"]');
            await np.waitForLoadState('load');
            const sulVideo = new URL(np.url()).pathname === '/benvenuto';
            const haSaluto = await np.locator('.saluto').count() === 1;
            check('lo studente che non ha visto il video: saluto se il video non c\'è, pagina del video se c\'è',
                sulVideo ? !haSaluto : haSaluto, ['pagina: ' + new URL(np.url()).pathname + ', saluto: ' + haSaluto]);
            await ctxNuovo.close();
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

        await esamina(tp, '/profilo', 'Profilo (tutor)', BERSAGLIO_MINIMO, daTelefono);
        check('nel profilo del tutor c\'è il campo «Email per gli studenti»',
            await tp.locator('input#contact_email').count() === 1);

        await esamina(tp, '/admin/courses/' + corso + '/edit', 'Modifica corso (tutor)', BERSAGLIO_MINIMO, daTelefono);
        const blocchi = await tp.locator('#benvenuti .tutor-benvenuto-admin').count();
        check('nella pagina del corso il tutor vede solo il proprio benvenuto', blocchi === 1, ['blocchi: ' + blocchi]);

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
