/**
 * Il supervisore gestisce gli studenti (15/11, Alessandro).
 *
 * Con `student.manage` entra in «Utenti» e vede solo gli studenti. Li crea
 * (il ruolo e' fisso: «Ruolo: Studente», senza menu), anche da un file; ne
 * modifica nome, cognome, email e stato; li mette nei gruppi dalla scheda;
 * manda la password temporanea; rimanda gli inviti; li elimina. Lo staff non
 * lo vede e non lo tocca, nessun ruolo cambia per mano sua, e l'elenco degli
 * utenti non lo scarica.
 *
 * I divieti si giudicano dal database, oltre che dallo stato: un invio
 * costruito a mano che cambiasse un tutor o un ruolo sarebbe il guasto.
 *
 * Esecuzione:  node tests/studenti_supervisore.js   (server di sviluppo acceso)
 *
 * Crea il supervisore «supervisore-studenti@test.it» e, con email
 * «…@studenti-sup.test», gli studenti e lo staff su cui prova i divieti: un
 * tutor e un altro supervisore suoi, non quelli delle altre suite, perche'
 * se un divieto cedesse la prova li cambierebbe o li eliminerebbe davvero.
 * Alla fine li toglie.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const SUP = 'supervisore-studenti@test.it';
const DOMINIO = '@studenti-sup.test';
const TUTOR = 'tutor1@test.it';
const STAFF_TUTOR = 'tutor' + DOMINIO;
const STAFF_SUP = 'supervisore' + DOMINIO;

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.forEach((d) => console.log('         · ' + d));
    }
}

function php(codice, ...argomenti) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();' + codice, '--', ...argomenti.map(String)], { cwd: RADICE, encoding: 'utf8' });
}

const riga = (sql, ...a) => JSON.parse(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo json_encode($s->fetch() ?: null);', sql, ...a));
const utente = (email) => riga('SELECT id, email, first_name, last_name, role, is_active, password_hash, invite_pending FROM users WHERE email = ?', email);
const utenteId = (id) => riga('SELECT id, email, first_name, last_name, role, is_active, password_hash FROM users WHERE id = ?', id);

function pulisci() {
    php('$pdo->prepare("DELETE FROM users WHERE email LIKE ? OR email = ?")->execute(["%" . $argv[1], $argv[2]]);', DOMINIO, SUP);
}

async function entra(browser, email) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/login')) {
        throw new Error('accesso non riuscito per ' + email);
    }
    return { ctx, page };
}

/** Una POST con il token di una pagina che l'utente apre; torna lo stato. */
async function posta(page, url, campi, paginaToken) {
    await page.goto(BASE + paginaToken);
    return page.evaluate(async ([url, campi]) => {
        const dati = new URLSearchParams();
        dati.append('_token', document.querySelector('input[name="_token"]').value);
        for (const [k, v] of campi) dati.append(k, v);
        const res = await fetch(url, { method: 'POST', body: dati, credentials: 'same-origin', redirect: 'manual' });
        return res.status;
    }, [url, campi]);
}

const stato = async (page, url) => (await page.goto(BASE + url)).status();

/** Lo stato di un GET senza aprirlo: un file scaricato non e' una pagina. */
const statoGet = (page, url) => page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin', redirect: 'manual' })).status, BASE + url);

const persona = (nome, email, ruolo, attivo = true) => [
    ['first_name', nome], ['last_name', 'Prova'], ['email', email], ['role', ruolo],
    ...(attivo ? [['is_active', '1']] : []),
];

