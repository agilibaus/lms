/**
 * «Filtra» nella pagina Utenti (20/11, Alessandro).
 *
 * Il pulsante con l'imbuto sta accanto a «Scarica dati» (per il
 * supervisore, che non scarica, dopo «Importa da file»). Filtra per gruppo
 * e per tutor, anche «—», in AND; sopra la tabella dice che cosa si sta
 * guardando, con «Togli filtro»; il filtro resta ordinando, e «Scarica dati»
 * scarica solo gli utenti filtrati. La regola e' in `filtro_utenti_test.php`:
 * qui la pagina.
 *
 * Esecuzione:  node tests/utenti_filtro.js   (server di sviluppo acceso)
 *
 * Crea un tutor, due gruppi («[filtro] A» con il tutor, «[filtro] B» senza),
 * tre studenti, un assistente e un supervisore, tutti «@utenti-filtro.test»,
 * e alla fine li cancella.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const DOMINIO = '@utenti-filtro.test';
const TUTOR = 'Tutor Filtroprova';

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

const uno = (sql, ...a) => php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo (string) $s->fetchColumn();', sql, ...a).trim();
const esegui = (sql, ...a) => php('$pdo->prepare($argv[1])->execute(array_slice($argv, 2));', sql, ...a);

function pulisci() {
    esegui("DELETE FROM `groups` WHERE name LIKE '[filtro] %'");
    esegui('DELETE FROM users WHERE email LIKE ?', '%' + DOMINIO);
}

function utente(nome, cognome, ruolo) {
    const hash = php('echo password_hash($argv[1], PASSWORD_DEFAULT);', PASSWORD);
    esegui('INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, name_display, welcome_seen_at, privacy_version_id)'
        + ' VALUES (?, ?, ?, ?, ?, 1, NOW(), \'full\', NOW(), (SELECT MAX(id) FROM privacy_versions))',
        (nome + '.' + cognome).toLowerCase() + DOMINIO, hash, nome, cognome, ruolo);
    return uno('SELECT id FROM users WHERE email = ?', (nome + '.' + cognome).toLowerCase() + DOMINIO);
}

async function entra(page, email) {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');
}

/** Che cosa mostra la pagina: righe, la frase sopra la tabella, il pulsante. */
async function stato(page) {
    return page.evaluate(() => ({
        nomi: [...document.querySelectorAll('.data-table tbody td[data-label="Nome"]')].map((td) => td.textContent.trim()),
        frase: document.querySelector('.filtro-attivo span')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
        togli: document.querySelector('.filtro-attivo a')?.textContent.trim() ?? null,
        pulsante: [...document.querySelectorAll('.page-actions summary')].map((s) => s.textContent.trim()).find((t) => t.startsWith('Filtra')) ?? null,
        vuoto: document.querySelector('.empty-state')?.textContent.trim() ?? null,
    }));
}

async function filtra(page, gruppo, tutor) {
    await page.locator('details.dropdown:has(summary:has-text("Filtra")) > summary').click();
    await page.selectOption('#filtro-gruppo', { label: gruppo });
    await page.selectOption('#filtro-tutor', { label: tutor });
    await page.click('.filtro-utenti button[type="submit"]');
    await page.waitForLoadState('networkidle');
}

