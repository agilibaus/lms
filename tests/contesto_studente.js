/**
 * Il report di uno studente aperto da un corso o da un gruppo (17/11,
 * Alessandro, strada A).
 *
 * Da «Report › Corsi › un corso › uno studente» la pagina mostrava tutti i
 * corsi dello studente. Adesso, arrivando da un corso o da un gruppo, mostra
 * solo quello, il collegamento in alto riporta alla pagina di partenza com'era
 * (filtri e ordinamento compresi) e i file scaricati seguono la pagina.
 *
 * Lo studente del mondo A e' iscritto al corso A (nel gruppo A) e al corso C,
 * senza gruppi. Per la prova il corso C riceve un incontro: dal corso A e dal
 * gruppo A non deve comparire, ne' a schermo ne' nel file.
 *
 * COSA VERIFICA:
 *
 *   1. **Le sei pagine di partenza**: dal collegamento sul nome si arriva alla
 *      pagina ristretta, e il collegamento in alto torna esattamente
 *      all'indirizzo di partenza.
 *   2. **Che cosa si vede**: niente schede, niente corso C, niente incontro
 *      del corso C; dal corso un riquadro «Avanzamento»; il titolo degli
 *      incontri porta le presenze.
 *   3. **I file**: XLSX e CSV portano il contesto, e il CSV non ha il corso C.
 *   4. **Un contesto che non regge si ignora**: il tutor A con il corso C,
 *      che non segue, e l'Amministratore con il gruppo B, di cui lo studente
 *      non fa parte, vedono la scheda completa che vedevano gia'.
 *   5. **La controprova**: la scheda completa, da Report › Studenti, ha
 *      ancora tutto, compreso il corso C e il suo incontro.
 *
 * Esecuzione:  node tests/contesto_studente.js   (server di sviluppo acceso)
 *
 * Semina all'inizio e risemina alla fine, per togliere l'incontro di prova.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const INCONTRO_C = '[prova-permessi] incontro del corso C';
const INCONTRO_GRUPPO = '[prova-permessi] incontro del gruppo A, senza corso';

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

const valore = (sql, ...a) => php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo $s->fetchColumn();', sql, ...a).trim();

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

/** Quello che conta della pagina dello studente. */
async function leggi(page) {
    return page.evaluate(() => {
        const indietro = document.querySelector('.page-header .back-link');
        const scarica = [...document.querySelectorAll('.page-header .dropdown-menu a')].map((a) => a.getAttribute('href'));
        return {
            indietro: indietro ? indietro.getAttribute('href') : null,
            etichetta: indietro ? indietro.textContent.trim() : null,
            schede: document.querySelector('nav.schede-pagina') !== null,
            corsi: [...document.querySelectorAll('section.report-studente-corso h2')].map((h) => h.textContent.trim()),
            incontriTitolo: (document.querySelector('section.report-studente-incontri h2:not(.sr-only)') || {}).textContent || null,
            colonnaCorso: [...document.querySelectorAll('section.report-studente-incontri th')].some((t) => t.textContent.includes('Corso o gruppo')),
            testo: document.body.innerText,
            scarica,
        };
    });
}

/** Dalla pagina di partenza al nome dello studente, e di nuovo indietro. */
async function giro(page, nome, partenza, studente) {
    await page.goto(BASE + partenza);
    const link = page.locator(studente === null
        ? 'a[href^="/reports/students/"][href*="da=fermi"]'
        : 'a[href^="/reports/students/' + studente + '?"]').first();
    const trovato = await link.count() > 0;
    check(nome + ': il collegamento allo studente porta il contesto', trovato);
    if (!trovato) {
        return null;
    }
    await link.click();
    await page.waitForLoadState('networkidle');
    const v = await leggi(page);
    await page.click('.page-header .back-link');
    await page.waitForLoadState('networkidle');
    const tornato = page.url().replace(BASE, '');
    check(nome + ': «‹ ' + v.etichetta + '» torna a ' + partenza, tornato === partenza, ['tornato a ' + tornato]);
    return v;
}

