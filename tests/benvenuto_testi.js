/**
 * I testi del video di benvenuto nelle Impostazioni (25/11, Alessandro):
 * senza «una volta sola», nel riquadro dell'indice e nel sottotitolo della
 * pagina.
 *
 * Esecuzione:  node tests/benvenuto_testi.js   (server di sviluppo acceso)
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

        await page.goto(BASE + '/admin/settings');
        const riquadro = await page.evaluate(() => {
            const a = document.querySelector('main a[href="/admin/settings/benvenuto"]');
            return a ? a.textContent.replace(/\s+/g, ' ').trim() : null;
        });
        check('Impostazioni: il riquadro «Video di benvenuto» senza «una volta sola»',
            riquadro !== null && riquadro.includes('Il video che uno studente vede al primo accesso.') && !riquadro.includes('una volta sola'),
            [String(riquadro)]);

        await page.goto(BASE + '/admin/settings/benvenuto');
        const sotto = await page.evaluate(() => {
            const p = document.querySelector('.page-header .page-subtitle');
            return p ? p.textContent.replace(/\s+/g, ' ').trim() : null;
        });
        check('Video di benvenuto: il sottotitolo senza «una volta sola»',
            sotto === 'Il video che uno studente vede al primo accesso. Lo si può rivedere dal proprio profilo.', [String(sotto)]);
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
