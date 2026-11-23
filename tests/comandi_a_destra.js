/**
 * I comandi nell'ultima colonna di una tabella stanno a destra (23/11,
 * Alessandro): prima nei report, poi «la stessa regola anche qui», in ogni
 * tabella di Pistacchio.
 *
 * Da studente, tutor e Amministratore si seguono i collegamenti di tutte le
 * pagine (un indirizzo per forma, come in `scala_testi.js`). In ogni tabella
 * visibile la colonna dei comandi e' l'ultima senza un'intestazione da
 * leggere (solo «Azioni» per i lettori di schermo): una data che e' anche un
 * collegamento, come «Ultimo tentativo», e' un dato e resta a sinistra. In
 * ogni riga, se la cella ha solo comandi (collegamenti e pulsanti), il
 * comando piu' a destra deve stare attaccato al bordo destro, al pixel.
 *
 * Le tabelle si misurano a 1600 px, dove sono tabelle e non schede.
 *
 * Esecuzione:  node tests/comandi_a_destra.js   (server di sviluppo acceso,
 * semina dei permessi fatta)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 20).forEach((d) => console.log('         · ' + d));
    }
}

const NON_SEGUIRE = /logout|\/join|\.csv|\.xlsx|\/download|\/scarica|\/file|\/immagine|\/materials\/\d+|\/pdf|\/calendario|\.ics|\/h5p|\/video|\/copertina|\/logo|i-tuoi-dati|mailto:|tel:/;
const forma = (u) => u.split('#')[0].replace(/\d+/g, 'N').replace(/([?&](da_id|torna|da|ordina|verso|giorni|pagina|cerca|q|concluse|gruppo|tutor|modulo)=)[^&]*/g, '$1x');

async function giro(browser, email) {
    const page = await (await browser.newContext({ viewport: { width: 1600, height: 900 } })).newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');

    const viste = new Set();
    const coda = ['/'];
    const storte = [];
    let tabelle = 0;

    while (coda.length > 0 && viste.size < 300) {
        const u = coda.shift();
        if (viste.has(forma(u))) {
            continue;
        }
        viste.add(forma(u));
        try {
            await page.goto(BASE + u, { waitUntil: 'domcontentloaded' });
        } catch (e) {
            continue;
        }
        if (!page.url().startsWith(BASE)) {
            continue;
        }
        const r = await page.evaluate(() => {
            const visibile = (e) => e.getClientRects().length > 0;
            const testo = (e) => e.textContent.replace(/\s+/g, ' ').trim();
            const risultato = { tabelle: 0, storte: [] };
            for (const t of document.querySelectorAll('table.data-table')) {
                if (!visibile(t)) {
                    continue;
                }
                const intestazioni = t.querySelectorAll('thead tr:last-child > th');
                const ultima = intestazioni[intestazioni.length - 1];
                if (!ultima) {
                    continue;
                }
                const leggibile = [...ultima.querySelectorAll('*'), ultima].some((e) => !e.closest('.sr-only')
                    && [...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== ''));
                if (leggibile) {
                    continue;
                }
                let misurata = false;
                for (const tr of t.querySelectorAll('tbody tr')) {
                    const cella = tr.lastElementChild;
                    if (!cella || tr.children.length < 2) {
                        continue;
                    }
                    const comandi = [...cella.querySelectorAll('a, button')].filter(visibile);
                    // Solo comandi: tolto il loro testo, nella cella non resta niente.
                    const resto = comandi.reduce((s, c) => s.replace(testo(c), ''), testo(cella)).trim();
                    if (comandi.length === 0 || resto !== '') {
                        continue;
                    }
                    misurata = true;
                    const bordo = cella.getBoundingClientRect().right - parseFloat(getComputedStyle(cella).paddingRight);
                    const destra = Math.max(...comandi.map((c) => c.getBoundingClientRect().right));
                    if (bordo - destra > 1) {
                        const titolo = t.closest('section, .card, main')?.querySelector('h2, h1');
                        risultato.storte.push((titolo ? '«' + testo(titolo).slice(0, 40) + '»' : 'tabella') + ': «' + comandi.map(testo).join(' ') + '» a ' + Math.round(bordo - destra) + ' px dal bordo');
                        break;
                    }
                }
                if (misurata) {
                    risultato.tabelle++;
                }
            }
            risultato.link = [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')).filter((h) => h && h.startsWith('/'));
            return risultato;
        });
        tabelle += r.tabelle;
        r.storte.forEach((s) => storte.push(forma(u) + ' ' + s));
        r.link.filter((l) => !NON_SEGUIRE.test(l)).forEach((l) => coda.push(l));
    }
    await page.close();

    return { pagine: viste.size, tabelle, storte: [...new Set(storte)] };
}

(async () => {
    const browser = await chromium.launch();
    try {
        for (const [ruolo, email] of [['studente', 'stud@test.it'], ['tutor', 'tutor1@test.it'], ['admin', 'admin@test.it']]) {
            const r = await giro(browser, email);
            console.log(`  --   ${ruolo}: ${r.pagine} pagine, ${r.tabelle} tabelle con i comandi in fondo`);
            check(`${ruolo}: ci sono tabelle con i comandi da misurare`, r.tabelle > 0);
            check(`${ruolo}: i comandi dell'ultima colonna sono a destra`, r.storte.length === 0, r.storte);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
