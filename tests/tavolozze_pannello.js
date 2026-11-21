/**
 * Le otto tavolozze nel riquadro «Colori» di Aspetto (21/11, Alessandro).
 *
 * Riquadri stretti: otto in fila su un computer, quattro per riga su un
 * tablet, due sul telefono, sempre a righe piene e con la distanza della
 * griglia fra una riga e l'altra; il nome sta dentro il suo riquadro. Dentro
 * il riquadro (21/11): i campioni a tutta larghezza, il nome centrato, sotto
 * la scelta, centrata. Una
 * tavolozza nuova si sceglie, si salva e si vede. I contrasti di ogni
 * tavolozza li controlla `tema_test.php`.
 *
 * Esecuzione:  node tests/tavolozze_pannello.js   (server di sviluppo acceso)
 *
 * Sceglie l'ocra e alla fine rimette la tavolozza che c'era.
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

function php(codice, ...argomenti) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();' + codice, '--', ...argomenti.map(String)], { cwd: RADICE, encoding: 'utf8' });
}

const uno = (sql, ...a) => php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); $v = $s->fetchColumn(); echo $v === false || $v === null ? "\\x00" : $v;', sql, ...a);
const esegui = (sql, ...a) => php('$pdo->prepare($argv[1])->execute(array_slice($argv, 2));', sql, ...a);
const tavolozza = () => uno("SELECT setting_value FROM settings WHERE setting_key = 'THEME_PALETTE'");

(async () => {
    const prima = tavolozza();
    const browser = await chromium.launch();

    try {
        for (const [larghezza, colonne, dove] of [[1280, 8, 'computer'], [1024, 4, 'tablet'], [375, 2, 'telefono']]) {
            const page = await (await browser.newContext({ viewport: { width: larghezza, height: 900 } })).newPage();
            await page.goto(BASE + '/login');
            await page.fill('input[name="email"]', 'admin@test.it');
            await page.fill('input[name="password"]', PASSWORD);
            await page.click('form.auth-form button[type="submit"]');
            await page.waitForLoadState('networkidle');
            await page.goto(BASE + '/admin/settings/aspetto');
            await page.evaluate(() => { document.querySelector('details:has(.tavolozze)').open = true; });

            const r = await page.evaluate(() => {
                const riquadri = [...document.querySelectorAll('.tavolozza')];
                const righe = [...new Set(riquadri.map((t) => Math.round(t.getBoundingClientRect().top)))].sort((a, b) => a - b);
                const fondo = riquadri.filter((t) => Math.round(t.getBoundingClientRect().top) === righe[0])[0].getBoundingClientRect().bottom;
                return {
                    nomi: riquadri.map((t) => t.querySelector('.tavolozza-nome').textContent.trim()),
                    righe: righe.length,
                    distanza: righe.length > 1 ? Math.round(righe[1] - fondo) : null,
                    fuori: riquadri.filter((t) => {
                        const n = t.querySelector('.tavolozza-nome');
                        return n.scrollWidth > n.clientWidth + 1 || n.getBoundingClientRect().right > t.getBoundingClientRect().right;
                    }).map((t) => t.textContent.trim()),
                    disposizione: riquadri.filter((t) => {
                        const box = t.getBoundingClientRect();
                        const stile = getComputedStyle(t);
                        const interno = box.width - parseFloat(stile.paddingLeft) - parseFloat(stile.paddingRight) - parseFloat(stile.borderLeftWidth) - parseFloat(stile.borderRightWidth);
                        const c = t.querySelector('.tavolozza-campioni').getBoundingClientRect();
                        const n = t.querySelector('.tavolozza-nome').getBoundingClientRect();
                        const i = t.querySelector('input').getBoundingClientRect();
                        const centro = box.left + box.width / 2;
                        const centrato = (x) => Math.abs(x.left + x.width / 2 - centro) <= 1.5;
                        return !(Math.abs(c.width - interno) <= 1.5 && c.bottom <= n.top && n.bottom <= i.top
                            && centrato(n) && centrato(i) && getComputedStyle(t.querySelector('.tavolozza-nome')).textAlign === 'center');
                    }).map((t) => t.textContent.trim()),
                };
            });

            if (dove === 'computer') {
                check('otto tavolozze, in ordine di tinta a partire dal verde',
                    JSON.stringify(r.nomi) === JSON.stringify(['Verde', 'Petrolio', 'Blu ardesia', 'Indaco', 'Prugna', 'Bordeaux', 'Terracotta', 'Ocra']), [JSON.stringify(r.nomi)]);
            }
            check(`${dove}: ${colonne} per riga, righe piene`, r.righe === 8 / colonne, [JSON.stringify(r)]);
            if (r.distanza !== null) {
                check(`${dove}: fra una riga e l'altra solo lo spazio della griglia`, r.distanza >= 6 && r.distanza <= 14, [String(r.distanza)]);
            }
            check(`${dove}: ogni nome sta dentro il suo riquadro`, r.fuori.length === 0, r.fuori);
            check(`${dove}: campioni a tutta larghezza, sotto il nome centrato, sotto la scelta centrata`, r.disposizione.length === 0, r.disposizione);

            if (dove === 'computer') {
                await page.click('.tavolozza:has(input[value="ocra"])');
                await Promise.all([page.waitForNavigation(), page.click('form.aspetto-modulo button[type="submit"]:not([name])')]);
                const primario = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim().toUpperCase());
                check('scelta l\'ocra e salvata, la piattaforma prende il suo colore', tavolozza() === 'ocra' && primario === '#825B17', [tavolozza(), primario]);
            }
            await page.close();
        }
    } finally {
        await browser.close();
        if (prima === '\x00') {
            esegui("DELETE FROM settings WHERE setting_key = 'THEME_PALETTE'");
        } else {
            esegui("UPDATE settings SET setting_value = ? WHERE setting_key = 'THEME_PALETTE'", prima);
        }
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
