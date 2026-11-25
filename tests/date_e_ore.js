/**
 * Data e ora come in tutta la piattaforma, «16/09/2026 18:00» (25/11,
 * Alessandro), anche dove prima c'erano «ieri» o «oggi alle»:
 *
 *   1. «Ultimo accesso» sotto il nome, nella pagina dello studente dei
 *      report (prima «ieri (24/11/2026 11:52)»);
 *   2. «Ultimo giro» della posta, nella coda delle email (prima «oggi alle
 *      10:15»). L'ultimo giro si mette ad adesso per la prova e si rimette
 *      com'era alla fine.
 *
 * Esecuzione:  node tests/date_e_ore.js   (server di sviluppo acceso)
 */
'use strict';

const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const DATA_ORA = '\\d{2}/\\d{2}/\\d{4} \\d{2}:\\d{2}';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 6).forEach((d) => console.log('         · ' + d));
    }
}

/** Legge o scrive l'ultimo giro della posta; restituisce il valore di prima. */
function ultimoGiro(nuovo) {
    const codice = 'require "vendor/autoload.php"; require "config/config.php";'
        + '$k = \\App\\Core\\Avvisi::ULTIMO_GIRO; echo json_encode(\\App\\Core\\Settings::stored($k));'
        + '$n = json_decode($argv[1], true);'
        + 'if ($n === null) { \\App\\Core\\Database::connection()->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]); }'
        + 'elseif ($n !== "leggi") { \\App\\Core\\Settings::recordSystem($k, $n); }';
    return JSON.parse(execFileSync('php', ['-r', codice, JSON.stringify(nuovo)], { cwd: RADICE }).toString());
}

async function entra(browser, email) {
    const page = await (await browser.newContext()).newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
    return page;
}

(async () => {
    const browser = await chromium.launch();
    const prima = ultimoGiro('leggi');
    try {
        // 1. L'ultimo accesso: lo studente entra, l'Amministratore apre la sua pagina.
        await entra(browser, 'stud@test.it');
        const admin = await entra(browser, 'admin@test.it');
        await admin.goto(BASE + '/reports/elenco/students');
        const link = await admin.evaluate(() => [...document.querySelectorAll('tbody tr')]
            .find((r) => r.textContent.includes('stud@test.it'))?.querySelector('a')?.getAttribute('href'));
        check('lo studente di prova è nell\'elenco', !!link);
        if (link) {
            await admin.goto(BASE + link);
            const frase = await admin.evaluate(() => [...document.querySelectorAll('.page-header .page-subtitle')]
                .map((p) => p.textContent.replace(/\s+/g, ' ').trim()).find((t) => t.startsWith('Ultimo accesso')));
            check('pagina dello studente: «Ultimo accesso: gg/mm/aaaa hh:mm», e nient\'altro',
                new RegExp('^Ultimo accesso: ' + DATA_ORA + '$').test(frase || ''), [String(frase)]);
        }

        // 2. L'ultimo giro della posta, adesso: prima era «oggi alle».
        const adesso = new Date();
        const due = (n) => String(n).padStart(2, '0');
        ultimoGiro(`${adesso.getFullYear()}-${due(adesso.getMonth() + 1)}-${due(adesso.getDate())} ${due(adesso.getHours())}:${due(adesso.getMinutes())}:00`);
        await admin.goto(BASE + '/admin/settings/posta/coda');
        const giro = (await admin.locator('main').innerText()).match(/Ultimo giro: [^.]*\./)?.[0];
        check('coda delle email: «Ultimo giro: gg/mm/aaaa hh:mm.»',
            new RegExp('^Ultimo giro: ' + DATA_ORA + '\\.$').test(giro || ''), [String(giro)]);
    } finally {
        ultimoGiro(prima);
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
