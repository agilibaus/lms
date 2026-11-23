/**
 * «Comandi pianificati» nelle Impostazioni (17/11, Alessandro).
 *
 * Solo l'Amministratore vede la pagina, il punto esclamativo sul riquadro
 * delle Impostazioni e il numero accanto a «Impostazioni» nel menu. Il
 * numero conta i comandi fermi o mai partiti; il punto esclamativo c'e' se
 * ce n'e' almeno uno.
 *
 * Le date delle ultime esecuzioni si scrivono nel database per la prova e
 * alla fine tornano com'erano.
 *
 * COSA VERIFICA:
 *
 *   1. **La pagina**: una riga per comando, con le colonne «Frequenza» e
 *      «Ultima esecuzione», e i tre stati: «Regolare», «Fermo», «Mai partito»
 *      (con la maiuscola dal 23/11, come tutte le etichette di stato).
 *   2. **L'avviso**: con un comando fermo e uno mai partito il menu dice 2 e
 *      il riquadro ha il punto esclamativo; con tutto regolare, niente.
 *   3. **Gli altri ruoli**: supervisore, tutor e assistente non aprono la
 *      pagina, non vedono il riquadro ne' il numero.
 *
 * Esecuzione:  node tests/comandi_pianificati.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const CHIAVI = ['AVVISI_LAST_RUN_AT', 'INVITES_LAST_RUN_AT', 'DRIP_LAST_RUN_AT'];
const SUPERVISORE = 'supervisore-comandi@test.it';

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

/** Le tre date com'erano: null se la chiave non c'era. */
function leggi() {
    return JSON.parse(php('$s = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN (\'AVVISI_LAST_RUN_AT\', \'INVITES_LAST_RUN_AT\', \'DRIP_LAST_RUN_AT\')");'
        + ' echo json_encode($s->fetchAll(PDO::FETCH_KEY_PAIR) ?: new stdClass());'));
}

/** Scrive le tre date: un numero e' «tanti secondi fa», null toglie la chiave. */
function scrivi(valori) {
    php('$v = json_decode($argv[1], true);'
        + ' foreach ($v as $k => $x) {'
        + '   $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);'
        + '   if ($x === null) { continue; }'
        + '   $d = is_int($x) ? date("Y-m-d H:i:s", time() - $x) : $x;'
        + '   $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $d]);'
        + ' }', JSON.stringify(valori));
}

async function entra(browser, email) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/login')) {
        throw new Error('accesso non riuscito per ' + email);
    }
    return { ctx, page };
}

/** Il numero accanto a «Impostazioni» nel menu, e il riquadro con il suo avviso. */
async function avviso(page) {
    await page.goto(BASE + '/admin/settings');
    return page.evaluate(() => {
        const voce = document.querySelector('a.nav-link[href="/admin/settings"] .nav-conteggio');
        const riquadro = document.querySelector('a.settings-card[href="/admin/settings/comandi"]');
        return {
            numero: voce ? voce.firstChild.textContent.trim() : null,
            riquadro: riquadro !== null,
            esclamativo: riquadro !== null && riquadro.querySelector('.settings-card-avviso') !== null,
        };
    });
}

