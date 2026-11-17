/**
 * Chi ha scaricato i materiali delle lezioni (17/11, Alessandro).
 *
 * Lo scaricamento di un materiale si registra, una volta per studente, con
 * la data del primo. Il report del corso ha il riquadro «Materiali»: per
 * ogni materiale quanti studenti l'hanno scaricato («4 su 7») e i loro nomi,
 * ciascuno con la data accanto; chi non l'ha scaricato non si elenca. Il
 * report dello studente ha, per ogni corso, i materiali con la data o «non
 * ancora». I file scaricati dei due report hanno lo stesso in fondo.
 *
 * COSA VERIFICA:
 *
 *   1. **Si registra solo lo studente, e solo il primo**: un secondo
 *      scaricamento non sposta la data; Amministratore e tutor che scaricano
 *      non lasciano righe; un materiale di un modulo chiuso, rifiutato, non
 *      conta.
 *   2. **Il report del corso**: conteggio e nomi in due colonne, la data
 *      sulla stessa riga del nome, niente elenco di chi non ha scaricato.
 *   3. **Il perimetro**: il tutor A non vede, ne' conta, lo studente
 *      dell'altra classe (gruppo del tutor 3); l'Amministratore si'.
 *   4. **Il supervisore** vede il riquadro.
 *   5. **Il report dello studente** e **i file** dei due report.
 *
 * Esecuzione:  node tests/materiali_scaricati.js   (server di sviluppo acceso)
 *
 * Semina all'inizio e alla fine; il supervisore di prova si crea e si toglie.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const SUPERVISORE = 'supervisore-materiali@test.it';

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
const righe = (materiale) => Number(valore('SELECT COUNT(*) FROM material_downloads WHERE material_id = ?', materiale));

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

/** Lo stato della risposta, scaricando da dentro la pagina. */
const scarica = (page, u) => page.evaluate(async (x) => (await fetch(x, { credentials: 'same-origin' })).status, u);
/** Una riga del CSV che comincia con il nome e il file, virgolette o no. */
const inCsv = (csv, nome, file) => csv.split('\n').some((r) => r.replace(/"/g, '').startsWith(nome + ';' + file + ';'));
const testo = (page, u) => page.evaluate(async (x) => (await (await fetch(x, { credentials: 'same-origin' })).text()), u);

/** La riga del materiale nel riquadro «Materiali» del report del corso. */
async function rigaMateriale(page, corso, nomeFile) {
    await page.goto(BASE + '/reports/courses/' + corso);
    return page.evaluate((nome) => {
        const tabella = document.querySelector('table.report-materiali');
        if (!tabella) {
            return null;
        }
        const intestazioni = [...tabella.querySelectorAll('thead th')].map((t) => t.textContent.trim().replace(/[↑↓]/g, '').split(':')[0].trim());
        const riga = [...tabella.querySelectorAll('tbody tr')].find((tr) => tr.cells[0].textContent.trim() === nome);
        if (!riga) {
            return { intestazioni, riga: null };
        }
        const nomi = [...riga.cells[3].querySelectorAll('li')].map((li) => {
            const quando = li.querySelector('.elenco-scaricati-quando');
            const r1 = li.getBoundingClientRect();
            const r2 = quando ? quando.getBoundingClientRect() : null;
            return {
                testo: li.textContent.replace(/\s+/g, ' ').trim(),
                // Accanto, non sotto: la data sta sulla stessa riga del nome.
                accanto: r2 !== null && Math.abs(r2.top - r1.top) < 6,
            };
        });
        return {
            intestazioni,
            conteggio: riga.cells[2].textContent.trim(),
            nomi,
            card: tabella.closest('section').innerText,
        };
    }, nomeFile);
}

(async () => {
    const d = semina();
    const A = d.A;
    const studente = A.studente;
    const altro = d.utenti.altroGruppo;
    const file = valore('SELECT file_name FROM lesson_materials WHERE id = ?', A.materiale);
    const nomeStudente = valore('SELECT full_name FROM users WHERE id = ?', studente);
    const nomeAltro = valore('SELECT full_name FROM users WHERE id = ?', altro);

    php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at)'
        + ' VALUES (?, ?, \'Super\', \'Materiali\', \'supervisore\', 1, NOW(), NOW())'
        + ' ON DUPLICATE KEY UPDATE role = \'supervisore\', is_active = 1")->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT)]);', SUPERVISORE, PASSWORD);

    const browser = await chromium.launch();

    try {
        // --- 1. che cosa si registra ------------------------------------------
        console.log('\nChe cosa si registra');
        php('$pdo->exec("DELETE FROM material_downloads WHERE material_id IN (" . (int) $argv[1] . ", " . (int) $argv[2] . ")");', A.materiale, A.materiale_chiuso);

        const admin = await entra(browser, 'admin@test.it');
        const tutor = await entra(browser, 'tutor1@test.it');
        let s = await scarica(admin.page, '/materials/' + A.materiale + '/download');
        let s2 = await scarica(tutor.page, '/materials/' + A.materiale + '/download');
        check('Amministratore e tutor scaricano, e non lasciano righe', s === 200 && s2 === 200 && righe(A.materiale) === 0,
            ['stati ' + s + ' ' + s2 + ', righe ' + righe(A.materiale)]);

        const stud = await entra(browser, 'stud@test.it');
        s = await scarica(stud.page, '/materials/' + A.materiale + '/download');
        const prima = valore('SELECT first_at FROM material_downloads WHERE material_id = ? AND user_id = ?', A.materiale, studente);
        check('lo studente scarica: una riga, con la data', s === 200 && righe(A.materiale) === 1 && prima !== '', ['stato ' + s]);

        // La data vecchia di un giorno: un secondo scaricamento non la tocca.
        php('$pdo->prepare("UPDATE material_downloads SET first_at = first_at - INTERVAL 1 DAY WHERE material_id = ?")->execute([$argv[1]]);', A.materiale);
        const spostata = valore('SELECT first_at FROM material_downloads WHERE material_id = ?', A.materiale);
        await scarica(stud.page, '/materials/' + A.materiale + '/download');
        check('lo scarica di nuovo: sempre una riga, con la data del primo',
            righe(A.materiale) === 1 && valore('SELECT first_at FROM material_downloads WHERE material_id = ?', A.materiale) === spostata);

        s = await scarica(stud.page, '/materials/' + A.materiale_chiuso + '/download');
        check('un materiale di un modulo chiuso, rifiutato, non conta', s === 403 && righe(A.materiale_chiuso) === 0, ['stato ' + s]);
        await stud.ctx.close();

        // Lo studente dell'altra classe, del tutor 3: scrive il database,
        // per non dover entrare con lui.
        php('$pdo->prepare("INSERT INTO material_downloads (material_id, user_id) VALUES (?, ?)")->execute([$argv[1], $argv[2]]);', A.materiale, altro);

        // --- 2 e 3. il report del corso ---------------------------------------
        console.log('\nIl report del corso');
        const iscrittiAdmin = Number(valore('SELECT COUNT(*) FROM enrollments WHERE course_id = ?', A.corso));
        let r = await rigaMateriale(admin.page, A.corso, file);
        const colonne = r !== null && ['Materiale', 'Lezione', 'Scaricato da', 'Nomi'].every((c, i) => r.intestazioni[i] === c);
        check('Amministratore: le colonne Materiale, Lezione, «Scaricato da», «Nomi»', colonne, [JSON.stringify(r && r.intestazioni)]);
        check('Amministratore: «2 su ' + iscrittiAdmin + '», i due nomi con la data accanto',
            r !== null && r.conteggio === '2 su ' + iscrittiAdmin && r.nomi.length === 2
            && r.nomi.every((n) => n.accanto && /\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/.test(n.testo))
            && r.nomi.some((n) => n.testo.startsWith(nomeStudente)) && r.nomi.some((n) => n.testo.startsWith(nomeAltro)),
            [JSON.stringify(r && { conteggio: r.conteggio, nomi: r.nomi })]);
        check('nessun elenco di chi non ha scaricato', r !== null && !/non ancora/i.test(r.card), [r && r.card]);

        r = await rigaMateriale(tutor.page, A.corso, file);
        const suTutor = r === null ? '' : r.conteggio.split(' su ')[1];
        check('tutor A: «1 su …», solo il suo studente, e il totale e\' quello dei suoi iscritti',
            r !== null && r.conteggio.startsWith('1 su ') && Number(suTutor) < iscrittiAdmin
            && r.nomi.length === 1 && r.nomi[0].testo.startsWith(nomeStudente),
            [JSON.stringify(r && { conteggio: r.conteggio, nomi: r.nomi })]);

        // --- 4. il supervisore -------------------------------------------------
        const sup = await entra(browser, SUPERVISORE);
        r = await rigaMateriale(sup.page, A.corso, file);
        check('il supervisore vede il riquadro, con i due nomi', r !== null && r.nomi.length === 2, [JSON.stringify(r)]);
        await sup.ctx.close();

        // --- 5. il report dello studente e i file ------------------------------
        console.log('\nIl report dello studente e i file');
        await admin.page.goto(BASE + '/reports/students/' + studente + '?corso=' + A.corso + '&da=corso');
        const mat = await admin.page.evaluate(() => [...document.querySelectorAll('div.report-studente-materiali tbody tr')]
            .map((tr) => [...tr.cells].map((c) => c.textContent.trim())));
        const suo = mat.find((m) => m[0] === file);
        check('report dello studente: il materiale con la data, gli altri «non ancora»',
            suo !== undefined && /\d{2}\/\d{2}\/\d{4}/.test(suo[2]) && mat.filter((m) => m !== suo).every((m) => m[2] === 'non ancora') && mat.length > 1,
            [JSON.stringify(mat)]);

        const csvCorso = await testo(admin.page, '/reports/courses/' + A.corso + '/csv');
        check('CSV del corso: il blocco dei materiali, una riga per scaricamento',
            csvCorso.includes('Studente;Materiale;"Scaricato il";Lezione') && inCsv(csvCorso, nomeStudente, file) && inCsv(csvCorso, nomeAltro, file),
            [csvCorso.slice(-400)]);
        const csvTutor = await testo(tutor.page, '/reports/courses/' + A.corso + '/csv');
        check('CSV del corso del tutor A: senza lo studente dell\'altra classe', inCsv(csvTutor, nomeStudente, file) && !csvTutor.includes(nomeAltro));
        const csvStud = await testo(admin.page, '/reports/students/' + studente + '/csv');
        check('CSV dello studente: il blocco dei materiali', csvStud.includes('Materiale;"Scaricato il";Corso;Lezione') && csvStud.includes(file),
            [csvStud.slice(-300)]);
        const xlsx = await admin.page.evaluate(async (x) => (await fetch(x, { credentials: 'same-origin' })).status, '/reports/courses/' + A.corso + '/xlsx');
        check('XLSX del corso: si scarica', xlsx === 200);

        await admin.ctx.close();
        await tutor.ctx.close();
    } finally {
        await browser.close();
        php('$pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', SUPERVISORE);
        semina();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
