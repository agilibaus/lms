/**
 * Solo lo studente invia i questionari e segna le lezioni come fatte
 * (13/11, Alessandro).
 *
 * Era la regola del supervisore (06/11, 0358), estesa a tutti gli altri
 * ruoli: Amministratore, tutor e assistente vedono le domande, spente, con
 * «Sola lettura: le risposte non si inviano.» al posto di «Invia», e un invio
 * costruito a mano — del questionario o di «Segna come fatta» — non scrive
 * niente a loro nome. Le righe dello staff non servono e appesantiscono
 * report e database.
 *
 * Le azioni si giudicano dal database: il server rifiuta rimandando con un
 * avviso (il questionario) o con un 403 (la lezione), e quello che conta e'
 * che non ci sia una riga nuova. L'invio porta le risposte a tutte le
 * domande, come il modulo vero: senza, si fermerebbe prima per «Rispondi a
 * tutte le domande» e la prova resterebbe verde anche senza il blocco.
 *
 * La controprova: lo studente di A invia il questionario e segna la lezione
 * come prima.
 *
 * Esecuzione:  node tests/solo_studenti.js   (server di sviluppo acceso)
 *
 * Semina con `--lezioni-fatte` (il questionario si apre a lezioni fatte), e
 * alla fine risemina, per lasciare il database come lo vogliono le altre
 * suite.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

const STAFF = [
    ['Amministratore', 'admin@test.it'],
    ['tutor', 'tutor1@test.it'],
    ['assistente', 'assist@test.it'],
];
const STUDENTE = 'stud@test.it';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.forEach((d) => console.log('         · ' + d));
    }
}

/** Codice PHP con l'applicazione caricata; `$argv[1..]` sono gli argomenti. */
function php(codice, ...argomenti) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();' + codice, '--', ...argomenti.map(String)], { cwd: RADICE, encoding: 'utf8' });
}

