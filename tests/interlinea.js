/**
 * L'interlinea (26/11, Alessandro): una sola per il testo che si legge,
 * 1,5 come il riferimento delle WCAG (1.4.12); 1,2 per titoli e comandi
 * (righe brevi); 1,3 nelle tabelle; 1 per i componenti che hanno bisogno di
 * una misura esatta. Prima erano quindici valori in cinquanta punti, e due
 * frasi una sotto l'altra potevano avere righe diverse.
 *
 * Due controlli:
 *   1. nel foglio di stile nessuna interlinea fuori da quelle decise (piu'
 *      le altezze fisse, scritte qui per nome);
 *   2. sulle pagine, il testo, i titoli, le celle e i pulsanti hanno
 *      l'interlinea della loro famiglia.
 *
 * Esecuzione:  node tests/interlinea.js   (server di sviluppo acceso)
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

// I valori ammessi, e le eccezioni che non sono interlinee di lettura.
const AMMESSI = ['1.5', '1.2', '1.3', '1'];
const ECCEZIONI = ['var(--tap-target)', '1.4rem', '0.82', 'inherit'];

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 10).forEach((d) => console.log('         · ' + d));
    }
}

(async () => {
    const css = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'css', 'style.css'), 'utf8');
    const valori = [...css.matchAll(/line-height:\s*([^;]+);/g)].map((m) => m[1].trim());
    const fuori = valori.filter((v) => !AMMESSI.includes(v) && !ECCEZIONI.includes(v));
    check('nel foglio di stile solo le interlinee decise (' + valori.length + ' dichiarazioni)', fuori.length === 0, fuori);
    check('1,5 scritto una volta sola, sul corpo della pagina', valori.filter((v) => v === '1.5').length === 1);

    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        const FAMIGLIE = [
            ['testo', 'main p.form-hint, main p.riquadro-intro, main .page-subtitle, main .lesson-content p, main .course-description p', 1.5],
            ['titoli', 'main h1, main h2, main h3', 1.2],
            ['pulsanti ed etichette', 'main .btn, main .badge:not(.data-table .badge)', 1.2],
            ['celle', 'main .data-table td', 1.3],
        ];
        for (const u of ['/admin/settings/comandi', '/admin/settings/posta', '/courses/1', '/lessons/8', '/admin/users']) {
            await page.goto(BASE + u);
            const r = await page.evaluate((famiglie) => famiglie.map(([nome, sel, atteso]) => {
                const sbagliati = [...document.querySelectorAll(sel)].filter((e) => e.getClientRects().length && e.textContent.trim()).map((e) => {
                    const s = getComputedStyle(e);
                    const v = s.lineHeight === 'normal' ? 0 : Math.round(parseFloat(s.lineHeight) / parseFloat(s.fontSize) * 100) / 100;
                    return Math.abs(v - atteso) < 0.02 ? null : e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0] + ' ' + v;
                }).filter(Boolean);
                return [nome, atteso, sbagliati];
            }), FAMIGLIE);
            for (const [nome, atteso, sbagliati] of r) {
                check(u + ': ' + nome + ' a ' + String(atteso).replace('.', ','), sbagliati.length === 0, sbagliati);
            }
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
