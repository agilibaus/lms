/**
 * «Facoltativo» (27/11, Alessandro): un campo facoltativo lo dice fra parentesi
 * nell'etichetta, «Link (facoltativo)», non con «Facoltativo.» in testa alla
 * frase sotto il campo. Le tre eccezioni corrette:
 *   - Modifica gruppo, «Link di invito in WhatsApp (facoltativo)»;
 *   - Profilo del tutor, «Email per gli studenti (facoltativa)»;
 *   - Questionario H5P, «Sostituisci con un altro file .h5p»: e' un'azione,
 *     la parola non serve; la frase comincia da «I tentativi gia' svolti…».
 *
 * Due parti: nessuna vista ha una frase che comincia con «Facoltativo.» o
 * «Facoltativa.» (controllo sui file), e le tre pagine come le vede chi le usa.
 *
 * Esecuzione:  node tests/facoltativo.js   (server di sviluppo acceso; nel
 * database un gruppo e un questionario H5P, che il test cerca e non crea)
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

/** Il primo gruppo e il primo questionario H5P del database, in sola lettura. */
function ids() {
    const codice = 'require "vendor/autoload.php"; require "config/config.php";'
        + '$pdo = \\App\\Core\\Database::connection();'
        + 'echo json_encode(['
        + '  "gruppo" => (int) $pdo->query("SELECT id FROM `groups` ORDER BY id LIMIT 1")->fetchColumn(),'
        + '  "h5p" => (int) $pdo->query("SELECT id FROM quizzes WHERE h5p_file IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn(),'
        + ']);';
    return JSON.parse(execFileSync('php', ['-r', codice], { cwd: RADICE }).toString());
}

function viste(cartella) {
    return fs.readdirSync(cartella, { withFileTypes: true }).flatMap((v) => {
        const p = path.join(cartella, v.name);
        return v.isDirectory() ? viste(p) : (p.endsWith('.php') ? [p] : []);
    });
}

async function entra(browser, email) {
    const page = await (await browser.newContext()).newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
    return page;
}

/** Etichetta e frase sotto il campo con quell'id. */
function campo(page, id) {
    return page.evaluate((i) => {
        const pulito = (t) => (t || '').replace(/\s+/g, ' ').trim();
        const input = document.getElementById(i);
        let frase = null;
        for (let n = input?.nextElementSibling; n; n = n.nextElementSibling) {
            if (n.matches('p.form-hint')) { frase = pulito(n.textContent); break; }
            if (n.matches('label, input, select, textarea, div.form-actions')) break;
        }
        return { etichetta: pulito(document.querySelector('label[for="' + i + '"]')?.textContent), frase };
    }, id);
}

(async () => {
    // Le viste: nessuna frase comincia con «Facoltativo.» o «Facoltativa.».
    const trovati = viste(path.join(RADICE, 'app', 'Views')).flatMap((f) => fs.readFileSync(f, 'utf8').split('\n')
        .map((riga, n) => ({ riga, n }))
        .filter(({ riga }) => /^\s*Facoltativ[oa]\./.test(riga) || /form-hint[^>]*>\s*Facoltativ[oa]\./.test(riga))
        .map(({ riga, n }) => path.relative(RADICE, f) + ':' + (n + 1) + '  ' + riga.trim()));
    check('nessuna vista scrive «Facoltativo.» in testa alla frase sotto un campo', trovati.length === 0, trovati);

    const { gruppo, h5p } = ids();
    const browser = await chromium.launch();
    try {
        const admin = await entra(browser, 'admin@test.it');

        await admin.goto(BASE + '/admin/groups/' + gruppo + '/edit');
        const w = await campo(admin, 'whatsapp_url');
        check('Modifica gruppo: «Link di invito in WhatsApp (facoltativo)», la frase senza «Facoltativo.»',
            w.etichetta === 'Link di invito in WhatsApp (facoltativo)'
                && w.frase === 'In WhatsApp, nel gruppo: «Invita tramite link», poi copia il link. '
                    + 'Gli studenti del gruppo lo trovano nel benvenuto del tutor, in cima a ogni corso.',
            [JSON.stringify(w)]);

        if (h5p > 0) {
            await admin.goto(BASE + '/quizzes/' + h5p + '/edit');
            const q = await campo(admin, 'h5p');
            check('Questionario H5P: «Sostituisci con un altro file .h5p», la frase comincia da «I tentativi»',
                q.etichetta === 'Sostituisci con un altro file .h5p' && /^I tentativi già svolti restano( \(\d+\))?, con il punteggio che avevano\.$/.test(q.frase || ''),
                [JSON.stringify(q)]);
        } else {
            check('Questionario H5P: nel database ce n\'è uno da aprire', false);
        }

        const tutor = await entra(browser, 'tutor1@test.it');
        await tutor.goto(BASE + '/profilo');
        const e = await campo(tutor, 'contact_email');
        check('Profilo del tutor: «Email per gli studenti (facoltativa)», la frase senza «Facoltativa.»',
            e.etichetta === 'Email per gli studenti (facoltativa)'
                && e.frase === 'Compare ai tuoi studenti nel tuo benvenuto, in cima ai corsi dei tuoi gruppi. '
                    + 'Può essere diversa dall\'email con cui accedi, che non viene mai mostrata.',
            [JSON.stringify(e)]);
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
