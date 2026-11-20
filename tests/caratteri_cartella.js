/**
 * Che cosa c'e' in `storage/fonts/` (20/11, Alessandro).
 *
 * La sezione «Caratteri» di Impostazioni › Aspetto dice quali caratteri sono
 * sul server, quale usa l'applicazione e quale le pagine pubbliche, e
 * segnala i file che non corrispondono a nessuna famiglia del catalogo.
 *
 * Esecuzione:  node tests/caratteri_cartella.js   (server di sviluppo acceso)
 *
 * Mette da parte il contenuto di `storage/fonts` e le due impostazioni dei
 * caratteri, prova con la cartella assente, con un carattere solo e con due
 * caratteri piu' due file sbagliati, e alla fine rimette tutto com'era.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const FONT = path.join(RADICE, 'storage', 'fonts');
const PARCHEGGIO = path.join(RADICE, 'storage', 'fonts.prova-cartella');
const ALBERT = path.join(RADICE, 'public', 'assets', 'fonts', 'albert-sans-latin.woff2');

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
const imposta = (chiave, valore) => valore === '\x00'
    ? esegui('DELETE FROM settings WHERE setting_key = ?', chiave)
    : esegui('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', chiave, valore);

function cartella(file) {
    fs.rmSync(FONT, { recursive: true, force: true });
    if (file === null) {
        return;
    }
    fs.mkdirSync(FONT, { recursive: true });
    for (const f of file) {
        fs.copyFileSync(ALBERT, path.join(FONT, f));
    }
}

(async () => {
    const prima = { app: uno("SELECT setting_value FROM settings WHERE setting_key = 'THEME_FONT_APP'"),
        auth: uno("SELECT setting_value FROM settings WHERE setting_key = 'THEME_FONT_AUTH'") };
    const cera = fs.existsSync(FONT);
    if (cera) {
        fs.renameSync(FONT, PARCHEGGIO);
    }

    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    const leggi = async () => {
        await page.goto(BASE + '/admin/settings/aspetto');
        return page.locator('.caratteri-cartella').innerText().then((t) => t.replace(/\s+/g, ' ').trim());
    };

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        imposta('THEME_FONT_APP', '\x00');
        imposta('THEME_FONT_AUTH', '\x00');

        cartella(null);
        let t = await leggi();
        check('cartella assente: dice che non c\'è nessun carattere, e niente altro',
            t === 'In storage/fonts/ non c\'è ancora nessun carattere.', [t]);

        cartella(['lora.woff2']);
        t = await leggi();
        check('un carattere: al singolare, senza indicazione d\'uso',
            t === 'In storage/fonts/ c\'è 1 carattere, gli stessi che l\'editor offre in Formato › Caratteri: Lora.', [t]);

        cartella(['playfair-display.woff2', 'albert-sans.woff2', 'Pippo.woff2', 'lora.ttf']);
        imposta('THEME_FONT_APP', 'Albert Sans');
        imposta('THEME_FONT_AUTH', 'Playfair Display');
        t = await leggi();
        check('due caratteri, in ordine alfabetico, ciascuno con il suo uso',
            t.startsWith('In storage/fonts/ ci sono 2 caratteri, gli stessi che l\'editor offre in Formato › Caratteri: '
                + 'Albert Sans (applicazione), Playfair Display (pagine pubbliche).'), [t]);
        check('i file sbagliati sono segnalati, con il nome',
            t.endsWith('Questi file non corrispondono a nessuna famiglia del catalogo e Pistacchio non li usa: '
                + 'lora.ttf, Pippo.woff2. Controlla il nome e l\'estensione .woff2.'), [t]);

        imposta('THEME_FONT_AUTH', 'Albert Sans');
        fs.rmSync(path.join(FONT, 'lora.ttf'));
        t = await leggi();
        check('lo stesso carattere per tutte e due, e un solo file sbagliato al singolare',
            t.includes('Albert Sans (applicazione e pagine pubbliche), Playfair Display.')
                && t.endsWith('Questo file non corrisponde a nessuna famiglia del catalogo e Pistacchio non lo usa: Pippo.woff2. Controlla il nome e l\'estensione .woff2.'), [t]);
    } finally {
        await browser.close();
        fs.rmSync(FONT, { recursive: true, force: true });
        if (cera) {
            fs.renameSync(PARCHEGGIO, FONT);
        }
        imposta('THEME_FONT_APP', prima.app);
        imposta('THEME_FONT_AUTH', prima.auth);
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
