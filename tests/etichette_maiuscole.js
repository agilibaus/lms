/**
 * Le etichette di stato cominciano con la maiuscola (23/11, Alessandro):
 * «Attiva», «Completato», «Assente», non «attiva».
 *
 * Da anonimo, studente, tutor e Amministratore si seguono i collegamenti di
 * tutte le pagine (un indirizzo per forma, come in `scala_testi.js`), e su
 * ognuna si legge ogni `.badge` visibile: se comincia con una lettera, la
 * lettera e' maiuscola. I numeri («3») e le date restano come sono.
 *
 * Esecuzione:  node tests/etichette_maiuscole.js   (server di sviluppo acceso,
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

// Gli stessi indirizzi che `scala_testi.js` non segue: file, uscite, azioni.
const NON_SEGUIRE = /logout|\/join|\.csv|\.xlsx|\/download|\/scarica|\/file|\/immagine|\/materials\/\d+|\/pdf|\/calendario|\.ics|\/h5p|\/video|\/copertina|\/logo|mailto:|tel:/;
const forma = (u) => u.split('#')[0].replace(/\d+/g, 'N').replace(/([?&](da_id|torna|da|ordina|verso|giorni|pagina|cerca|q|concluse|gruppo|tutor|modulo)=)[^&]*/g, '$1x');

async function giro(browser, email) {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    if (email !== null) {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');
    }
    const viste = new Set();
    const coda = ['/'];
    const minuscole = [];
    let etichette = 0;

    while (coda.length > 0 && viste.size < 250) {
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
            // Le sezioni chiuse (`details`) si aprono: le etichette dentro contano.
            document.querySelectorAll('details').forEach((d) => { d.open = true; });
            const badge = [...document.querySelectorAll('.badge')].filter((b) => b.getClientRects().length > 0);
            return {
                testi: badge.map((b) => b.textContent.replace(/\s+/g, ' ').trim()),
                link: [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')).filter((h) => h && h.startsWith('/')),
            };
        });
        etichette += r.testi.length;
        r.testi.filter((t) => /^\p{Ll}/u.test(t)).forEach((t) => minuscole.push(`${forma(u)}: «${t}»`));
        r.link.filter((l) => !NON_SEGUIRE.test(l)).forEach((l) => coda.push(l));
    }
    await page.close();

    return { pagine: viste.size, etichette, minuscole: [...new Set(minuscole)] };
}

(async () => {
    const browser = await chromium.launch();
    try {
        for (const [ruolo, email] of [['anonimo', null], ['studente', 'stud@test.it'], ['tutor', 'tutor1@test.it'], ['admin', 'admin@test.it']]) {
            const r = await giro(browser, email);
            console.log(`  --   ${ruolo}: ${r.pagine} pagine, ${r.etichette} etichette`);
            check(`${ruolo}: ogni etichetta comincia con la maiuscola`, r.minuscole.length === 0, r.minuscole);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
