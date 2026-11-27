/**
 * «Nuovo utente» e «Modifica utente» (27/11, Alessandro): la frase sulla
 * password iniziale, sopra «Crea utente», che in «Modifica utente» non c'e';
 * e il testo del riquadro «Password temporanea» di «Modifica utente».
 *
 * Esecuzione:  node tests/utente_nuovo_testi.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

const FRASE = 'La password iniziale la genera la piattaforma e la invia per email all\'utente. '
    + 'Al primo accesso dovrà sceglierne una sua.';

const TEMPORANEA = 'La password la genera la piattaforma e la invia per email all\'utente: non compare '
    + 'qui e non la conosce nessun altro. Le eventuali sessioni aperte vengono chiuse, e al primo accesso '
    + 'dovrà sceglierne una sua. Se l\'invio dell\'email fallisce, la password attuale resta valida.';

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

        const frasi = async () => page.evaluate(() => [...document.querySelectorAll('main .form-hint')]
            .map((p) => p.textContent.replace(/\s+/g, ' ').trim())
            .filter((t) => /password iniziale/i.test(t)));

        await page.goto(BASE + '/admin/users/create');
        const nuovo = await frasi();
        check('«Nuovo utente»: la frase sulla password iniziale', nuovo.length === 1 && nuovo[0] === FRASE, nuovo);

        // Lo studente di prova: il riquadro «Password temporanea» c'e' per chi puo' stare nei gruppi.
        await page.goto(BASE + '/admin/users');
        const id = await page.evaluate(() => {
            const riga = [...document.querySelectorAll('main tr')].find((r) => r.textContent.includes('stud@test.it'));
            return riga?.querySelector('a[href^="/admin/users/"][href$="/edit"]')?.getAttribute('href') || null;
        });
        if (id) {
            await page.goto(BASE + id);
            const modifica = await frasi();
            check('«Modifica utente»: la frase non c\'è', modifica.length === 0, modifica);
            const temp = await page.evaluate(() => [...document.querySelectorAll('main section.card')]
                .filter((s) => (s.querySelector('h2')?.textContent || '').trim() === 'Password temporanea')
                .map((s) => (s.querySelector('.riquadro-intro')?.textContent || '').replace(/\s+/g, ' ').trim()));
            check('«Modifica utente»: il testo del riquadro «Password temporanea»', temp.length === 1 && temp[0] === TEMPORANEA, temp);
        } else {
            check('«Modifica utente»: trovato lo studente di prova da aprire', false);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
