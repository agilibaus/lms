/**
 * Il recupero della password, da visitatore (24/11, ripasso del
 * visitatore). Fino ad allora i controlli aprivano «Nuova password» solo con
 * un link non valido: la pagina con il modulo non la vedeva nessuno.
 *
 * Con un account di prova creato qui e tolto alla fine:
 *
 *   1. «Password dimenticata?» manda l'email con il link (posta su file,
 *      `storage/mail`);
 *   2. il link apre «Nuova password», con il pulsante «Salva la password»
 *      (era «Salva e accedi», ma dopo il salvataggio non si entra: si torna
 *      all'accesso) e «Annulla»;
 *   3. salvata, si torna all'accesso con «Password aggiornata», e la nuova
 *      password fa entrare;
 *   4. lo stesso link, la seconda volta, non vale piu'.
 *
 * Esecuzione:  node tests/nuova_password.js   (server di sviluppo acceso,
 * posta su file: MAIL_TRANSPORT=log)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const RADICE = path.join(__dirname, '..');
const POSTA = path.join(RADICE, 'storage', 'mail');
const EMAIL = 'nuova.password@prova-recupero.invalid';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 6).forEach((d) => console.log('         · ' + d));
    }
}

function sql(azione) {
    const codice = 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();'
        + ' $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);'
        + ' if ($argv[2] === "crea") { $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Prova\', \'Recupero\', \'studente\', 1, NOW(), NOW())")->execute([$argv[1], password_hash("Vecchia1!", PASSWORD_DEFAULT)]); }';
    execFileSync('php', ['-r', codice, '--', EMAIL, azione], { cwd: RADICE, encoding: 'utf8' });
}

function linkDalla(dopo) {
    if (!fs.existsSync(POSTA)) {
        return null;
    }
    const file = fs.readdirSync(POSTA).map((n) => path.join(POSTA, n))
        .filter((p) => fs.statSync(p).mtimeMs >= dopo)
        .map((p) => fs.readFileSync(p, 'utf8'))
        .filter((t) => t.includes(EMAIL));
    const m = file.join('\n').match(/https?:\/\/\S+\/password\/reimposta\/[0-9a-f]+/);
    return m ? m[0].replace(/^https?:\/\/[^/]+/, BASE) : null;
}

(async () => {
    sql('crea');
    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext()).newPage();
        const inizio = Date.now() - 1000;

        await page.goto(BASE + '/password/dimenticata');
        await page.fill('input[name="email"]', EMAIL);
        await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
        const link = linkDalla(inizio);
        check('«Password dimenticata?» manda l\'email con il link', link !== null, ['nessuna email per ' + EMAIL + ' in storage/mail']);

        if (link !== null) {
            await page.goto(link);
            const pagina = await page.evaluate(() => ({
                h1: (document.querySelector('h1.auth-panel-title, main h1, h1') || {}).textContent?.trim(),
                pulsanti: [...document.querySelectorAll('form .btn')].map((b) => b.textContent.trim()),
            }));
            check('il link apre «Nuova password»', await page.title() === 'Nuova password · Pistacchio LMS', [await page.title()]);
            check('i pulsanti sono «Salva la password» e «Annulla»', JSON.stringify(pagina.pulsanti) === JSON.stringify(['Salva la password', 'Annulla']), [JSON.stringify(pagina.pulsanti)]);

            for (const campo of await page.$$('input[type="password"]')) {
                await campo.fill('Nuova1!Lunga');
            }
            await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
            const avviso = await page.evaluate(() => (document.querySelector('.alert') || {}).textContent?.trim() || '');
            check('salvata, si torna all\'accesso con «Password aggiornata»', new URL(page.url()).pathname === '/login' && avviso.startsWith('Password aggiornata'), [page.url(), avviso]);

            await page.fill('input[name="email"]', EMAIL);
            await page.fill('input[name="password"]', 'Nuova1!Lunga');
            await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
            check('la nuova password fa entrare', !['/login'].includes(new URL(page.url()).pathname), [page.url()]);

            const altro = await (await browser.newContext()).newPage();
            await altro.goto(link);
            const testo = await altro.evaluate(() => document.body.innerText);
            check('lo stesso link, la seconda volta, non vale piu\'', testo.includes('Questo link non è valido') && (await altro.$$('input[type="password"]')).length === 0, [testo.slice(0, 160)]);
        }
    } finally {
        await browser.close();
        sql('togli');
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
