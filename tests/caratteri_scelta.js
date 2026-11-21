/**
 * Cambiare carattere in Impostazioni › Aspetto (21/11, Alessandro).
 *
 * Con un carattere gia' scelto, l'elenco del campo proponeva solo quello:
 * entrando nel campo ora si svuota e l'elenco torna completo; uscendo senza
 * scegliere torna il carattere di prima, e Invio non salva il campo vuoto.
 * «Torna al carattere di sistema» (e «Torna ad Albert Sans» per le pagine
 * pubbliche) svuota il campo davvero, e compare solo con un
 * carattere scelto.
 *
 * Esecuzione:  node tests/caratteri_scelta.js   (server di sviluppo acceso)
 *
 * L'anteprima (21/11): sotto ogni campo, la frase nel carattere che si sta
 * scegliendo, prima di salvare; un carattere nuovo lo scarica il server, e
 * al Salva passa dall'anteprima alla cartella vera.
 *
 * Mette in `storage/fonts` Comfortaa e fra le anteprime Lora (il file di
 * Albert Sans con quei nomi), sceglie Comfortaa per l'applicazione, e alla
 * fine rimette file e impostazioni come li ha trovati.
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
    const ANTEPRIME = path.join(FONT, 'anteprime');
    const cerano = fs.existsSync(ANTEPRIME);
    fs.mkdirSync(ANTEPRIME, { recursive: true });
    const creati = [path.join(FONT, 'comfortaa.woff2'), path.join(ANTEPRIME, 'lora.woff2')].filter((f) => !fs.existsSync(f));
    creati.forEach((f) => fs.copyFileSync(ALBERT, f));
    const loraVero = path.join(FONT, 'lora.woff2');
    const loraCera = fs.existsSync(loraVero);
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
        check('con un carattere scelto: il nome nel campo e «Torna al carattere di sistema» accanto', r.valore === 'Comfortaa' && r.comando, [JSON.stringify(r)]);
        const nomi = await page.evaluate(() => ['font_interno', 'font_pubbliche'].map((c) => document.querySelector('[data-carattere-partenza="' + c + '"]')?.textContent.trim()));
        const intro = await page.evaluate(() => document.querySelector('details:has(#font_interno) > p.form-hint')?.textContent.replace(/\s+/g, ' ').trim() ?? '');
        check('ogni comando dice dove si torna, e la frase in cima lo ripete',
            nomi[0] === 'Torna al carattere di sistema' && nomi[1] === 'Torna ad Albert Sans'
                && intro.endsWith('Con il campo vuoto l\'applicazione usa il carattere di sistema e le pagine pubbliche Albert Sans.'), [JSON.stringify(nomi), intro]);
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
        const anteprima = (id) => page.evaluate((i) => {
            const a = document.querySelector('[data-anteprima="' + i + '"]');
            const t = a.querySelector('[data-anteprima-testo]');
            const v = a.querySelector('[data-anteprima-avviso]');
            return { nome: a.querySelector('[data-anteprima-nome]').textContent.trim(), famiglia: t.style.fontFamily,
                avviso: v.hidden ? null : v.textContent.trim() };
        }, id);
        let a = await anteprima('font_interno');
        check('l\'anteprima mostra il carattere salvato', a.nome === 'Comfortaa' && a.famiglia.includes('Comfortaa'), [JSON.stringify(a)]);

        await page.focus(campo);
        await page.keyboard.type('Lora');
        await page.waitForFunction(() => document.querySelector('[data-anteprima="font_interno"] [data-anteprima-nome]').textContent.trim() === 'Lora');
        a = await anteprima('font_interno');
        const caricato = await page.evaluate(() => document.fonts.check('16px "Anteprima Lora"'));
        check('scegliendo un carattere nuovo l\'anteprima cambia subito, con il file preparato dal server',
            a.famiglia.includes('Anteprima Lora') && caricato && a.avviso === null, [JSON.stringify(a), String(caricato)]);
        await page.focus('#font_pubbliche');
        r = await leggi();
        check('scrivendo un altro nome, resta quello', r.valore === 'Lora', [JSON.stringify(r)]);

        // Un carattere che non c'e' ne' sul server ne' fra le anteprime: il
        // server lo chiede a Google. Nel contenitore di sviluppo la rete e'
        // chiusa e l'anteprima lo deve dire; dove la rete c'e', deve
        // comparire il carattere.
        const stato = await page.evaluate(async () => (await fetch('/admin/settings/aspetto/anteprima-carattere?famiglia=Merriweather')).status);
        await page.fill('#font_pubbliche', 'Merriweather');
        await page.waitForFunction(() => !document.querySelector('[data-anteprima="font_pubbliche"] [data-anteprima-nome]').textContent.includes('caricamento'));
        a = await anteprima('font_pubbliche');
        check(stato === 200 ? 'un carattere scaricato adesso compare nell\'anteprima' : 'se il server non riesce a scaricarlo, l\'anteprima lo dice',
            stato === 200 ? a.famiglia.includes('Anteprima Merriweather') && a.avviso === null
                : a.avviso === 'Anteprima non disponibile: il server non è riuscito a scaricare il carattere.', [String(stato), JSON.stringify(a)]);
        await page.fill('#font_pubbliche', '');
        await page.focus(campo);
        await page.focus('#font_pubbliche');

        await salva();
        check('e si salva: il carattere passa dall\'anteprima alla cartella vera',
            salvato() === 'Lora' && fs.existsSync(loraVero) && !fs.existsSync(path.join(ANTEPRIME, 'lora.woff2')), [salvato()]);

        await page.click(comando);
        r = await leggi();
        check('«Torna al carattere di sistema» svuota il campo, torna il testo d\'esempio e il comando sparisce',
            r.valore === '' && r.esempio === 'carattere di sistema' && !r.comando, [JSON.stringify(r)]);
        a = await anteprima('font_interno');
        check('e l\'anteprima torna al carattere di sistema', a.nome === 'carattere di sistema' && a.famiglia.startsWith('-apple-system'), [JSON.stringify(a)]);
        await page.focus('#font_pubbliche');
        r = await leggi();
        check('uscendo dal campo resta vuoto', r.valore === '', [JSON.stringify(r)]);
        await salva();
        // Il campo vuoto cancella l'impostazione (o la lascia vuota): vale il carattere di sistema.
        check('salvando vale il carattere di sistema', ['', '\x00'].includes(salvato()), [JSON.stringify(salvato())]);
    } finally {
        await browser.close();
        creati.forEach((f) => fs.rmSync(f, { force: true }));
        if (!loraCera) {
            fs.rmSync(loraVero, { force: true });
        }
        fs.rmSync(path.join(ANTEPRIME, 'merriweather.woff2'), { force: true });
        if (!cerano) {
            fs.rmSync(ANTEPRIME, { recursive: true, force: true });
        }
        imposta('THEME_FONT_APP', prima.app);
        imposta('THEME_FONT_AUTH', prima.auth);
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