(async () => {
    const prima = leggi();
    php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at)'
        + ' VALUES (?, ?, \'Super\', \'Comandi\', \'supervisore\', 1, NOW(), NOW())'
        + ' ON DUPLICATE KEY UPDATE role = \'supervisore\', is_active = 1")->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT)]);', SUPERVISORE, PASSWORD);
    const browser = await chromium.launch();

    try {
        const admin = await entra(browser, 'admin@test.it');
        const page = admin.page;

        // --- 1. la pagina ------------------------------------------------------
        console.log('\nLa pagina');
        scrivi({ AVVISI_LAST_RUN_AT: 120, INVITES_LAST_RUN_AT: 31 * 60, DRIP_LAST_RUN_AT: null });
        const risposta = await page.goto(BASE + '/admin/settings/comandi');
        const t = await page.evaluate(() => ({
            colonne: [...document.querySelectorAll('table.comandi-pianificati thead th')].map((th) => th.textContent.trim()),
            righe: [...document.querySelectorAll('table.comandi-pianificati tbody tr')].map((tr) => [...tr.cells].map((c) => c.textContent.replace(/\s+/g, ' ').trim())),
        }));
        check('l\'Amministratore apre la pagina', risposta.status() === 200);
        check('le colonne: Comando, Che cosa fa, Frequenza, Ultima esecuzione, Stato',
            JSON.stringify(t.colonne) === JSON.stringify(['Comando', 'Che cosa fa', 'Frequenza', 'Ultima esecuzione', 'Stato']), [JSON.stringify(t.colonne)]);
        const [avvisi, inviti, rilascio] = t.righe;
        check('avvisi di 2 minuti fa: regolare', avvisi && avvisi[0].startsWith('Avvisi') && avvisi[3].includes('2 minuti fa') && avvisi[4] === 'Regolare', [JSON.stringify(avvisi)]);
        check('inviti di 31 minuti fa: fermo', inviti && inviti[0].startsWith('Inviti') && inviti[3].includes('31 minuti fa') && inviti[4] === 'Fermo', [JSON.stringify(inviti)]);
        check('rilascio senza data: «mai», mai partito', rilascio && rilascio[0].startsWith('Rilascio dei moduli') && rilascio[3] === 'mai' && rilascio[4] === 'Mai partito', [JSON.stringify(rilascio)]);

        // --- 2. l'avviso -------------------------------------------------------
        console.log('\nL\'avviso');
        let a = await avviso(page);
        check('un fermo e un mai partito: «2» nel menu e il punto esclamativo sul riquadro', a.numero === '2' && a.esclamativo, [JSON.stringify(a)]);
        scrivi({ DRIP_LAST_RUN_AT: 47 * 3600 });
        a = await avviso(page);
        check('il rilascio di 47 ore fa e\' regolare: «1»', a.numero === '1' && a.esclamativo, [JSON.stringify(a)]);
        scrivi({ INVITES_LAST_RUN_AT: 60 });
        a = await avviso(page);
        check('tutto regolare: niente numero, niente punto esclamativo', a.numero === null && a.riquadro && !a.esclamativo, [JSON.stringify(a)]);
        scrivi({ DRIP_LAST_RUN_AT: 49 * 3600 });
        a = await avviso(page);
        check('il rilascio di 49 ore fa e\' fermo: «1»', a.numero === '1' && a.esclamativo, [JSON.stringify(a)]);
        await admin.ctx.close();

        // --- 3. gli altri ruoli ------------------------------------------------
        console.log('\nGli altri ruoli');
        scrivi({ AVVISI_LAST_RUN_AT: null });
        for (const email of [SUPERVISORE, 'tutor1@test.it', 'assist@test.it']) {
            const { ctx, page: p } = await entra(browser, email);
            const r = await p.goto(BASE + '/admin/settings/comandi');
            const stato = r.status();
            // Il menu si guarda su una pagina vera: la risposta 403 non ce l'ha.
            // Le Impostazioni per chi le apre (il supervisore), la pagina
            // iniziale per gli altri.
            let riquadro = false;
            const ri = await p.goto(BASE + '/admin/settings');
            if (ri.status() === 200) {
                riquadro = await p.evaluate(() => document.querySelector('a.settings-card[href="/admin/settings/comandi"]') !== null);
            } else {
                await p.goto(BASE + '/');
            }
            const v = await p.evaluate(() => ({
                menu: document.querySelector('nav') !== null,
                numero: document.querySelector('a.nav-link[href="/admin/settings"] .nav-conteggio') !== null,
            }));
            check(email + ': la pagina risponde 403, niente numero nel menu, niente riquadro', stato === 403 && v.menu && !v.numero && !riquadro,
                ['stato ' + stato + ', ' + JSON.stringify(v) + ', riquadro ' + riquadro]);
            await ctx.close();
        }
    } finally {
        await browser.close();
        scrivi(Object.fromEntries(CHIAVI.map((k) => [k, prima[k] ?? null])));
        php('$pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', SUPERVISORE);
    }

    const dopo = leggi();
    check('le date sono tornate com\'erano', JSON.stringify(CHIAVI.map((k) => prima[k] ?? null)) === JSON.stringify(CHIAVI.map((k) => dopo[k] ?? null)));

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
