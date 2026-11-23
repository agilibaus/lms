/**
 * Il riquadro «Sorveglianza esterna» in Impostazioni › Comandi pianificati
 * (23/11, Alessandro).
 *
 * Lo stato accanto al titolo («Spenta», «Attiva»), l'indirizzo per cui e'
 * stata salvata la chiave, la chiave che non torna mai al browser, il
 * segnale di prova e la protezione dell'indirizzo: una chiave salvata per
 * un altro sito resta spenta.
 *
 * Esecuzione:  node tests/sorveglianza_pannello.js   (database di sviluppo acceso)
 *
 * Accende un Healthchecks.io finto su 127.0.0.1:8125 e un secondo server di
 * sviluppo su 127.0.0.1:8126 che lo usa (`HEALTHCHECKS_BASE_URL`), cosi' il
 * segnale di prova non esce dal contenitore; alla fine li spegne e rimette
 * le due impostazioni come le ha trovate.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync, spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const BASE = 'http://127.0.0.1:8126';
const CHIAVE = 'chiaveDelPannelloDiProva1';
const REGISTRO = path.join(os.tmpdir(), 'hc_finto_pannello.log');
const SCONOSCIUTI = path.join(os.tmpdir(), 'hc_finto_pannello_404.txt');

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
const imposta = (k, v) => (v === '\x00'
    ? esegui('DELETE FROM settings WHERE setting_key = ?', k)
    : esegui('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', k, v));
const aspetta = (ms) => new Promise((r) => setTimeout(r, ms));
const chiamate = () => (fs.existsSync(REGISTRO) ? fs.readFileSync(REGISTRO, 'utf8').split('\n').filter(Boolean).map((r) => JSON.parse(r)) : []);

(async () => {
    const prima = { chiave: uno("SELECT setting_value FROM settings WHERE setting_key = 'HEALTHCHECKS_PING_KEY'"),
        per: uno("SELECT setting_value FROM settings WHERE setting_key = 'HEALTHCHECKS_PER_URL'") };
    imposta('HEALTHCHECKS_PING_KEY', '\x00');
    imposta('HEALTHCHECKS_PER_URL', '\x00');
    fs.rmSync(REGISTRO, { force: true });
    fs.writeFileSync(SCONOSCIUTI, '');
    const ambiente = { ...process.env, HC_FINTO_REGISTRO: REGISTRO, HC_FINTO_SCONOSCIUTI: SCONOSCIUTI, HEALTHCHECKS_BASE_URL: 'http://127.0.0.1:8125' };
    const servizio = spawn('php', ['-S', '127.0.0.1:8125', 'tests/aiuti/healthchecks_finto.php'], { cwd: RADICE, env: ambiente, stdio: 'ignore' });
    const sito = spawn('php', ['-S', '127.0.0.1:8126', '-t', 'public', 'router-dev.php'], { cwd: RADICE, env: ambiente, stdio: 'ignore' });
    await aspetta(1200);

    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    const riquadro = () => page.evaluate(() => {
        const f = document.querySelector('form.sorveglianza');
        const h = f.querySelector('.sorveglianza-testa h2').getBoundingClientRect();
        const b = f.querySelector('.sorveglianza-testa .badge');
        const r = b.getBoundingClientRect();
        return {
            stato: b.textContent.trim(),
            accanto: r.left > h.right && r.top < h.bottom && r.bottom > h.top,
            testo: f.innerText.replace(/\s+/g, ' '),
            prova: f.querySelector('button[formaction="/admin/settings/comandi/prova"]') !== null,
            cancella: f.querySelector('input[name="cancella_chiave"]') !== null,
            avviso: document.querySelector('.alert-success, .alert-danger, .alert-error')?.textContent.replace(/\s+/g, ' ').trim() ?? '',
        };
    });
    const salva = async (chiave, cancella = false) => {
        if (chiave !== null) await page.fill('#HEALTHCHECKS_PING_KEY', chiave);
        if (cancella) await page.check('input[name="cancella_chiave"]');
        await Promise.all([page.waitForNavigation(), page.click('form.sorveglianza button[type="submit"]:not([formaction])')]);
    };

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');
        await page.goto(BASE + '/admin/settings/comandi');

        let r = await riquadro();
        check('senza chiave: «Spenta» accanto al titolo, e niente segnale di prova',
            r.stato === 'Spenta' && r.accanto && r.testo.includes('Nessuna chiave') && !r.prova && !r.cancella, [JSON.stringify(r)]);

        await salva('https://hc-ping.com/' + CHIAVE);
        r = await riquadro();
        check('un indirizzo intero al posto della chiave si rifiuta, e resta spenta',
            r.stato === 'Spenta' && r.avviso.includes('non sembra una chiave'), [JSON.stringify(r)]);

        await salva(CHIAVE);
        r = await riquadro();
        const html = await page.content();
        check('salvata: «Attiva», con l\'indirizzo per cui è stata salvata',
            r.stato === 'Attiva' && r.accanto && r.testo.includes('Chiave salvata per http://127.0.0.1:8123') && r.prova && r.cancella, [JSON.stringify(r)]);
        check('la chiave non torna al browser', !html.includes(CHIAVE));

        await salva('');
        r = await riquadro();
        check('il campo vuoto lascia la chiave di prima',
            r.stato === 'Attiva' && uno("SELECT setting_value FROM settings WHERE setting_key = 'HEALTHCHECKS_PING_KEY'") === CHIAVE, [JSON.stringify(r)]);

        fs.writeFileSync(SCONOSCIUTI, 'pistacchio-rilascio\n');
        fs.rmSync(REGISTRO, { force: true });
        await Promise.all([page.waitForNavigation(), page.click('button[formaction="/admin/settings/comandi/prova"]')]);
        r = await riquadro();
        const c = chiamate().map((x) => x.uri);
        check('il segnale di prova va a tutti e quattro i check, e dice quale non c\'è',
            c.length === 4 && c.every((u) => u.startsWith('/' + CHIAVE + '/pistacchio-'))
                && r.avviso.includes('arrivato a pistacchio-avvisi, pistacchio-inviti, pistacchio-posta')
                && r.avviso.includes('nessun check trovato per pistacchio-rilascio'), [JSON.stringify(c), r.avviso]);
        fs.writeFileSync(SCONOSCIUTI, '');
        await Promise.all([page.waitForNavigation(), page.click('button[formaction="/admin/settings/comandi/prova"]')]);
        r = await riquadro();
        check('con tutti i check a posto: arrivato a tutti e 4', r.avviso.startsWith('Segnale di prova arrivato a tutti e 4 i check'), [r.avviso]);

        imposta('HEALTHCHECKS_PER_URL', 'https://lms.movimente.it');
        fs.rmSync(REGISTRO, { force: true });
        await page.goto(BASE + '/admin/settings/comandi');
        r = await riquadro();
        check('la chiave salvata per un altro indirizzo: «Spenta», con l\'avviso e senza segnale di prova',
            r.stato === 'Spenta' && r.testo.includes('Chiave salvata per https://lms.movimente.it: su questo indirizzo (http://127.0.0.1:8123) la sorveglianza è spenta')
                && !r.prova && r.cancella, [JSON.stringify(r)]);
        const rifiuto = await page.evaluate(async () => {
            const t = document.querySelector('form.sorveglianza input[name="_token"]');
            const dati = new FormData();
            dati.append('_token', t ? t.value : '');
            const risposta = await fetch('/admin/settings/comandi/prova', { method: 'POST', body: dati });
            return { token: t !== null, testo: await risposta.text() };
        });
        check('e un segnale di prova mandato a mano (con il token giusto) non parte',
            rifiuto.token && rifiuto.testo.includes('spenta su questo indirizzo') && chiamate().length === 0,
            [JSON.stringify({ token: rifiuto.token, chiamate: chiamate() })]);

        await salva(null, true);
        r = await riquadro();
        check('«Cancella la chiave salvata»: di nuovo spenta, chiave e indirizzo tolti',
            r.stato === 'Spenta' && uno("SELECT COUNT(*) FROM settings WHERE setting_key IN ('HEALTHCHECKS_PING_KEY', 'HEALTHCHECKS_PER_URL')") === '0', [JSON.stringify(r)]);
    } finally {
        await browser.close();
        servizio.kill();
        sito.kill();
        imposta('HEALTHCHECKS_PING_KEY', prima.chiave);
        imposta('HEALTHCHECKS_PER_URL', prima.per);
        fs.rmSync(REGISTRO, { force: true });
        fs.rmSync(SCONOSCIUTI, { force: true });
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