(async () => {
    pulisci();
    const tutor = utente('Tutor', 'Filtroprova', 'tutor');
    const s1 = utente('Primo', 'Filtroprova', 'studente');
    const s2 = utente('Secondo', 'Filtroprova', 'studente');
    const s3 = utente('Terzo', 'Filtroprova', 'studente');
    const assistente = utente('Assistente', 'Filtroprova', 'assistente');
    utente('Supervisore', 'Filtroprova', 'supervisore');
    esegui("INSERT INTO `groups` (name, tutor_id) VALUES ('[filtro] A', ?), ('[filtro] B', NULL)", tutor);
    const a = uno("SELECT id FROM `groups` WHERE name = '[filtro] A'");
    const b = uno("SELECT id FROM `groups` WHERE name = '[filtro] B'");
    esegui('INSERT INTO group_members (group_id, user_id) VALUES (?, ?), (?, ?), (?, ?), (?, ?)', a, s1, a, s2, b, s2, b, s3);
    esegui('INSERT INTO assistant_tutors (assistant_id, tutor_id) VALUES (?, ?)', assistente, tutor);
    const totale = Number(uno('SELECT COUNT(*) FROM users'));

    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();

    try {
        await entra(page, 'admin@test.it');
        await page.goto(BASE + '/admin/users');

        console.log('--- il pulsante');
        let r = await page.evaluate(() => [...document.querySelectorAll('.page-actions > *')].map((e) => ({
            testo: (e.querySelector('summary') ?? e).textContent.trim(),
            imbuto: !!e.querySelector('summary svg') && e.querySelector('summary')?.textContent.trim().startsWith('Filtra'),
        })));
        const dove = r.findIndex((x) => x.testo.startsWith('Filtra'));
        check('«Filtra», con l\'imbuto, subito dopo «Scarica dati»',
            dove > 0 && r[dove - 1].testo.startsWith('Scarica dati') && r[dove].imbuto && r[dove].testo === 'Filtra', [JSON.stringify(r)]);
        r = await stato(page);
        check('senza filtro: nessuna frase sopra la tabella', r.frase === null, [JSON.stringify(r)]);

        console.log('\n--- per gruppo, per tutor, insieme');
        await filtra(page, '[filtro] A', 'Tutti');
        r = await stato(page);
        check('Gruppo: i suoi studenti, «Filtra (1)», la frase e «Togli filtro»',
            JSON.stringify(r.nomi) === JSON.stringify(['Primo Filtroprova', 'Secondo Filtroprova'])
                && r.pulsante === 'Filtra (1)' && r.frase === `2 utenti su ${totale} · Gruppo: [filtro] A` && r.togli === 'Togli filtro', [JSON.stringify(r)]);

        await filtra(page, 'Tutti', TUTOR);
        r = await stato(page);
        check('Tutor: gli studenti dei suoi gruppi e l\'assistente che lo affianca',
            JSON.stringify(r.nomi) === JSON.stringify(['Assistente Filtroprova', 'Primo Filtroprova', 'Secondo Filtroprova'])
                && r.frase === `3 utenti su ${totale} · Tutor: ${TUTOR}`, [JSON.stringify(r)]);

        await filtra(page, '[filtro] B', TUTOR);
        r = await stato(page);
        check('Gruppo e Tutor in AND: «Filtra (2)», al singolare',
            JSON.stringify(r.nomi) === JSON.stringify(['Secondo Filtroprova']) && r.pulsante === 'Filtra (2)'
                && r.frase === `1 utente su ${totale} · Gruppo: [filtro] B · Tutor: ${TUTOR}`, [JSON.stringify(r)]);

        await filtra(page, 'Tutti', '—');
        r = await stato(page);
        check('Tutor «—»: anche chi è in un gruppo senza tutor',
            r.nomi.includes('Secondo Filtroprova') && r.nomi.includes('Terzo Filtroprova') && !r.nomi.includes('Primo Filtroprova')
                && r.nomi.includes('Admin Prova'), [JSON.stringify(r)]);

        await filtra(page, '—', 'Tutti');
        r = await stato(page);
        check('Gruppo «—»: il personale, nessuno studente di quei gruppi',
            r.nomi.includes('Admin Prova') && r.nomi.includes('Assistente Filtroprova')
                && !r.nomi.some((n) => ['Primo Filtroprova', 'Secondo Filtroprova', 'Terzo Filtroprova'].includes(n)), [JSON.stringify(r)]);

        console.log('\n--- ordinamento, scaricamento, togliere il filtro');
        await page.goto(BASE + '/admin/users?gruppo=' + encodeURIComponent('[filtro] A') + '&ordina=nome&verso=desc');
        r = await stato(page);
        check('ordinando, il filtro resta', JSON.stringify(r.nomi) === JSON.stringify(['Secondo Filtroprova', 'Primo Filtroprova']), [JSON.stringify(r)]);
        await page.click('.data-table thead a:has-text("Email")');
        await page.waitForLoadState('networkidle');
        r = await stato(page);
        check('cliccando un\'intestazione, il filtro resta', r.pulsante === 'Filtra (1)' && r.nomi.length === 2, [JSON.stringify(r), page.url()]);

        const voci = await page.locator('details.dropdown:has(summary:has-text("Scarica dati")) .dropdown-menu a').evaluateAll(
            (as) => as.map((x) => ({ testo: x.textContent.trim(), href: x.getAttribute('href') })));
        check('«Scarica dati» dice quanti utenti scarica', voci.length > 0 && voci.every((v) => v.testo.endsWith(', 2 utenti') && v.href.includes('gruppo=')), [JSON.stringify(voci)]);
        const csv = await page.evaluate(async (h) => (await fetch(h)).text(), voci[0].href);
        check('il CSV contiene solo gli utenti filtrati',
            csv.includes('primo.filtroprova' + DOMINIO) && csv.includes('secondo.filtroprova' + DOMINIO)
                && !csv.includes('terzo.filtroprova' + DOMINIO) && !csv.includes('admin@test.it'), [csv.slice(0, 300)]);

        await page.click('.filtro-attivo a');
        await page.waitForLoadState('networkidle');
        r = await stato(page);
        check('«Togli filtro»: di nuovo tutti, nell\'ordine scelto, senza frase',
            r.frase === null && r.pulsante === 'Filtra' && r.nomi.length === totale && new URL(page.url()).searchParams.get('ordina') === 'email', [JSON.stringify(r), page.url()]);

        await page.goto(BASE + '/admin/users?gruppo=' + encodeURIComponent('[filtro] che non c\'è'));
        r = await stato(page);
        const scelto = await page.locator('#filtro-gruppo').inputValue();
        check('un filtro senza risultati lo dice, e il menu mostra che cosa si filtra',
            r.vuoto === 'Nessun utente con questo filtro.' && r.frase === `0 utenti su ${totale} · Gruppo: [filtro] che non c'è` && scelto === '[filtro] che non c\'è', [JSON.stringify(r), scelto]);

        console.log('\n--- il supervisore');
        await page.context().clearCookies();
        await entra(page, 'supervisore.filtroprova' + DOMINIO);
        await page.goto(BASE + '/admin/users');
        r = await page.evaluate(() => [...document.querySelectorAll('.page-actions > *')].map((e) => (e.querySelector('summary') ?? e).textContent.trim()));
        check('per il supervisore «Filtra» viene dopo «Importa da file»',
            r.indexOf('Filtra') === r.indexOf('Importa da file') + 1 && !r.some((t) => t.startsWith('Scarica')), [JSON.stringify(r)]);
        await filtra(page, '[filtro] A', 'Tutti');
        r = await stato(page);
        const studenti = Number(uno("SELECT COUNT(*) FROM users WHERE role = 'studente'"));
        check('e il filtro funziona sui suoi studenti',
            JSON.stringify(r.nomi) === JSON.stringify(['Primo Filtroprova', 'Secondo Filtroprova']) && r.frase === `2 utenti su ${studenti} · Gruppo: [filtro] A`, [JSON.stringify(r)]);
    } finally {
        await browser.close();
        pulisci();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
