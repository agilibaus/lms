/**
 * Che cosa c'e' in `storage/fonts/` (20/11, Alessandro).
 *
 * La sezione «Caratteri» di Impostazioni › Aspetto dice quali caratteri sono
 * sul server, ciascuno scritto nel suo carattere, e segnala i file che non
 * corrispondono a nessuna famiglia del catalogo (riquadro rifatto il 21/11).
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
    // La sezione «Sul server, pronti all'uso» del riquadro (21/11): le
    // voci, ciascuna con il suo carattere e il suo aspetto, e le frasi.
    const leggi = async () => {
        await page.goto(BASE + '/admin/settings/aspetto');
        return page.evaluate(() => {
            const s = document.querySelector('.caratteri-server');
            return {
                titolo: s.querySelector('.caratteri-server-titolo').textContent.trim(),
                frasi: [...s.querySelectorAll('p.form-hint')].map((p) => p.textContent.replace(/\s+/g, ' ').trim()),
                voci: [...s.querySelectorAll('.caratteri-voce')].map((v) => ({
                    testo: v.textContent.replace(/\s+/g, ' ').trim(),
                    famiglia: getComputedStyle(v).fontFamily,
                    bordo: getComputedStyle(v).borderTopStyle,
                    rosso: v.querySelector('em') ? getComputedStyle(v.querySelector('em')).color : null,
                })),
            };
        });
    };
    // La frase di Alessandro del 26/11.
    const NOTA = 'Se il server non può uscire su internet per raggiungere Google, '
        + 'il carattere va messo a mano nella directory storage/fonts/, in formato .woff2 e con il nome della famiglia '
        + 'in minuscolo, con i trattini al posto degli spazi: Playfair Display diventa playfair-display.woff2.';

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        imposta('THEME_FONT_APP', '\x00');
        imposta('THEME_FONT_AUTH', '\x00');

        cartella(null);
        let r = await leggi();
        check('cartella assente: «Ancora nessuno», nessuna voce, e la nota sugli hosting',
            r.titolo === 'Sul server, pronti all’uso' && r.voci.length === 0
                && JSON.stringify(r.frasi) === JSON.stringify(['Ancora nessuno: il primo arriva quando scegli un carattere e salvi.', NOTA]), [JSON.stringify(r)]);

        cartella(['lora.woff2']);
        r = await leggi();
        check('un carattere: una voce scritta nel suo carattere',
            r.voci.length === 1 && r.voci[0].testo === 'Lora' && r.voci[0].famiglia.startsWith('Lora')
                && r.frasi[0] === 'Si scelgono nei due campi qui sopra e nel menu Formato › Caratteri dell’editor.', [JSON.stringify(r)]);

        cartella(['playfair-display.woff2', 'albert-sans.woff2', 'Pippo.woff2', 'lora.ttf']);
        imposta('THEME_FONT_APP', 'Albert Sans');
        imposta('THEME_FONT_AUTH', 'Playfair Display');
        r = await leggi();
        check('due caratteri in ordine alfabetico, senza l\'indicazione d\'uso',
            JSON.stringify(r.voci.slice(0, 2).map((v) => v.testo)) === JSON.stringify(['Albert Sans', 'Playfair Display'])
                && r.voci[1].famiglia.startsWith('"Playfair Display"'), [JSON.stringify(r)]);
        check('i file sbagliati: bordo tratteggiato e «non riconosciuto» in rosso',
            JSON.stringify(r.voci.slice(2).map((v) => v.testo)) === JSON.stringify(['lora.ttf non riconosciuto', 'Pippo.woff2 non riconosciuto'])
                && r.voci.slice(2).every((v) => v.bordo === 'dashed' && v.rosso === 'rgb(185, 28, 28)'), [JSON.stringify(r)]);

        const campi = await page.evaluate(() => ({
            intro: document.querySelector('.caratteri-intro').textContent.replace(/\s+/g, ' ').trim(),
            aiuti: ['font_interno_aiuto', 'font_pubbliche_aiuto'].map((i) => document.getElementById(i).textContent.trim()),
        }));
        check('la frase in cima e le due frasi sotto i campi',
            campi.intro === 'Dal catalogo di Google Fonts. Quando si sceglie un carattere, è Pistacchio a scaricarlo una volta sola e poi a servirlo. '
                + 'Con il campo vuoto l\'applicazione usa il carattere di sistema e le pagine pubbliche Albert Sans.'
                && JSON.stringify(campi.aiuti) === JSON.stringify(['Corsi, lezioni, report.', 'Accesso, registrazione, password.']), [JSON.stringify(campi)]);
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
