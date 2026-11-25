/**
 * I sottotitoli delle pagine delle Impostazioni e i testi dei loro riquadri
 * nell'indice, decisi da Alessandro nel ripasso dell'Amministratore (dal
 * 25/11). Una riga per testo: chi cambia una frase cambia anche la riga.
 *
 * Esecuzione:  node tests/sottotitoli_impostazioni.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

const ATTESI = [
    ['/admin/settings/posta', 'Quello che salvi qui ha la precedenza sul file .env; se lasci un campo vuoto torna a valere il valore scritto nel file.'],
    ['/admin/settings/email', 'Un campo lasciato vuoto usa il testo predefinito in grigio. Come partono le email si imposta in Server di posta elettronica.'],
];

// Il testo dei riquadri nella pagina delle Impostazioni: [indirizzo del riquadro, testo].
const RIQUADRI = [
    ['/admin/settings/email', 'Nome dell’organizzazione, mittente e testo di ogni email inviata dalla piattaforma.'],
];

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

        for (const [indirizzo, attesa] of ATTESI) {
            await page.goto(BASE + indirizzo);
            const frase = await page.evaluate(() => (document.querySelector('.page-header .page-subtitle')?.textContent || '').replace(/\s+/g, ' ').trim());
            check(indirizzo + ': il sottotitolo', frase === attesa, [frase]);
        }

        await page.goto(BASE + '/admin/settings');
        for (const [indirizzo, attesa] of RIQUADRI) {
            const testo = await page.evaluate((h) => (document.querySelector('a.settings-card[href="' + h + '"] .settings-card-text')?.textContent || '')
                .replace(/\s+/g, ' ').trim(), indirizzo);
            check('Impostazioni: il testo del riquadro ' + indirizzo, testo === attesa, [testo]);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