(async () => {
    const d = semina();
    const A = d.A;
    const C = d.C;
    const studente = A.studente;
    const corsoA = valore('SELECT title FROM courses WHERE id = ?', A.corso);
    const corsoC = valore('SELECT title FROM courses WHERE id = ?', C.corso);
    const gruppoA = valore('SELECT name FROM `groups` WHERE id = ?', A.gruppo);

    // Un incontro del corso C, senza gruppo: lo studente A e' atteso.
    php('$pdo->prepare("INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, created_by)'
        + ' VALUES (?, NULL, ?, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 30 DAY, ?)")->execute([$argv[1], $argv[2], $argv[3]]);',
        C.modulo, INCONTRO_C, d.utenti.admin);

    // Un incontro del gruppo A senza corso: dal corso A c'e' (il gruppo ha
    // il corso), e la colonna «Corso o gruppo» non deve comparire lo stesso.
    php('$pdo->prepare("INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, created_by)'
        + ' VALUES (NULL, ?, ?, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY + INTERVAL 1 HOUR, ?)")->execute([$argv[1], $argv[2], $argv[3]]);',
        A.gruppo, INCONTRO_GRUPPO, d.utenti.admin);

    const browser = await chromium.launch();

    try {
        const admin = await entra(browser, 'admin@test.it');
        const page = admin.page;

        // --- 1 e 2. le sei pagine di partenza --------------------------------
        console.log('\nDal corso');
        const dalCorso = [
            ['Report del corso', '/reports/courses/' + A.corso + '?ordina=studente&verso=disc', corsoA],
            ['Griglia del corso', '/reports/courses/' + A.corso + '/griglia?gruppo=' + A.gruppo, 'Griglia del corso'],
            ['Studenti fermi', '/reports/fermi?giorni=7&ordina=nome&verso=disc', 'Studenti fermi'],
            ['Gestione del corso', '/admin/courses/' + A.corso + '/edit', corsoA],
        ];

        for (const [nome, partenza, etichetta] of dalCorso) {
            // Lo studente A, che ha appena lavorato, fra i fermi non c'e':
            // da li' si prova il ritorno con il primo della tabella.
            const fermi = partenza.startsWith('/reports/fermi');
            const v = await giro(page, nome, partenza, fermi ? null : studente);
            if (v === null) {
                continue;
            }
            if (fermi) {
                check(nome + ': un riquadro «Avanzamento», senza schede', !v.schede && v.corsi.length === 1 && v.corsi[0] === 'Avanzamento',
                    [JSON.stringify({ ...v, testo: undefined })]);
                continue;
            }
            const bene = v.etichetta === etichetta && !v.schede && v.corsi.length === 1 && v.corsi[0] === 'Avanzamento'
                && !v.testo.includes(corsoC) && !v.testo.includes(INCONTRO_C)
                && /presente a \d+ su \d+/.test(v.incontriTitolo || '') && !v.colonnaCorso
                && !v.testo.includes('Sessioni live seguite') && v.testo.includes(INCONTRO_GRUPPO);
            check(nome + ': solo il corso A, un riquadro «Avanzamento», gli incontri (anche quello del gruppo) con le presenze nel titolo, senza la colonna «Corso o gruppo»', bene,
                [JSON.stringify({ ...v, testo: undefined })]);
            const fileOk = v.scarica.length > 0 && v.scarica.every((h) => h.endsWith('?corso=' + A.corso));
            check(nome + ': «Scarica» porta il corso', fileOk, v.scarica);
        }

        // Ordinare una colonna lascia la pagina nel suo contesto.
        const partenzaCorso = '/reports/courses/' + A.corso + '?ordina=studente&verso=disc';
        await page.goto(BASE + partenzaCorso);
        await page.locator('a[href^="/reports/students/' + studente + '?"]').first().click();
        await page.waitForLoadState('networkidle');
        await page.locator('th a.ordina').first().click();
        await page.waitForLoadState('networkidle');
        const ordinata = await leggi(page);
        check('ordinando una colonna il contesto resta, e anche il ritorno', ordinata.indietro === partenzaCorso && ordinata.corsi.length === 1,
            [JSON.stringify({ ...ordinata, testo: undefined })]);

        console.log('\nDal gruppo');
        const dalGruppo = [
            ['Report del gruppo', '/reports/groups/' + A.gruppo],
            ['Gestione del gruppo', '/admin/groups/' + A.gruppo + '/edit'],
        ];

        for (const [nome, partenza] of dalGruppo) {
            const v = await giro(page, nome, partenza, studente);
            if (v === null) {
                continue;
            }
            const bene = v.etichetta === gruppoA && !v.schede && v.corsi.length === 1 && v.corsi[0] === corsoA
                && !v.testo.includes(corsoC) && !v.testo.includes(INCONTRO_C)
                && /presente a \d+ su \d+/.test(v.incontriTitolo || '') && !v.colonnaCorso;
            check(nome + ': solo i corsi del gruppo, col loro nome, e i suoi incontri, senza la colonna «Corso o gruppo» (un corso solo)', bene, [JSON.stringify({ ...v, testo: undefined })]);
            const fileOk = v.scarica.length > 0 && v.scarica.every((h) => h.endsWith('?gruppo=' + A.gruppo));
            check(nome + ': «Scarica» porta il gruppo', fileOk, v.scarica);
        }

        // --- 3. i file -------------------------------------------------------
        console.log('\nI file');
        const csv = (u) => page.evaluate(async (x) => (await (await fetch(x, { credentials: 'same-origin' })).text()), u);
        const dalCorsoCsv = await csv('/reports/students/' + studente + '/csv?corso=' + A.corso);
        check('il CSV dal corso ha il corso A e non il corso C, ne\' il suo incontro',
            dalCorsoCsv.includes('Corso;') && dalCorsoCsv.includes(corsoA) && !dalCorsoCsv.includes(corsoC) && !dalCorsoCsv.includes(INCONTRO_C),
            [dalCorsoCsv.slice(0, 200)]);
        const dalGruppoCsv = await csv('/reports/students/' + studente + '/csv?gruppo=' + A.gruppo);
        check('il CSV dal gruppo nemmeno', dalGruppoCsv.includes(corsoA) && !dalGruppoCsv.includes(corsoC) && !dalGruppoCsv.includes(INCONTRO_C));

        // --- 5. la controprova -----------------------------------------------
        console.log('\nLa scheda completa');
        await page.goto(BASE + '/reports/students/' + studente);
        let v = await leggi(page);
        check('controprova: senza contesto torna a «Report», con le schede e il corso C', v.indietro === '/reports' && v.schede && v.testo.includes(corsoC),
            [JSON.stringify({ ...v, testo: undefined })]);
        await page.goto(BASE + '/reports/students/' + studente + '?scheda=incontri');
        v = await leggi(page);
        check('controprova: e fra gli incontri c\'e\' quello del corso C, con la colonna «Corso o gruppo»', v.testo.includes(INCONTRO_C) && v.colonnaCorso);
        const tuttoCsv = await csv('/reports/students/' + studente + '/csv');
        check('controprova: e anche nel CSV', tuttoCsv.includes(corsoC) && tuttoCsv.includes(INCONTRO_C));

        // --- 4. un contesto che non regge -------------------------------------
        console.log('\nUn contesto che non regge');
        await page.goto(BASE + '/reports/students/' + studente + '?gruppo=' + d.B.gruppo + '&da=gruppo');
        v = await leggi(page);
        check('Amministratore, gruppo di cui lo studente non fa parte: la scheda completa', v.indietro === '/reports' && v.testo.includes(corsoC));
        await page.goto(BASE + '/reports/students/' + studente + '?corso=' + d.B.corso + '&da=corso');
        v = await leggi(page);
        check('Amministratore, corso a cui lo studente non e\' iscritto: la scheda completa', v.indietro === '/reports' && v.testo.includes(corsoC));
        await admin.ctx.close();

        const tutor = await entra(browser, 'tutor1@test.it');
        await tutor.page.goto(BASE + '/reports/students/' + studente + '?corso=' + C.corso + '&da=corso');
        v = await leggi(tutor.page);
        check('tutor A, corso C che non segue: la sua scheda, senza il corso C', v.indietro === '/reports' && !v.testo.includes(corsoC) && v.testo.includes(corsoA),
            [JSON.stringify({ ...v, testo: undefined })]);
        const tutorCsv = await tutor.page.evaluate(async (x) => (await (await fetch(x, { credentials: 'same-origin' })).text()),
            '/reports/students/' + studente + '/csv?corso=' + C.corso);
        check('tutor A: nemmeno il file con il corso C lo mostra', tutorCsv.includes(corsoA) && !tutorCsv.includes(corsoC));
        await tutor.ctx.close();
    } finally {
        await browser.close();
        semina();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
