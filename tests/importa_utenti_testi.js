/**
 * «Importa utenti» (27/11, Alessandro): i testi dei riquadri «Com'e' fatto il
 * file» e «Le password».
 *
 * Esecuzione:  node tests/importa_utenti_testi.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

const FILE = 'La prima riga contiene i nomi delle colonne. L\'ordine non conta, le maiuscole nemmeno. '
    + 'Il separatore può essere virgola o punto e virgola.';

const LE_PASSWORD = 'Le password le genera Pistacchio nel momento in cui manda l\'email, e chi accede deve '
    + 'cambiarla subito. Gli inviti non partono tutti insieme ma a scaglioni di 20, ogni quarto d\'ora. '
    + 'Fino a quando l\'invito non parte, l\'utente non ha una password e non può entrare: il conteggio '
    + 'in fondo alla pagina Utenti dice quanti ne mancano.';

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

        await page.goto(BASE + '/admin/users/importa');
        const testo = (titolo) => page.evaluate((t) => [...document.querySelectorAll('main section.card')]
            .filter((s) => (s.querySelector('h2')?.textContent || '').trim() === t)
            .map((s) => (s.querySelector('.riquadro-intro')?.textContent || '').replace(/\s+/g, ' ').trim()), titolo);
        const file = await testo('Com\'è fatto il file');
        check('«Com\'è fatto il file»: il testo del riquadro', file.length === 1 && file[0] === FILE, file);
        const testi = await testo('Le password');
        check('«Le password»: il testo del riquadro', testi.length === 1 && testi[0] === LE_PASSWORD, testi);
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
