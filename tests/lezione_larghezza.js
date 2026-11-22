/**
 * Il testo della lezione occupa tutta la colonna (22/11, Alessandro).
 *
 * Fino ad allora si fermava a 68 caratteri: su uno schermo largo il testo
 * sembrava troncato, con mezza pagina vuota accanto. Ora e' largo quanto la
 * colonna delle pagine, che resta di al massimo 1080 px; sul telefono non
 * scorre di lato.
 *
 * Esecuzione:  node tests/lezione_larghezza.js   (server di sviluppo acceso,
 * semina dei permessi fatta: usa una lezione del corso «[prova-permessi]
 * mondo A»)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.forEach((d) => console.log('         · ' + d));
    }
}

const lezione = execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
    + ' echo App\\Core\\Database::connection()->query("SELECT l.id FROM lessons l JOIN modules m ON m.id = l.module_id'
    + ' JOIN courses c ON c.id = m.course_id WHERE c.title = \'[prova-permessi] mondo A\' ORDER BY m.position, l.position LIMIT 1")->fetchColumn();'],
    { cwd: RADICE, encoding: 'utf8' }).trim();

(async () => {
    const browser = await chromium.launch();

    try {
        for (const larghezza of [1920, 1280, 375]) {
            const page = await (await browser.newContext({ viewport: { width: larghezza, height: 900 } })).newPage();
            await page.goto(BASE + '/login');
            await page.fill('input[name="email"]', 'admin@test.it');
            await page.fill('input[name="password"]', PASSWORD);
            await page.click('form.auth-form button[type="submit"]');
            await page.waitForLoadState('networkidle');
            await page.goto(BASE + '/lessons/' + lezione);

            const r = await page.evaluate(() => {
                const main = document.querySelector('.main-content');
                const s = getComputedStyle(main);
                const colonna = main.getBoundingClientRect().width - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight);
                return {
                    testo: Math.round(document.querySelector('.lesson-content').getBoundingClientRect().width),
                    colonna: Math.round(colonna),
                    main: Math.round(main.getBoundingClientRect().width),
                    scorre: document.documentElement.scrollWidth > window.innerWidth,
                };
            });

            if (larghezza === 375) {
                check('telefono: il testo sta nello schermo, senza scorrere di lato', !r.scorre && r.testo <= r.colonna, [JSON.stringify(r)]);
            } else {
                check(`${larghezza} px: il testo è largo quanto la colonna`, Math.abs(r.testo - r.colonna) <= 1, [JSON.stringify(r)]);
                check(`${larghezza} px: la colonna resta al massimo 1080 px`, r.main <= 1080, [JSON.stringify(r)]);
            }
            await page.close();
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
