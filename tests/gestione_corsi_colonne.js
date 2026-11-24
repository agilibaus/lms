/**
 * «Gestione corsi»: lo stato e l'iscrizione in due colonne (24/11,
 * Alessandro, ripasso del tutor). Prima la colonna «Stato» aveva due
 * etichette, il corso pubblicato o in bozza e il tipo di iscrizione.
 *
 * Da Amministratore, tutor e supervisore (un supervisore di prova e una
 * bozza presso di lui, tolti alla fine; l'assistente non vede la pagina):
 *
 *   1. le colonne: Titolo, Stato, Iscrizione;
 *   2. in «Stato» una sola etichetta, Pubblicato o Bozza presso…; in
 *      «Iscrizione» una sola, la modalita' senza la parola «Iscrizione»:
 *      Gratuita, Su richiesta, A pagamento, Chiusa;
 *   3. «Stato» e «Iscrizione» si ordinano (l'ordine lo controlla
 *      `ordinamento_pagine.js`).
 *
 * Esecuzione:  node tests/gestione_corsi_colonne.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const SUPERVISORE = 'supervisore.colonne@prova-colonne.invalid';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 10).forEach((d) => console.log('         · ' + d));
    }
}

function supervisore(azione) {
    execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php"; $pdo = App\\Core\\Database::connection();'
        + ' $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);'
        + ' $pdo->exec("DELETE FROM courses WHERE slug = \'prova-colonne-bozza\'");'
        // Il supervisore vede le bozze: una presso di lui, perche' la tabella ci sia.
        + ' if ($argv[2] === "crea") { $pdo->exec("INSERT INTO courses (title, slug, is_published, presso, enrollment_mode, created_by) SELECT \'[prova-colonne] bozza\', \'prova-colonne-bozza\', 0, \'supervisore\', \'request\', MIN(id) FROM users WHERE role = \'admin\'"); }'
        + ' if ($argv[2] === "crea") { $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Prova\', \'Colonne\', \'supervisore\', 1, NOW(), NOW())")->execute([$argv[1], password_hash($argv[3], PASSWORD_DEFAULT)]); }',
    '--', SUPERVISORE, azione, PASSWORD], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
}

// Senza «Iscrizione», che sta nell'intestazione (24/11).
const MODALITA = ['Gratuita', 'Su richiesta', 'A pagamento', 'Chiusa'];

(async () => {
    supervisore('crea');
    const browser = await chromium.launch();
    try {
        for (const email of ['admin@test.it', 'tutor1@test.it', SUPERVISORE]) {
            const nome = email.split('@')[0];
            const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
            await page.goto(BASE + '/login');
            await page.fill('input[name="email"]', email);
            await page.fill('input[name="password"]', PASSWORD);
            await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
            const r = await page.goto(BASE + '/admin/courses');
            const t = await page.evaluate(() => {
                const th = [...document.querySelectorAll('.data-table thead th')];
                const nomi = th.map((x) => (x.querySelector('a') ? x.querySelector('a').firstChild.textContent : x.textContent).trim());
                const ordinabili = th.filter((x) => x.querySelector('a.ordina')).map((x) => x.querySelector('a').firstChild.textContent.trim());
                const col = (n) => nomi.indexOf(n);
                return {
                    nomi,
                    ordinabili,
                    righe: [...document.querySelectorAll('.data-table tbody tr')].map((tr) => ({
                        stato: col('Stato') < 0 ? [] : [...tr.children[col('Stato')].querySelectorAll('.badge')].map((b) => b.textContent.trim()),
                        iscrizione: col('Iscrizione') < 0 ? [] : [...tr.children[col('Iscrizione')].querySelectorAll('.badge')].map((b) => b.textContent.trim()),
                    })),
                };
            });
            check(nome + ': la pagina si apre', r.status() === 200, [String(r.status())]);
            check(nome + ': le colonne Titolo, Stato, Iscrizione', JSON.stringify(t.nomi.slice(0, 3)) === '["Titolo","Stato","Iscrizione"]', [JSON.stringify(t.nomi)]);
            check(nome + ': in «Stato» solo lo stato, in «Iscrizione» solo l\'iscrizione, una etichetta per cella',
                t.righe.length > 0 && t.righe.every((x) => x.stato.length === 1 && /^(Pubblicato|Bozza presso )/.test(x.stato[0])
                    && x.iscrizione.length === 1 && MODALITA.includes(x.iscrizione[0])), t.righe.map((x) => JSON.stringify(x)));
            check(nome + ': «Stato» e «Iscrizione» si ordinano', t.ordinabili.includes('Stato') && t.ordinabili.includes('Iscrizione'), [JSON.stringify(t.ordinabili)]);
            await page.close();
        }
    } finally {
        await browser.close();
        supervisore('togli');
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
