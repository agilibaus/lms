/**
 * La frase su chi vede la foto, nel profilo (25/11, Alessandro): prima del
 * riquadro «Immagine», non piu' dentro; allo studente e al tutor, ciascuno
 * la sua; allo staff nessuna, perche' non e' mai membro di un gruppo e la
 * frase («compariranno nella pagina dei tuoi gruppi») per lui non era vera.
 *
 * Esecuzione:  node tests/profilo_nota.js   (server di sviluppo acceso)
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

const ATTESE = {
    'stud@test.it': 'La foto e la presentazione sono facoltative. Compariranno nella pagina dei tuoi gruppi, dove le vedono i compagni di gruppo, il tutor e l\'Amministratore.',
    'tutor1@test.it': 'La foto è obbligatoria, e si può sostituire ma non togliere: compare nella pagina dei tuoi gruppi e nel tuo benvenuto in cima ai corsi, dove la vedono i tuoi studenti e l\'Amministratore. La presentazione è facoltativa.',
    'assist@test.it': null,
    'admin@test.it': null,
};

(async () => {
    const browser = await chromium.launch();
    try {
        for (const [email, attesa] of Object.entries(ATTESE)) {
            const page = await (await browser.newContext()).newPage();
            await page.goto(BASE + '/login');
            await page.fill('input[name="email"]', email);
            await page.fill('input[name="password"]', PASSWORD);
            await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
            await page.goto(BASE + '/profilo');
            const r = await page.evaluate(() => {
                const riquadro = [...document.querySelectorAll('section.card')].find((c) => c.querySelector('h2')?.textContent.trim() === 'Immagine');
                const prima = riquadro?.previousElementSibling;
                const testo = (e) => e.textContent.replace(/\s+/g, ' ').trim();
                return {
                    prima: prima && prima.matches('p.form-hint') ? testo(prima) : null,
                    // Dentro il riquadro restano solo le note sul file, non chi la vede.
                    dentro: riquadro ? [...riquadro.querySelectorAll('.form-hint')].map(testo).filter((t) => /pagina dei tuoi gruppi/.test(t)) : ['riquadro mancante'],
                };
            });
            const nome = email.split('@')[0];
            check(nome + ': ' + (attesa === null ? 'nessuna frase prima del riquadro «Immagine»' : 'la sua frase prima del riquadro «Immagine»'), r.prima === attesa, [JSON.stringify(r.prima)]);
            check(nome + ': dentro il riquadro la frase non c\'e\' piu\'', r.dentro.length === 0, r.dentro);
            await page.close();
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
