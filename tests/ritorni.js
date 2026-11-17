/**
 * I collegamenti «indietro» che tornano alla pagina di partenza (17/11,
 * Alessandro).
 *
 * Il controllo dei collegamenti in alto aveva trovato pagine che si aprono
 * da piu' parti e tornavano sempre in una sola. Adesso il collegamento
 * verso la pagina dice da dove parte (`App\Core\Ritorno`) e la pagina ci
 * torna; il testo del collegamento e' il titolo della pagina a cui torna.
 *
 * Per ogni percorso: si apre la pagina di partenza, con dei parametri
 * (ordinamento, filtri, vista), si segue il collegamento, si clicca
 * l'«indietro» e si controlla di essere **esattamente** dove si era
 * partiti, e che il testo dell'«indietro» fosse il titolo di quella pagina.
 *
 * COSA VERIFICA:
 *
 *   1. **La fruizione di una lezione** aperta dal report «Fruizione dei
 *      video» torna al report, anche per il supervisore (prima: 403).
 *   2. **Report › un elenco › il dettaglio** torna all'elenco, con la sua
 *      ricerca e il suo ordinamento: corsi, gruppi, studenti, sessioni live,
 *      video, e la griglia aperta dall'elenco dei corsi — anche dopo aver
 *      cambiato modulo nella griglia o scheda nel report dello studente.
 *   3. **Il tentativo** e **il report dell'incontro** aperti dal report di
 *      uno studente tornano allo studente; **il gruppo** aperto dal corso
 *      torna al corso, dalla gestione dei gruppi alla gestione; **l'incontro**
 *      aperto dall'Agenda, dal gruppo o dal suo report torna li'.
 *   4. **La scheda di un corso** aperta da quella di un gruppo, **la scheda
 *      di un gruppo** aperta da quella di un utente, **«Nuova risorsa»** e
 *      **«Modifica»** aperte da una categoria della Bacheca tornano li'.
 *   5. **Le controprove**: senza partenza, l'«indietro» di sempre; una
 *      partenza che la pagina non accetta, o con l'id di un'altra cosa, si
 *      ignora.
 *
 * Esecuzione:  node tests/ritorni.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const SUPERVISORE = 'supervisore-ritorni@test.it';

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

function semina() {
    return JSON.parse(execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php')], { encoding: 'utf8', cwd: RADICE }));
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

const vai = (page, url) => page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
const qui = (page) => page.url().replace(BASE, '');
const titolo = (page) => page.evaluate(() => ((document.querySelector('main h1') || {}).textContent || '').replace(/\s+/g, ' ').trim());
const indietro = (page) => page.evaluate(() => {
    const a = document.querySelector('main .back-link');
    return a ? { href: a.getAttribute('href'), testo: a.textContent.replace(/\s+/g, ' ').trim() } : null;
});

/**
 * Dalla partenza, il collegamento `selettore`, poi l'«indietro»: si deve
 * tornare all'indirizzo di partenza, e il testo dell'«indietro» deve essere
 * il titolo della pagina di partenza. `prima` fa qualcosa sulla pagina di
 * arrivo prima di tornare (cambiare scheda, modulo).
 */
async function giro(page, nome, partenza, selettore, prima = null) {
    await vai(page, partenza);
    const atteso = qui(page);
    const titoloPartenza = await titolo(page);
    const link = page.locator(selettore).first();

    if (await link.count() === 0) {
        check(nome + ': c\'e\' il collegamento', false, ['nessun ' + selettore + ' in ' + partenza]);
        return;
    }

    await Promise.all([page.waitForURL((u) => u.href !== BASE + atteso), link.click()]);
    await page.waitForLoadState('domcontentloaded');
    const arrivo = qui(page);

    if (prima !== null) {
        await prima(page);
    }

    const b = await indietro(page);
    const lasciata = page.url();
    await Promise.all([page.waitForURL((u) => u.href !== lasciata), page.click('main .back-link')]);
    await page.waitForLoadState('domcontentloaded');
    const tornato = qui(page);
    const bene = tornato === atteso && b !== null && b.testo === titoloPartenza;
    check(nome + ': «‹ ' + (b ? b.testo : '?') + '» torna a ' + atteso, bene,
        ['arrivo ' + arrivo, 'tornato a ' + tornato, 'titolo della partenza «' + titoloPartenza + '»']);
}

