/**
 * Il testo che apre un riquadro (25/11, Alessandro): sotto il titolo del
 * riquadro, una misura sola (quella dei testi secondari) e righe meno
 * strette. Prima era la nota piccola (`.card-meta`, 0,8 rem) in quattordici
 * riquadri e l'aiuto dei campi (`.form-hint`) in altri sei, tutti con
 * l'interlinea di base: nelle «Categorie della bacheca», su due righe, il
 * blocco sembrava schiacciato.
 *
 * Due controlli: nelle viste nessun paragrafo subito sotto il titolo di un
 * riquadro usa ancora una delle due classi; nella pagina, il testo ha la
 * misura e l'interlinea giuste.
 *
 * Esecuzione:  node tests/riquadro_intro.js   (server di sviluppo acceso)
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const VISTE = path.join(__dirname, '..', 'app', 'Views');

// Paragrafi sotto un `h2` che non aprono un riquadro.
const NON_INTRO = {
    'questions/index.php': 'chi ha fatto la domanda e quando: un dato della domanda',
    'admin/settings/email.php': 'la testa della scelta di un modello, non un riquadro',
};

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

function viste(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((d) => {
        const p = path.join(dir, d.name);
        return d.isDirectory() ? viste(p) : (p.endsWith('.php') ? [p] : []);
    });
}

(async () => {
    const vecchie = [];
    for (const f of viste(VISTE)) {
        const nome = path.relative(VISTE, f).split(path.sep).join('/');
        if (nome in NON_INTRO) continue;
        const righe = fs.readFileSync(f, 'utf8').split('\n');
        righe.forEach((r, i) => {
            if (!r.includes('<h2')) return;
            // La riga dopo il titolo, saltando le vuote e quelle solo PHP.
            for (const s of righe.slice(i + 1, i + 5)) {
                const t = s.trim();
                if (t === '' || t.startsWith('<?php')) continue;
                if (/^<p class="(card-meta|form-hint)\b/.test(t)) vecchie.push(nome + ':' + (righe.indexOf(s, i) + 1));
                break;
            }
        });
    }
    check('nelle viste, sotto il titolo di un riquadro, nessun testo con .card-meta o .form-hint', vecchie.length === 0, vecchie);

    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        for (const [u, titolo] of [['/bacheca/corsi/1/categorie', 'Categorie'], ['/admin/users/importa', null], ['/admin/settings/posta', 'Prova di invio']]) {
            await page.goto(BASE + u);
            const r = await page.evaluate((titolo) => {
                const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
                const c = [...document.querySelectorAll('section.card')].find((s) => titolo === null || s.querySelector('h2')?.textContent.trim() === titolo);
                const p = c?.querySelector(':scope > h2 + p');
                if (!p) return null;
                const s = getComputedStyle(p);
                const fs = parseFloat(s.fontSize);
                return { classe: p.className, misura: Math.round(fs / rem * 100) / 100, interlinea: s.lineHeight === 'normal' ? 0 : Math.round(parseFloat(s.lineHeight) / fs * 100) / 100 };
            }, titolo);
            check(u + ': il testo sotto il titolo è a 0,92 rem con interlinea 1,45',
                r !== null && r.misura === 0.92 && r.interlinea === 1.45, [JSON.stringify(r)]);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