(async () => {
    pulisci();
    const nuovo = (email, ruolo) => php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Prova\', \'Studenti\', ?, 1, NOW(), NOW())")->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT), $argv[3]]);', email, PASSWORD, ruolo);
    nuovo(SUP, 'supervisore');
    nuovo(STAFF_TUTOR, 'tutor');
    nuovo(STAFF_SUP, 'supervisore');
    const tutor = utente(STAFF_TUTOR);
    const gruppo = riga('SELECT id, name FROM `groups` ORDER BY id LIMIT 1');
    const browser = await chromium.launch();
    let file = null;

    try {
        const sup = await entra(browser, SUP);
        const adm = await entra(browser, 'admin@test.it');

        // --- 1. l'elenco ------------------------------------------------------
        const r = await stato(sup.page, '/admin/users');
        const elenco = await sup.page.evaluate(() => ({
            menu: [...document.querySelectorAll('nav a.nav-link')].map((a) => a.textContent.trim()),
            ruoli: [...new Set([...document.querySelectorAll('td[data-label="Ruolo"]')].map((t) => t.textContent.trim()))],
            importa: !!document.querySelector('a[href="/admin/users/importa"]'),
            scarica: !!document.querySelector('a[href="/admin/users/csv"]'),
            sottotitolo: document.querySelector('.page-subtitle')?.textContent.trim(),
        }));
        check('supervisore: «Utenti» nel menu, la pagina si apre, e ci sono solo studenti',
            r === 200 && elenco.menu.includes('Utenti') && elenco.ruoli.join() === 'Studente', [JSON.stringify(elenco)]);
        check('supervisore: «Importa da file» sì, «Scarica dati» no', elenco.importa && !elenco.scarica, [JSON.stringify(elenco)]);
        check('supervisore: l’elenco non si scarica, né CSV né XLSX (403)',
            await statoGet(sup.page, '/admin/users/csv') === 403 && await statoGet(sup.page, '/admin/users/xlsx') === 403);

        // --- 2. crea uno studente -------------------------------------------
        await sup.page.goto(BASE + '/admin/users/create');
        const modulo = await sup.page.evaluate(() => ({
            menu: !!document.querySelector('select[name="role"]'),
            fisso: [...document.querySelectorAll('form[data-user-form] p')].map((p) => p.textContent.trim()).find((t) => t.startsWith('Ruolo:')) || '',
            foto: !!document.querySelector('input[name="avatar"]'),
        }));
        check('il modulo: «Ruolo: Studente», senza menu e senza foto', !modulo.menu && modulo.fisso === 'Ruolo: Studente' && !modulo.foto, [JSON.stringify(modulo)]);
        await posta(sup.page, '/admin/users', persona('Uno', 'uno' + DOMINIO, 'studente'), '/admin/users/create');
        let u = utente('uno' + DOMINIO);
        check('crea uno studente', u !== null && u.role === 'studente' && Number(u.is_active) === 1, [JSON.stringify(u)]);
        await posta(sup.page, '/admin/users', persona('Due', 'due' + DOMINIO, 'tutor'), '/admin/users/create');
        u = utente('due' + DOMINIO);
        check('un ruolo diverso mandato a mano diventa «studente»', u !== null && u.role === 'studente', [JSON.stringify(u)]);

        // --- 3. modifica uno studente ---------------------------------------
        const uno = utente('uno' + DOMINIO);
        await sup.page.goto(BASE + '/admin/users/' + uno.id + '/edit');
        check('la scheda dello studente si apre, con gruppi e password temporanea',
            await sup.page.locator('form[action$="/password"]').count() === 1 && await sup.page.locator('select[name="group_id"]').count() === 1);
        await posta(sup.page, '/admin/users/' + uno.id, persona('Unico', 'unico' + DOMINIO, 'studente', false), '/admin/users/' + uno.id + '/edit');
        u = utenteId(uno.id);
        check('modifica nome, email e stato', u.first_name === 'Unico' && u.email === 'unico' + DOMINIO && Number(u.is_active) === 0, [JSON.stringify(u)]);
        await posta(sup.page, '/admin/users/' + uno.id, persona('Unico', 'unico' + DOMINIO, 'admin'), '/admin/users/' + uno.id + '/edit');
        u = utenteId(uno.id);
        check('non cambia il ruolo: «admin» mandato a mano lascia uno studente', u.role === 'studente' && Number(u.is_active) === 1, [JSON.stringify(u)]);
        const primaHash = u.password_hash;
        await posta(sup.page, '/admin/users/' + uno.id + '/password', [], '/admin/users/' + uno.id + '/edit');
        check('manda la password temporanea', utenteId(uno.id).password_hash !== primaHash);
        await posta(sup.page, '/admin/users/' + uno.id + '/groups', [['group_id', gruppo.id]], '/admin/users/' + uno.id + '/edit');
        const membro = riga('SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND user_id = ?', gruppo.id, uno.id);
        check('lo mette in un gruppo dalla scheda', Number(membro.n) === 1);

        // --- 4. lo staff non lo tocca ----------------------------------------
        const t0 = utenteId(tutor.id);
        check('la scheda di un tutor: 403', await stato(sup.page, '/admin/users/' + tutor.id + '/edit') === 403);
        const r1 = await posta(sup.page, '/admin/users/' + tutor.id, persona('Rubato', 'rubato' + DOMINIO, 'studente'), '/admin/users');
        const r2 = await posta(sup.page, '/admin/users/' + tutor.id + '/password', [], '/admin/users');
        const r3 = await posta(sup.page, '/admin/users/' + tutor.id + '/delete', [], '/admin/users');
        const t1 = utenteId(tutor.id);
        check('un tutor non si modifica, non riceve password, non si elimina (403, e niente cambia)',
            r1 === 403 && r2 === 403 && r3 === 403 && JSON.stringify(t0) === JSON.stringify(t1), [r1, r2, r3, JSON.stringify(t1)]);
        const collega = utente(STAFF_SUP);
        const r4 = await posta(sup.page, '/admin/users/' + collega.id + '/delete', [], '/admin/users');
        check('nemmeno un altro supervisore (403)', r4 === 403 && utente(STAFF_SUP) !== null);

        // --- 5. elimina uno studente ----------------------------------------
        const due = utente('due' + DOMINIO);
        await posta(sup.page, '/admin/users/' + due.id + '/delete', [], '/admin/users');
        check('elimina uno studente', utente('due' + DOMINIO) === null);

        // --- 6. importa da file, e gli inviti -------------------------------
        file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'imp-')), 'elenco.csv');
        fs.writeFileSync(file, 'email;nome;cognome\ntre' + DOMINIO + ';Tre;Prova\nquattro' + DOMINIO + ';Quattro;Prova\n');
        await sup.page.goto(BASE + '/admin/users/importa');
        await sup.page.setInputFiles('input[name="file"]', file);
        await Promise.all([sup.page.waitForLoadState('networkidle'), sup.page.click('form[action="/admin/users/importa/anteprima"] button[type="submit"]')]);
        await Promise.all([sup.page.waitForLoadState('networkidle'), sup.page.click('form[action="/admin/users/importa"] button[type="submit"]')]);
        const tre = utente('tre' + DOMINIO);
        const quattro = utente('quattro' + DOMINIO);
        check('importa da file: due studenti, con l’invito in attesa',
            tre !== null && quattro !== null && tre.role === 'studente' && Number(tre.invite_pending) === 1, [JSON.stringify(tre), JSON.stringify(quattro)]);
        await sup.page.goto(BASE + '/admin/users');
        check('vede gli inviti in attesa e «Manda adesso il prossimo scaglione»',
            await sup.page.locator('form[action="/admin/users/inviti/manda"]').count() === 1);
        await posta(sup.page, '/admin/users/inviti/manda', [], '/admin/users');
        check('manda lo scaglione: gli inviti partono', Number((utente('tre' + DOMINIO) || {}).invite_pending) === 0);

        // --- 7. controprove ---------------------------------------------------
        await adm.page.goto(BASE + '/admin/users');
        const vista = await adm.page.evaluate(() => ({
            ruoli: [...new Set([...document.querySelectorAll('td[data-label="Ruolo"]')].map((t) => t.textContent.trim()))],
            scarica: !!document.querySelector('a[href="/admin/users/csv"]'),
            menu: !!document.querySelector('select[name="role"]'),
        }));
        await adm.page.goto(BASE + '/admin/users/create');
        check('controprova: l’Amministratore vede tutti i ruoli, scarica l’elenco e sceglie il ruolo',
            vista.ruoli.length > 1 && vista.scarica && await adm.page.locator('select[name="role"]').count() === 1, [JSON.stringify(vista)]);
        const tut = await entra(browser, TUTOR);
        check('controprova: il tutor resta fuori da «Utenti» (403)', await stato(tut.page, '/admin/users') === 403);
    } finally {
        await browser.close();
        pulisci();
        if (file) fs.rmSync(path.dirname(file), { recursive: true, force: true });
    }

    console.log('\n' + ok + ' OK, ' + fail + ' FAIL');
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    pulisci();
    process.exit(1);
});
