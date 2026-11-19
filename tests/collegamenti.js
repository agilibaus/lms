/**
 * I collegamenti interni (19/11, Alessandro): nessuna pagina deve mostrare
 * un collegamento che porta a una pagina che non c'e' (404), che si rompe
 * (500) o che chi lo vede non puo' aprire (403).
 *
 * Le altre prove guardano la porta di ogni pagina (`rotte.js`: chi la
 * apre); questa guarda i collegamenti dentro le pagine, ruolo per ruolo.
 * Il primo giro ha trovato due difetti cosi': nel report di un incontro il
 * supervisore vedeva «pagina dell'incontro», che non puo' aprire; nella
 * fruizione di un video chi non modifica la lezione vedeva «Modifica
 * lezione».
 *
 * COME. Per ogni ruolo (anonimo, studente, assistente, tutor, supervisore,
 * Amministratore) si visitano le pagine seguendo i collegamenti, come in
 * `scala_testi.js`: una per forma (/courses/1 e /courses/2 sono la stessa
 * pagina), fino a 600. Di ogni pagina si raccolgono TUTTI i collegamenti
 * interni, e ogni indirizzo diverso si chiede una volta, con la sessione di
 * quel ruolo: e' un errore una risposta 400 o piu', o un rimando
 * all'accesso per chi l'accesso l'ha fatto.
 *
 * FUORI, per scelta:
 * - «Entra» negli incontri (/live/{id}/join) e lo scaricamento dei
 *   materiali (/materials/{id}/download): scrivono la presenza e
 *   «scaricato», e una prova non scrive a nome di nessuno;
 * - i file caricati (certificati, Bacheca, audio, foto, copertine, immagini
 *   delle lezioni, H5P): nel contenitore di
 *   prova molti non ci sono, e un 404 lì vuol dire «file mancante», non un
 *   collegamento sbagliato. Un 403 o un 500 invece vale anche per loro;
 * - gli indirizzi fuori da Pistacchio (Meet, WhatsApp, Bunny, Google).
 *
 * Crea un supervisore di prova (supervisore@collegamenti.test) e alla fine
 * lo toglie. Rifa' la semina dei permessi all'inizio.
 *
 * Esecuzione:  node tests/collegamenti.js   (server di sviluppo acceso)
 * Dura poco piu' di un minuto. Si lancia con le patch che toccano pagine,
 * rotte o collegamenti.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const SUPERVISORE = 'supervisore@collegamenti.test';

/** Non si seguono: scrivono qualcosa a nome di chi prova. */
const NON_SI_SEGUONO = [/^\/live\/\d+\/join\b/, /^\/materials\/\d+\/download\b/];

/** File caricati: un 404 qui e' un file che nel contenitore non c'e'. */
const FILE = [
    /^\/certificates\/\d+\/download\b/,
    /^\/bacheca\/risorse\/\d+\/download\b/,
    /^\/benvenuti\/\d+\/audio\b/,
    /^\/utenti\/\d+\/immagine\b/,
    /^\/gruppi\/\d+\/immagine\b/,
    /^\/corsi\/\d+\/copertina\b/,
    /^\/lessons\/\d+\/images\//,
    /^\/(quizzes|lessons)\/\d+\/h5p\//,
];

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 12).forEach((d) => console.log('         · ' + d));
    }
}

function php(codice, ...argomenti) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();' + codice, '--', ...argomenti.map(String)], { cwd: RADICE, encoding: 'utf8' });
}

// Una pagina per forma, come in scala_testi.js.
const forma = (u) => u.split('#')[0].replace(/\d+/g, 'N').replace(/([?&](da_id|torna|da|ordina|verso|giorni|pagina|cerca|q)=)[^&]*/g, '$1x');

async function giro(browser, ruolo, email) {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('dialog', (d) => d.dismiss());

    if (email) {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');
    }

    // Le pagine, seguendo i collegamenti.
    const coda = email ? ['/'] : ['/login', '/register', '/password/dimenticata', '/register/rinvia', '/privacy'];
    const viste = new Set();
    const trovati = new Map(); // indirizzo -> prima pagina in cui compare
    let pagine = 0;

    while (coda.length && pagine < 600) {
        const u = coda.shift();
        if (viste.has(forma(u))) continue;
        viste.add(forma(u));

        let r;
        try {
            r = await page.goto(BASE + u, { waitUntil: 'domcontentloaded', timeout: 15000 });
        } catch (e) {
            continue;
        }
        if (!r || r.status() >= 400 || !(r.headers()['content-type'] || '').includes('text/html') || !page.url().startsWith(BASE)) continue;
        pagine++;

        const dove = page.url().replace(BASE, '');
        for (const h of await page.$$eval('a[href]', (as) => as.map((a) => a.href))) {
            if (!h.startsWith(BASE + '/')) continue;
            const u2 = h.slice(BASE.length).split('#')[0];
            if (!trovati.has(u2)) trovati.set(u2, dove);
            if (!NON_SI_SEGUONO.some((x) => x.test(u2))) coda.push(u2);
        }
    }

    // Ogni indirizzo diverso, una volta.
    const sbagliati = [];
    let provati = 0;
    for (const [u, da] of trovati) {
        if (NON_SI_SEGUONO.some((x) => x.test(u))) continue;
        provati++;

        let stato;
        let finale = '';
        try {
            const r = await ctx.request.get(BASE + u, { timeout: 20000 });
            stato = r.status();
            finale = r.url().replace(BASE, '');
        } catch (e) {
            stato = 'nessuna risposta';
        }

        if (stato === 404 && FILE.some((x) => x.test(u))) continue;
        if (typeof stato !== 'number' || stato >= 400) {
            sbagliati.push(stato + '  ' + u + '   (in ' + da + ')');
        } else if (email && finale.startsWith('/login')) {
            sbagliati.push('rimanda all\'accesso  ' + u + '   (in ' + da + ')');
        }
    }

    await ctx.close();

    console.log('\n' + ruolo + ': ' + pagine + ' pagine, ' + provati + ' collegamenti diversi');
    // Un giro che non trova niente non prova niente.
    check(ruolo + ': il giro ha trovato pagine e collegamenti', pagine > 0 && provati > 0);
    check(ruolo + ': ogni collegamento porta a una pagina che si apre', sbagliati.length === 0, sbagliati);
}

(async () => {
    execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php')], { cwd: RADICE, encoding: 'utf8' });
    php('$pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);'
        + ' $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at, name_display)'
        + ' VALUES (?, ?, \'Supervisore\', \'Collegamenti\', \'supervisore\', 1, NOW(), NOW(), \'full\')")'
        + '->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT)]);', SUPERVISORE, PASSWORD);

    const browser = await chromium.launch();
    try {
        for (const [ruolo, email] of [['anonimo', null], ['studente', 'stud@test.it'], ['assistente', 'assist@test.it'],
            ['tutor', 'tutor1@test.it'], ['supervisore', SUPERVISORE], ['admin', 'admin@test.it']]) {
            await giro(browser, ruolo, email);
        }
    } finally {
        await browser.close();
        php('$pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', SUPERVISORE);
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail > 0 ? 1 : 0);
})();
