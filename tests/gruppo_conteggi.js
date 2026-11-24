/**
 * I conteggi sotto il nome del gruppo (24/11, Alessandro): «1 corso
 * assegnato» e «2 corsi assegnati», non «1 corsi assegnati»; lo stesso per
 * i membri, «1 membro». Nella scheda del gruppo (Gruppi › il gruppo) e nel
 * report del gruppo.
 *
 * Sui gruppi della semina: «mondo A» ha un membro e un corso, «cerchio»
 * otto membri e nessun corso.
 *
 * Esecuzione:  node tests/gruppo_conteggi.js   (server di sviluppo acceso,
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
        dettagli.slice(0, 6).forEach((d) => console.log('         · ' + d));
    }
}

/** Il gruppo della semina con quel nome: id, membri, corsi. */
function gruppo(nome) {
    const r = execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php"; $pdo = App\\Core\\Database::connection();'
        + ' $s = $pdo->prepare("SELECT g.id, (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id), (SELECT COUNT(*) FROM group_course_access c WHERE c.group_id = g.id) FROM groups g WHERE g.name = ? ORDER BY g.id DESC LIMIT 1");'
        + ' $s->execute([$argv[1]]); echo implode(" ", $s->fetch(PDO::FETCH_NUM) ?: []);', '--', nome],
    { cwd: path.join(__dirname, '..'), encoding: 'utf8' }).trim().split(' ').map(Number);
    return { id: r[0], membri: r[1], corsi: r[2] };
}

const atteso = (g) => (g.membri === 1 ? '1 membro' : g.membri + ' membri') + ' · '
    + (g.corsi === 1 ? '1 corso assegnato' : g.corsi + ' corsi assegnati');

(async () => {
    const gruppi = [gruppo('[prova-permessi] mondo A'), gruppo('[prova-permessi] cerchio')];
    check('i due gruppi della semina: uno con un membro e un corso, uno con piu\' membri',
        gruppi[0].membri === 1 && gruppi[0].corsi === 1 && gruppi[1].membri > 1, [JSON.stringify(gruppi)]);

    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext()).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        for (const g of gruppi) {
            for (const [dove, u] of [['scheda del gruppo', '/admin/groups/' + g.id + '/edit'], ['report del gruppo', '/reports/groups/' + g.id]]) {
                await page.goto(BASE + u);
                const riga = await page.evaluate(() => (document.querySelector('.page-header .page-subtitle') || {}).textContent?.replace(/\s+/g, ' ').trim() || '');
                check(dove + ' (membri ' + g.membri + ', corsi ' + g.corsi + '): «' + atteso(g) + '»', riga.includes(atteso(g)), [riga]);
            }
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
