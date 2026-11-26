/**
 * «Tre parole» (26/11, Alessandro): il sottotitolo dice che le parole piu'
 * grandi sono le piu' scritte, e il riquadro del modulo non lo ripete piu'
 * sopra la nuvola; nella vista «Nominative» non c'e' piu' «Chi le ha
 * scritte» sopra la tabella.
 *
 * Esecuzione:  node tests/tre_parole_testi.js   (server di sviluppo acceso,
 * con le tre parole del gruppo Giallo nel modulo 1 del corso 1)
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
        dettagli.slice(0, 6).forEach((d) => console.log('         · ' + d));
    }
}

(async () => {
    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext()).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        await page.goto(BASE + '/tre-parole?corso=1&modulo=1&gruppo=1');
        const r = await page.evaluate(() => ({
            sottotitolo: (document.querySelector('.page-header .page-subtitle')?.textContent || '').replace(/\s+/g, ' ').trim(),
            nuvola: !!document.querySelector('.tp-nuvola'),
            ripete: /più grandi le più scritte/.test(document.querySelector('main')?.innerText || ''),
        }));
        check('il sottotitolo', r.sottotitolo === 'Le parole degli studenti alla fine di ogni modulo. Le più grandi sono le più scritte.', [r.sottotitolo]);
        check('c\'è la nuvola, senza la scritta sopra', r.nuvola && !r.ripete, [JSON.stringify(r)]);

        await page.goto(BASE + '/tre-parole?corso=1&modulo=1&gruppo=1&vista=nomi');
        const n = await page.evaluate(() => ({
            tabella: !!document.querySelector('main .data-table'),
            titoletto: /Chi le ha scritte/.test(document.querySelector('main')?.innerText || ''),
        }));
        check('vista «Nominative»: c\'è la tabella, senza «Chi le ha scritte»', n.tabella && !n.titoletto, [JSON.stringify(n)]);
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
