/**
 * Gli errori della registrazione, tutti insieme e ciascuno sotto il suo
 * campo (24/11, Alessandro: «i messaggi devono essere brevi,
 * sufficientemente piccoli ma leggibili, di colore rosso»). Prima se ne
 * vedeva uno solo, in cima: chi sbagliava email e password lo scopriva in
 * due giri.
 *
 * COSA VERIFICA:
 *
 *   1. con nome vuoto, email sbagliata, password corta e conferma diversa
 *      compaiono quattro errori, ciascuno nel posto del suo campo (sotto il
 *      campo; per la password dopo la regola, che resta subito sotto il
 *      campo), e nessun avviso in cima;
 *   2. gli errori sono nella misura delle note piccole e nel rosso degli
 *      errori, letti dal foglio di stile;
 *   3. nome, cognome ed email scritti restano nei campi; le password no;
 *   4. a 320 px la pagina sta nello schermo;
 *   5. con i dati giusti la registrazione va avanti come prima, a
 *      «Controlla la posta» (l'account di prova si toglie alla fine).
 *
 * Esecuzione:  node tests/registrazione_errori.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const RADICE = path.join(__dirname, '..');
const EMAIL = 'registrazione.errori@prova-registrazione.invalid';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

function togli() {
    execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' App\\Core\\Database::connection()->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', '--', EMAIL],
    { cwd: RADICE, encoding: 'utf8' });
}

async function sbagliata(page) {
    await page.goto(BASE + '/register');
    // Senza i controlli del browser: si prova quello che fa il server.
    await page.evaluate(() => document.querySelectorAll('form').forEach((f) => { f.noValidate = true; }));
    await page.fill('input[name="first_name"]', '');
    await page.fill('input[name="last_name"]', 'Rossi');
    await page.fill('input[name="email"]', 'mario@');
    await page.fill('input[name="password"]', 'abc');
    await page.fill('input[name="password_confirm"]', 'abd');
    await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
}

(async () => {
    togli();
    const browser = await chromium.launch();
    try {
        let page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
        await sbagliata(page);
        const r = await page.evaluate(() => {
            // Il campo a cui ogni errore sta sotto: il campo piu' vicino che
            // lo precede nello stesso contenitore, saltando la regola.
            const campoDi = (e) => {
                let x = e.previousElementSibling;
                while (x && x.classList.contains('form-hint')) x = x.previousElementSibling;
                return x && x.matches('input') ? x.name : null;
            };
            const r = {
                errori: [...document.querySelectorAll('.campo-errore')].map((e) => {
                    const c = getComputedStyle(e);
                    return { campo: campoDi(e), testo: e.textContent.trim(), misura: c.fontSize, colore: c.color };
                }),
                rem: parseFloat(getComputedStyle(document.documentElement).fontSize),
                danger: getComputedStyle(document.documentElement).getPropertyValue('--color-danger').trim(),
                inCima: [...document.querySelectorAll('.alert-error')].map((a) => a.textContent.trim()),
                regolaSotto: document.querySelector('input[name="password"]').nextElementSibling.className,
                valori: Object.fromEntries([...document.querySelectorAll('form input:not([type=hidden])')].map((i) => [i.name, i.value])),
            };
            return r;
        });
        const campi = r.errori.map((e) => e.campo + ': ' + e.testo);
        check('quattro errori insieme, ciascuno sotto il suo campo', JSON.stringify(campi) === JSON.stringify([
            'first_name: Il nome è obbligatorio.',
            'email: Indirizzo email non valido.',
            'password: La password deve avere almeno 8 caratteri.',
            'password_confirm: Le due password non coincidono.',
        ]), campi);
        check('la regola resta subito sotto il campo della password', r.regolaSotto === 'form-hint regola-password', [r.regolaSotto]);
        check('nessun avviso in cima', r.inCima.length === 0, r.inCima);
        const rosso = (() => { const h = r.danger.replace('#', ''); return `rgb(${parseInt(h.slice(0, 2), 16)}, ${parseInt(h.slice(2, 4), 16)}, ${parseInt(h.slice(4, 6), 16)})`; })();
        check('gli errori piccoli (le note piccole, 0,8 rem) e rossi (il rosso degli errori)',
            r.errori.length > 0 && r.errori.every((e) => Math.abs(parseFloat(e.misura) - 0.8 * r.rem) < 0.05 && e.colore === rosso),
            r.errori.map((e) => e.misura + ' ' + e.colore + ' (atteso ' + 0.8 * r.rem + 'px ' + rosso + ')'));
        check('nome, cognome ed email restano; le password no',
            r.valori.first_name === '' && r.valori.last_name === 'Rossi' && r.valori.email === 'mario@' && r.valori.password === '' && r.valori.password_confirm === '',
            [JSON.stringify(r.valori)]);

        page = await (await browser.newContext({ viewport: { width: 320, height: 640 } })).newPage();
        await sbagliata(page);
        const sfora = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        check('a 320 px, con gli errori, la pagina sta nello schermo', sfora <= 0, ['sfora di ' + sfora + ' px']);

        await page.goto(BASE + '/register');
        await page.fill('input[name="first_name"]', 'Prova');
        await page.fill('input[name="last_name"]', 'Registrazione');
        await page.fill('input[name="email"]', EMAIL);
        await page.fill('input[name="password"]', 'Giusta1!Lunga');
        await page.fill('input[name="password_confirm"]', 'Giusta1!Lunga');
        await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
        check('con i dati giusti si arriva a «Controlla la posta»', new URL(page.url()).pathname === '/register/verifica-inviata', [page.url()]);
    } finally {
        await browser.close();
        togli();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