(async () => {
    const d = semina();
    const A = d.A;
    php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at)'
        + ' VALUES (?, ?, \'Super\', \'Ritorni\', \'supervisore\', 1, NOW(), NOW())'
        + ' ON DUPLICATE KEY UPDATE role = \'supervisore\', is_active = 1")->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT)]);', SUPERVISORE, PASSWORD);
    const browser = await chromium.launch();

    try {
        const admin = await entra(browser, 'admin@test.it');
        const a = admin.page;

        // --- 1. la fruizione della lezione ------------------------------------
        console.log('\n1. La fruizione della lezione');
        const sup = await entra(browser, SUPERVISORE);
        // Il corso 1 dei dati di sviluppo, che ha lezioni con un video (lo
        // stesso di accessibilita.js): i corsi di prova non ne hanno.
        await giro(sup.page, 'supervisore, Fruizione dei video › lezione', '/reports/fruizione/1',
            'main a[href*="/fruizione?da=report-fruizione"]');
        await vai(sup.page, '/reports/fruizione/1');
        const lezione = await sup.page.locator('main a[href*="/fruizione?da=report-fruizione"]').first().getAttribute('href');
        await vai(sup.page, lezione);
        const b = await indietro(sup.page);
        const r = await vai(sup.page, b.href);
        check('supervisore: la pagina a cui torna si apre (prima: 403)', r.status() === 200 && b.href.startsWith('/reports/fruizione/1'), ['stato ' + r.status() + ', ' + b.href]);
        await sup.ctx.close();

        // --- 2. Report › elenco › dettaglio -----------------------------------
        console.log('\n2. Report › elenco › dettaglio');
        for (const [elenco, nome] of [['courses', 'Corsi'], ['groups', 'Gruppi'], ['students', 'Studenti'], ['live', 'Sessioni live'], ['fruizione', 'Video']]) {
            await giro(a, 'Report › ' + nome + ' › Dettaglio', '/reports/elenco/' + elenco + '?verso=disc',
                'main td.col-azioni a:text-is("Dettaglio")');
        }
        await giro(a, 'Report › Corsi › Griglia, cambiando modulo', '/reports/elenco/courses?verso=disc',
            'main td.col-azioni a:text-is("Griglia")',
            async (p) => {
                const modulo = p.locator('main a[href*="modulo=1"]').first();
                if (await modulo.count() > 0) {
                    await Promise.all([p.waitForURL(/modulo=1/), modulo.click()]);
                }
            });
        // Lo studente A, che ha corsi e incontri: le schede ci sono.
        await giro(a, 'Report › Studenti › Dettaglio, cambiando scheda', '/reports/elenco/students?cerca=stud%40test.it',
            'main td.col-azioni a:text-is("Dettaglio")',
            async (p) => {
                const scheda = p.locator('nav.schede-pagina a').nth(1);
                check('(ci sono le schede da cambiare)', await scheda.count() > 0);
                if (await scheda.count() > 0) {
                    await Promise.all([p.waitForURL(/scheda=/), scheda.click()]);
                }
            });

        // --- 3. percorsi frequenti --------------------------------------------
        console.log('\n3. Percorsi frequenti');
        await giro(a, 'report dello studente › tentativo', '/reports/students/' + A.studente, 'main a[href^="/attempts/"]');
        await giro(a, 'report dello studente dal corso › tentativo (due ritorni)',
            '/reports/students/' + A.studente + '?corso=' + A.corso + '&da=corso', 'main a[href^="/attempts/"]');
        await giro(a, 'report dello studente › report dell\'incontro', '/reports/students/' + A.studente + '?scheda=incontri',
            'main a[href^="/reports/live/"]');
        await giro(a, 'Gruppi › pagina del gruppo', '/admin/groups', 'main a[href^="/gruppi/"][href*="da=gruppi"]');
        await giro(a, 'scheda del gruppo › pagina del gruppo', '/admin/groups/' + A.gruppo + '/edit', 'main a[href^="/gruppi/"]');
        await giro(a, 'report dell\'incontro › pagina dell\'incontro', '/reports/live/' + A.incontro, 'main a[href^="/live/"]');

        const stud = await entra(browser, 'stud@test.it');
        const s = stud.page;
        await giro(s, 'studente: corso › il suo gruppo', '/courses/' + A.corso, 'main a.corso-tuo-gruppo-link');
        await giro(s, 'studente: Agenda (elenco) › incontro', '/agenda?vista=elenco', 'main a[href^="/live/"][href*="da=agenda"]');
        await giro(s, 'studente: gruppo › incontro', '/gruppi/' + A.gruppo + '?corso=' + A.corso, 'main a[href^="/live/"]');
        await stud.ctx.close();

        // --- 4. percorsi secondari ---------------------------------------------
        console.log('\n4. Percorsi secondari');
        await giro(a, 'scheda del gruppo › scheda del corso', '/admin/groups/' + A.gruppo + '/edit', 'main a[href^="/admin/courses/"][href*="da=scheda-gruppo"]');
        await giro(a, 'scheda dell\'utente › scheda del gruppo', '/admin/users/' + A.studente + '/edit', 'main a[href^="/admin/groups/"][href*="da=scheda-utente"]');
        await giro(a, 'categoria della Bacheca › Nuova risorsa', '/bacheca/categorie/' + A.bacheca_categoria, 'main a[href^="/bacheca/nuova"]');
        await giro(a, 'categoria della Bacheca › Modifica', '/bacheca/categorie/' + A.bacheca_categoria, 'main a[href*="/modifica?da=categoria"]');

        // --- 5. controprove ------------------------------------------------------
        console.log('\n5. Controprove');
        const casi = [
            ['report del corso senza partenza: «Report»', '/reports/courses/' + A.corso, '/reports', 'Report'],
            ['report del corso con una partenza che non accetta: «Report»', '/reports/courses/' + A.corso + '?da=agenda', '/reports', 'Report'],
            ['incontro senza partenza: «Sessioni live»', '/live/' + A.incontro, '/live', 'Sessioni live'],
            ['incontro dal report di un altro incontro: «Sessioni live»', '/live/' + A.incontro + '?da=report-incontro&da_id=' + A.incontro_concluso, '/live', 'Sessioni live'],
            ['tentativo dal report di un altro studente: il corso', '/attempts/' + A.tentativo + '?da=report-studente&da_id=' + d.utenti.studenteB, '/courses/' + A.corso, null],
            ['indietro con un `torna` che porta fuori: resta sul sito', '/reports/courses/' + A.corso + '?da=elenco-corsi&torna=' + encodeURIComponent('x=1&//altrove'), '/reports/elenco/courses?x=1', 'Corsi'],
            ['pagina del gruppo senza partenza: «Il mio profilo»', '/gruppi/' + A.gruppo, '/profilo', 'Il mio profilo'],
        ];
        for (const [nome, url, href, testo] of casi) {
            await vai(a, url);
            const b = await indietro(a);
            check(nome, b !== null && b.href === href && (testo === null || b.testo === testo), [JSON.stringify(b)]);
        }
        await admin.ctx.close();
    } finally {
        await browser.close();
        php('$pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', SUPERVISORE);
        semina();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