const conta = (sql, ...a) => Number(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo (int) $s->fetchColumn();', sql, ...a).trim());
const idUtente = (email) => conta('SELECT id FROM users WHERE email = ?', email);

function semina(...opzioni) {
    const uscita = execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php'), ...opzioni], { encoding: 'utf8', cwd: RADICE });
    return JSON.parse(uscita);
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

/** Una POST con il token di una pagina che l'utente apre; torna lo stato. */
async function posta(page, url, campi, paginaToken) {
    await page.goto(BASE + paginaToken);
    return page.evaluate(async ([url, campi]) => {
        const dati = new URLSearchParams();
        dati.append('_token', document.querySelector('input[name="_token"]').value);
        for (const [k, v] of campi) dati.append(k, v);
        const res = await fetch(url, { method: 'POST', body: dati, credentials: 'same-origin', redirect: 'manual' });
        return res.status;
    }, [url, campi]);
}

/** Le risposte a tutte le domande, come le manderebbe il modulo. */
const risposte = (quiz) => JSON.parse(php('$c = []; $q = $pdo->prepare("SELECT id, question_type FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id"); $q->execute([(int) $argv[1]]);'
    + ' foreach ($q->fetchAll() as $d) { $o = $pdo->prepare("SELECT id FROM quiz_options WHERE question_id = ? ORDER BY id LIMIT 1"); $o->execute([$d["id"]]); $op = $o->fetchColumn();'
    + ' if ($d["question_type"] === "open") { $c[] = ["answers[" . $d["id"] . "]", "Una risposta"]; }'
    + ' elseif ($d["question_type"] === "multiple_choice") { $c[] = ["answers[" . $d["id"] . "][]", (string) $op]; }'
    + ' else { $c[] = ["answers[" . $d["id"] . "]", (string) $op]; } } echo json_encode($c);', quiz));

/** Come si presenta la pagina del questionario. */
async function vista(page, quiz) {
    const risposta = await page.goto(BASE + '/quizzes/' + quiz);
    const dati = await page.evaluate(() => ({
        domande: document.querySelectorAll('.quiz-question-list > li').length,
        spento: !!document.querySelector('fieldset.quiz-concluso-campi[disabled]'),
        invia: !!document.querySelector('.quiz-form button[type="submit"]'),
        frase: [...document.querySelectorAll('.quiz-form .form-hint')].map((p) => p.textContent.trim()).join(' | '),
    }));
    return { stato: risposta ? risposta.status() : 0, ...dati };
}

(async () => {
    console.log('Semina dei due mondi, a lezioni fatte…');
    const A = semina('--lezioni-fatte').A;
    const browser = await chromium.launch();

    try {
        for (const [ruolo, email] of STAFF) {
            const id = idUtente(email);
            const { ctx, page } = await entra(browser, email);

            // --- la pagina del questionario ---------------------------------
            const v = await vista(page, A.quiz);
            check(ruolo + ': il questionario si apre, con le domande spente, senza «Invia», con «Sola lettura: le risposte non si inviano.»',
                v.stato === 200 && v.domande > 0 && v.spento && !v.invia && v.frase === 'Sola lettura: le risposte non si inviano.', [JSON.stringify(v)]);
            check(ruolo + ': niente «Anteprima per lo staff»', !v.frase.includes('Anteprima'), [v.frase]);

            // --- l'invio del questionario, costruito a mano -----------------
            const t0 = conta('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ?', id);
            await posta(page, '/quizzes/' + A.quiz + '/attempts', risposte(A.quiz), '/quizzes/' + A.quiz);
            const t1 = conta('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ?', id);
            check(ruolo + ': un invio completo del questionario non salva tentativi', t1 === t0, [t0 + ' → ' + t1]);

            // --- «Segna come fatta», costruito a mano -----------------------
            const f0 = conta('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ?', id);
            const stato = await posta(page, '/lessons/' + A.lezione + '/complete', [], '/lessons/' + A.lezione);
            const f1 = conta('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ?', id);
            check(ruolo + ': «Segna come fatta» non scrive niente e risponde 403', f1 === f0 && stato === 403, [f0 + ' → ' + f1 + ', stato ' + stato]);

            // --- e nella lezione il pulsante non c'e' -----------------------
            await page.goto(BASE + '/lessons/' + A.lezione);
            const pulsante = await page.evaluate(() => !!document.querySelector('form[action$="/complete"]'));
            check(ruolo + ': nella lezione niente «Segna come completata»', !pulsante);

            await ctx.close();
        }

        // --- la controprova: lo studente invia e segna come prima -----------
        const idStud = idUtente(STUDENTE);
        // Da zero sul questionario e sulla lezione di A: un tentativo gia'
        // al 100% chiuderebbe il questionario, una lezione gia' fatta non
        // aggiungerebbe una riga. La semina finale rimette tutto.
        php('$pdo->prepare("DELETE FROM quiz_attempts WHERE user_id = ? AND quiz_id = ?")->execute([$argv[1], $argv[2]]);'
            + ' $pdo->prepare("DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?")->execute([$argv[1], $argv[3]]);', idStud, A.quiz, A.lezione);
        const { ctx, page } = await entra(browser, STUDENTE);

        // Prima la lezione: il questionario si apre a lezioni fatte.
        const stato = await posta(page, '/lessons/' + A.lezione + '/complete', [], '/lessons/' + A.lezione);
        check('controprova: lo studente segna la lezione come fatta',
            conta('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', idStud, A.lezione) === 1, ['stato ' + stato]);

        const v = await vista(page, A.quiz);
        check('controprova: lo studente vede le risposte attive e «Invia risposte»', v.stato === 200 && v.domande > 0 && !v.spento && v.invia, [JSON.stringify(v)]);

        await posta(page, '/quizzes/' + A.quiz + '/attempts', risposte(A.quiz), '/quizzes/' + A.quiz);
        check('controprova: lo studente invia il questionario e il tentativo si salva',
            conta('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND quiz_id = ?', idStud, A.quiz) === 1);
        await ctx.close();
    } finally {
        await browser.close();
        semina();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
