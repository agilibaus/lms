/**
 * Cambiare carattere in Impostazioni › Aspetto (21/11, Alessandro).
 *
 * Con un carattere gia' scelto, l'elenco del campo proponeva solo quello:
 * entrando nel campo ora si svuota e l'elenco torna completo; uscendo senza
 * scegliere torna il carattere di prima, e Invio non salva il campo vuoto.
 * «Carattere di partenza» svuota il campo davvero, e compare solo con un
 * carattere scelto.
 *
 * Esecuzione:  node tests/caratteri_scelta.js   (server di sviluppo acceso)
 *
 * Mette in `storage/fonts` due caratteri «scaricati» (il file di Albert Sans
 * con il nome di Comfortaa e di Lora), sceglie Comfortaa per l'applicazione,
 * e alla fine rimette file e impostazioni come li ha trovati.
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
const salvato = () => uno("SELECT setting_value FROM settings WHERE setting_key = 'THEME_FONT_APP'");

(async () => {
    const prima = { app: salvato(), auth: uno("SELECT setting_value FROM settings WHERE setting_key = 'THEME_FONT_AUTH'") };
    fs.mkdirSync(FONT, { recursive: true });
    const creati = ['comfortaa.woff2', 'lora.woff2'].map((f) => path.join(FONT, f)).filter((f) => !fs.existsSync(f));
    creati.forEach((f) => fs.copyFileSync(ALBERT, f));
    imposta('THEME_FONT_APP', 'Comfortaa');
    imposta('THEME_FONT_AUTH', '\x00');

    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    const campo = '#font_interno';
    const comando = '[data-carattere-partenza="font_interno"]';
    const leggi = () => page.evaluate(([c, k]) => {
        const i = document.querySelector(c);
        const b = document.querySelector(k);
        return { valore: i.value, esempio: i.placeholder, comando: b !== null && !b.hidden && b.offsetParent !== null };
    }, [campo, comando]);
    const salva = async () => {
        await Promise.all([page.waitForNavigation(), page.click('form.aspetto-modulo button[type="submit"]:not([name])')]);
    };

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');
        await page.goto(BASE + '/admin/settings/aspetto');

        let r = await leggi();
        check('con un carattere scelto: il nome nel campo e «Carattere di partenza» accanto', r.valore === 'Comfortaa' && r.comando, [JSON.stringify(r)]);
        const altro = await page.evaluate(() => {
            const b = document.querySelector('[data-carattere-partenza="font_pubbliche"]');
            return b !== null && !b.hidden && b.offsetParent !== null;
        });
        check('senza carattere scelto il comando non c\'è', !altro);

        await page.focus(campo);
        r = await leggi();
        check('entrando nel campo si svuota, e il nome passa nel testo d\'esempio', r.valore === '' && r.esempio === 'Comfortaa' && r.comando, [JSON.stringify(r)]);

        await page.focus('#font_pubbliche');
        r = await leggi();
        check('uscendo senza scegliere torna il carattere di prima', r.valore === 'Comfortaa' && r.esempio === 'carattere di sistema', [JSON.stringify(r)]);

        await page.focus(campo);
        await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
        const avviso = await page.locator('.alert-success').textContent().catch(() => '');
        check('Invio con il campo appena svuotato salva, ma non toglie il carattere',
            salvato() === 'Comfortaa' && avviso.includes('Aspetto salvato'), [salvato(), avviso]);

        await page.goto(BASE + '/admin/settings/aspetto');
        await page.focus(campo);
        await page.keyboard.type('Lora');
        await page.focus('#font_pubbliche');
        r = await leggi();
        check('scrivendo un altro nome, resta quello', r.valore === 'Lora', [JSON.stringify(r)]);
        await salva();
        check('e si salva', salvato() === 'Lora', [salvato()]);

        await page.click(comando);
        r = await leggi();
        check('«Carattere di partenza» svuota il campo, torna il testo d\'esempio e il comando sparisce',
            r.valore === '' && r.esempio === 'carattere di sistema' && !r.comando, [JSON.stringify(r)]);
        await page.focus('#font_pubbliche');
        r = await leggi();
        check('uscendo dal campo resta vuoto', r.valore === '', [JSON.stringify(r)]);
        await salva();
        // Il campo vuoto cancella l'impostazione (o la lascia vuota): vale il carattere di partenza.
        check('salvando vale il carattere di partenza', ['', '\x00'].includes(salvato()), [JSON.stringify(salvato())]);
    } finally {
        await browser.close();
        creati.forEach((f) => fs.rmSync(f, { force: true }));
        imposta('THEME_FONT_APP', prima.app);
        imposta('THEME_FONT_AUTH', prima.auth);
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
