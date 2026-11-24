/**
 * La frase sotto il titolo dei report per chi vede solo una parte degli
 * studenti (24/11, Alessandro). Era «Stai vedendo solo gli studenti dei
 * gruppi che segui», anche dove la tabella elenca corsi, incontri o video;
 * e in «Studenti fermi» e nella Griglia l'assistente leggeva la frase del
 * tutor. Ora parla dei dati:
 *
 *   tutor        «Questi dati riguardano solo gli studenti dei gruppi che segui.»
 *   assistente   «Questi dati riguardano solo gli studenti dei gruppi seguiti dal tuo tutor.»
 *
 * Si aprono le sette pagine (l'indice, i cinque elenchi, «Studenti fermi»
 * e la Griglia di un corso della semina) da tutor, da assistente e da
 * Amministratore, che vede tutti gli studenti e non ha la frase.
 *
 * Esecuzione:  node tests/report_perimetro.js   (server di sviluppo acceso,
 * semina dei permessi fatta)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 10).forEach((d) => console.log('         · ' + d));
    }
}

const corso = execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
    + ' echo (int) App\\Core\\Database::connection()->query("SELECT id FROM courses WHERE title = \'[prova-permessi] mondo A\' ORDER BY id DESC LIMIT 1")->fetchColumn();'],
{ cwd: path.join(__dirname, '..'), encoding: 'utf8' }).trim();

const PAGINE = ['/reports', '/reports/elenco/courses', '/reports/elenco/groups', '/reports/elenco/students',
    '/reports/elenco/live', '/reports/elenco/fruizione', '/reports/fermi', '/reports/courses/' + corso + '/griglia'];

const ATTESE = {
    'tutor1@test.it': 'Questi dati riguardano solo gli studenti dei gruppi che segui.',
    'assist@test.it': 'Questi dati riguardano solo gli studenti dei gruppi seguiti dal tuo tutor.',
    'admin@test.it': null,
};

(async () => {
    const browser = await chromium.launch();
    try {
        check('c\'e\' il corso della semina per la Griglia', corso !== '0' && corso !== '', ['semina dei permessi da fare']);
        for (const [email, attesa] of Object.entries(ATTESE)) {
            const page = await (await browser.newContext()).newPage();
            await page.goto(BASE + '/login');
            await page.fill('input[name="email"]', email);
            await page.fill('input[name="password"]', PASSWORD);
            await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
            const storte = [];
            for (const u of PAGINE) {
                const r = await page.goto(BASE + u);
                const frasi = await page.evaluate(() => [...document.querySelectorAll('.page-header .page-subtitle, .page-subtitle')]
                    .map((p) => p.textContent.replace(/\s+/g, ' ').trim())
                    .filter((t) => /riguardano solo|Stai vedendo solo/.test(t)));
                const giusto = r.status() === 200 && (attesa === null ? frasi.length === 0 : frasi.length === 1 && frasi[0] === attesa);
                if (!giusto) {
                    storte.push(u + ' [' + r.status() + ']: ' + (frasi.length ? frasi.join(' | ') : 'nessuna frase'));
                }
            }
            check(email.split('@')[0] + ': ' + (attesa === null ? 'nessuna frase, vede tutti' : '«' + attesa + '»') + ' nelle ' + PAGINE.length + ' pagine', storte.length === 0, storte);
            await page.close();
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
