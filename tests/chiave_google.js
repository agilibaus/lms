/**
 * La chiave di Google caricata dal pannello (17/11, Alessandro, strada B).
 *
 * Caricando la chiave da Impostazioni › Google Meet, il file va in
 * `storage/google` e in tabella si salva **solo il suo nome**, non il
 * percorso completo: cosi' il database si puo' copiare su un altro server
 * (con la cartella `storage`) e Meet continua a funzionare. «Rimuovi la
 * chiave» cancella il file e l'impostazione.
 *
 * Se nel database di sviluppo c'e' una chiave vera, con il suo file, la
 * prova non parte: caricarne una nuova cancellerebbe quella vecchia.
 *
 * Esecuzione:  node tests/chiave_google.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const CARTELLA = path.join(RADICE, 'storage', 'google');
const ACCOUNT = 'prova-pannello@progetto.iam.gserviceaccount.com';

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

const inTabella = () => php('$v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = \'GOOGLE_SERVICE_ACCOUNT_JSON\'")->fetchColumn(); echo json_encode($v);').trim();

(async () => {
    if (php('echo is_file(App\\Core\\Google\\ChiaveGoogle::percorso()) ? "1" : "0";').trim() === '1') {
        console.log('C\'e\' una chiave di Google vera: la prova non la tocca.');
        process.exit(0);
    }

    const prima = JSON.parse(inTabella());
    const file = path.join(os.tmpdir(), 'chiave-prova.json');
    fs.writeFileSync(file, JSON.stringify({ client_email: ACCOUNT, private_key: 'non-serve-per-la-prova' }));
    const browser = await chromium.launch();

    try {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        // --- il caricamento ----------------------------------------------------
        await page.goto(BASE + '/admin/settings/meet');
        await page.setInputFiles('#key', file);
        await page.click('form[action="/admin/settings/meet"] button[type="submit"]');
        await page.waitForLoadState('networkidle');

        const salvato = JSON.parse(inTabella());
        check('in tabella solo il nome del file, senza percorso', typeof salvato === 'string' && /^[a-f0-9]{32}\.json$/.test(salvato), [String(salvato)]);
        const sulDisco = typeof salvato === 'string' && fs.existsSync(path.join(CARTELLA, salvato));
        check('il file e\' in storage/google', sulDisco);
        const pagina = await page.content();
        check('il pannello mostra l\'account della chiave', pagina.includes(ACCOUNT));

        // --- la rimozione ------------------------------------------------------
        page.once('dialog', (d) => d.accept());
        await page.click('form[action="/admin/settings/meet/chiave/elimina"] button[type="submit"]');
        await page.waitForLoadState('networkidle');
        check('«Rimuovi la chiave» toglie l\'impostazione e il file',
            JSON.parse(inTabella()) === false && !(typeof salvato === 'string' && fs.existsSync(path.join(CARTELLA, salvato))));
        await ctx.close();
    } finally {
        await browser.close();
        fs.unlinkSync(file);
        php('$pdo->prepare("DELETE FROM settings WHERE setting_key = \'GOOGLE_SERVICE_ACCOUNT_JSON\'")->execute();'
            + ' if ($argv[1] !== "false") { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (\'GOOGLE_SERVICE_ACCOUNT_JSON\', ?)")->execute([json_decode($argv[1])]); }',
            JSON.stringify(prima));
    }

    check('l\'impostazione e\' tornata com\'era', JSON.stringify(JSON.parse(inTabella())) === JSON.stringify(prima));

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
