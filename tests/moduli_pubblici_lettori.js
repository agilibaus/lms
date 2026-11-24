/**
 * I moduli delle pagine pubbliche e i lettori di schermo (24/11,
 * Alessandro): gli errori e la regola della password collegati ai campi a
 * cui si riferiscono, nella registrazione, nell'accesso e in «Nuova
 * password». «Password dimenticata» non ha errori da collegare: risponde
 * sempre con la stessa frase, per non dire chi e' registrato.
 *
 * Su ogni campo si legge cio' che un lettore di schermo annuncia: il testo
 * dei suoi `aria-describedby` (ogni id deve esistere) e `aria-invalid`.
 *
 *   1. registrazione, vuota: la password dice la regola, nessun campo e'
 *      segnato, il cursore e' sul nome;
 *   2. registrazione con quattro errori: i quattro campi sbagliati sono
 *      segnati e dicono il loro errore (la password anche la regola), il
 *      cognome giusto no; il cursore va sul primo da correggere;
 *   3. accesso con email o password sbagliate: i due campi sono segnati e
 *      dicono l'avviso; senza errori niente;
 *   4. «Nuova password»: la regola sulla nuova password; con la password
 *      corta l'errore va alla nuova password, con le due diverse alla
 *      conferma, e il cursore su quel campo.
 *
 * Esecuzione:  node tests/moduli_pubblici_lettori.js   (server di sviluppo
 * acceso, posta su file: MAIL_TRANSPORT=log)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const RADICE = path.join(__dirname, '..');
const POSTA = path.join(RADICE, 'storage', 'mail');
const EMAIL = 'lettori@prova-lettori.invalid';
const REGOLA = 'Minimo 8 caratteri, con almeno una lettera e una cifra.';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

function sql(azione) {
    const codice = 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();'
        + ' $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);'
        + ' $pdo->exec("DELETE FROM login_failures WHERE email LIKE \'%@prova-lettori.invalid\'");'
        + ' if ($argv[2] === "crea") { $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at, name_display) VALUES (?, ?, \'Prova\', \'Lettori\', \'studente\', 1, NOW(), NOW(), \'full\')")->execute([$argv[1], password_hash("Vecchia1!", PASSWORD_DEFAULT)]); }';
    execFileSync('php', ['-r', codice, '--', EMAIL, azione], { cwd: RADICE, encoding: 'utf8' });
}

/** Che cosa annuncia ogni campo: nome → [non valido?, descrizione]. Gli id che mancano si segnalano. */
async function annunci(page) {
    return page.evaluate(() => {
        const r = { campi: {}, idMancanti: [], cursore: document.activeElement ? document.activeElement.name || null : null };
        for (const i of document.querySelectorAll('form input:not([type=hidden])')) {
            const ids = (i.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            ids.filter((id) => !document.getElementById(id)).forEach((id) => r.idMancanti.push(i.name + ' → ' + id));
            r.campi[i.name] = [i.getAttribute('aria-invalid') === 'true', ids.map((id) => (document.getElementById(id) || {}).textContent?.trim()).filter(Boolean).join(' | ')];
        }
        return r;
    });
}

const mostra = (a) => Object.entries(a.campi).map(([n, [inv, d]]) => n + (inv ? ' (non valido)' : '') + ': ' + (d || '—')).concat(['cursore: ' + a.cursore], a.idMancanti.map((m) => 'id che manca: ' + m));

(async () => {
    sql('crea');
    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext()).newPage();

        console.log('\nRegistrazione');
        await page.goto(BASE + '/register');
        let a = await annunci(page);
        check('vuota: la password dice la regola, nessun campo segnato, il cursore sul nome',
            a.campi.password[1] === REGOLA && Object.values(a.campi).every(([inv]) => !inv)
            && Object.entries(a.campi).every(([n, [, d]]) => n === 'password' || d === '') && a.cursore === 'first_name' && a.idMancanti.length === 0, mostra(a));

        await page.evaluate(() => document.querySelectorAll('form').forEach((f) => { f.noValidate = true; }));
        await page.fill('input[name="last_name"]', 'Rossi');
        await page.fill('input[name="email"]', 'mario@');
        await page.fill('input[name="password"]', 'abc');
        await page.fill('input[name="password_confirm"]', 'abd');
        await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
        a = await annunci(page);
        const attesi = {
            first_name: [true, 'Il nome è obbligatorio.'],
            last_name: [false, ''],
            email: [true, 'Indirizzo email non valido.'],
            password: [true, REGOLA + ' | La password deve avere almeno 8 caratteri.'],
            password_confirm: [true, 'Le due password non coincidono.'],
        };
        check('con quattro errori: ogni campo sbagliato e\' segnato e dice il suo errore, il cognome giusto no',
            JSON.stringify(a.campi) === JSON.stringify(attesi) && a.idMancanti.length === 0, mostra(a));
        check('il cursore va sul primo campo da correggere', a.cursore === 'first_name', ['cursore: ' + a.cursore]);
        // Con il nome giusto il primo da correggere e' l'email.
        await page.evaluate(() => document.querySelectorAll('form').forEach((f) => { f.noValidate = true; }));
        await page.fill('input[name="first_name"]', 'Mario');
        await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
        a = await annunci(page);
        check('con il nome giusto il cursore va sull\'email', a.cursore === 'email' && !a.campi.first_name[0], mostra(a));

        console.log('\nAccesso');
        await page.goto(BASE + '/login');
        a = await annunci(page);
        check('senza errori: nessun campo segnato, niente da dire', Object.values(a.campi).every(([inv, d]) => !inv && d === ''), mostra(a));
        await page.fill('input[name="email"]', 'nessuno@prova-lettori.invalid');
        await page.fill('input[name="password"]', 'sbagliata1');
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
        a = await annunci(page);
        check('email o password sbagliate: i due campi sono segnati e dicono l\'avviso',
            ['email', 'password'].every((n) => a.campi[n][0] && a.campi[n][1] === 'Email o password non corretti.') && a.idMancanti.length === 0, mostra(a));

        console.log('\nNuova password');
        const inizio = Date.now() - 1000;
        await page.goto(BASE + '/password/dimenticata');
        await page.fill('input[name="email"]', EMAIL);
        await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
        const posta = fs.existsSync(POSTA) ? fs.readdirSync(POSTA).map((n) => path.join(POSTA, n)).filter((p) => fs.statSync(p).mtimeMs >= inizio).map((p) => fs.readFileSync(p, 'utf8')).filter((t) => t.includes(EMAIL)).join('\n') : '';
        const m = posta.match(/https?:\/\/\S+\/password\/reimposta\/[0-9a-f]+/);
        check('il link di recupero arriva', m !== null, ['nessuna email in storage/mail']);
        if (m) {
            const link = m[0].replace(/^https?:\/\/[^/]+/, BASE);
            await page.goto(link);
            a = await annunci(page);
            check('la nuova password dice la regola; nessun campo segnato; il cursore sulla nuova password',
                a.campi.password[1] === REGOLA && !a.campi.password[0] && !a.campi.password_confirm[0] && a.campi.password_confirm[1] === '' && a.cursore === 'password', mostra(a));

            const invia = async (uno, due) => {
                await page.evaluate(() => document.querySelectorAll('form').forEach((f) => { f.noValidate = true; }));
                await page.fill('input[name="password"]', uno);
                await page.fill('input[name="password_confirm"]', due);
                await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
                return annunci(page);
            };
            a = await invia('abc', 'abc');
            check('password corta: l\'errore va alla nuova password, segnata, con il cursore',
                a.campi.password[0] && a.campi.password[1] === REGOLA + ' | La password deve avere almeno 8 caratteri.'
                && !a.campi.password_confirm[0] && a.cursore === 'password' && a.idMancanti.length === 0, mostra(a));
            a = await invia('Buona1!Lunga', 'Altra1!Lunga');
            check('le due diverse: l\'errore va alla conferma, segnata, con il cursore',
                a.campi.password_confirm[0] && a.campi.password_confirm[1] === 'Le due password non coincidono.'
                && !a.campi.password[0] && a.campi.password[1] === REGOLA && a.cursore === 'password_confirm' && a.idMancanti.length === 0, mostra(a));
        }
    } finally {
        await browser.close();
        sql('togli');
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
