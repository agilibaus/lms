/**
 * Questionari con dei tentativi: che cosa si blocca e il ricalcolo dei voti
 * (16/11, Alessandro).
 *
 * Con dei tentativi le domande non si aggiungono e non si eliminano, il
 * loro tipo non cambia e le opzioni non si aggiungono ne' si tolgono. Si
 * correggono i testi e si cambiano le risposte corrette e la soglia: in quei
 * due casi, se cambia almeno un voto, prima la pagina «Ricalcolo dei voti»,
 * poi modifica e voti nuovi insieme. Un refuso, o un cambiamento che non
 * sposta nessun voto, si salva subito. Nei questionari H5P la soglia non si
 * cambia.
 *
 * Tutto si giudica dal database: le domande, le corrette, la soglia, il
 * punteggio e l'esito dei tentativi, e se ogni risposta risulta giusta.
 *
 * I dati: il questionario del mondo A con due domande in piu' (una multipla
 * e una aperta) e tre tentativi scritti qui, di due studenti; un
 * questionario H5P nel modulo dopo. Esecuzione:
 *   node tests/ricalcolo.js   (server di sviluppo acceso)
 * Semina all'inizio e alla fine, come le altre suite.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

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

const json = (codice, ...a) => JSON.parse(php(codice, ...a));
const tutte = (sql, ...a) => json('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));', sql, ...a);
const uno = (sql, ...a) => tutte(sql, ...a)[0] ?? null;
const semina = () => JSON.parse(execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php'), '--lezioni-fatte'], { cwd: RADICE, encoding: 'utf8' }));

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

/** Una POST con il token della pagina: torna lo stato e, se e' una pagina, il testo. */
async function posta(page, url, campi, paginaToken) {
    await page.goto(BASE + paginaToken);
    return page.evaluate(async ([url, campi]) => {
        const dati = new URLSearchParams();
        dati.append('_token', document.querySelector('input[name="_token"]').value);
        for (const [k, v] of campi) dati.append(k, v);
        const res = await fetch(url, { method: 'POST', body: dati, credentials: 'same-origin', redirect: 'manual' });
        return { rimando: res.type === 'opaqueredirect', stato: res.status, testo: res.type === 'opaqueredirect' ? '' : await res.text() };
    }, [url, campi]);
}

const testo = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&[a-z#0-9]+;/g, (e) => ({ '&laquo;': '«', '&raquo;': '»', '&#039;': "'", '&rsquo;': '’' }[e] || ' ')).replace(/\s+/g, ' ');

(async () => {
    const d = semina();
    const A = d.A;
    const quiz = A.quiz;
    const studente = d.utenti.studenteA;
    const compagno = Number(php('echo (int) $pdo->query("SELECT id FROM users WHERE email = \'compagno1@test.it\'")->fetchColumn();'));

    // --- i dati: due domande in piu', tre tentativi -------------------------
    const prep = json(`
        $q = (int) $argv[1];
        $d = App\\Models\\QuizQuestionModel::forQuiz($q);
        $q1 = (int) $d[0]['id']; $q2 = (int) $d[1]['id'];
        $o = fn ($id) => array_map(fn ($r) => (int) $r['id'], App\\Models\\QuizOptionModel::forQuestion($id));
        $q3 = App\\Models\\QuizQuestionModel::create($q, '[ricalcolo] Quali sono giuste?', 'multiple_choice');
        App\\Models\\QuizOptionModel::replaceForQuestion($q3, [['text' => 'Emme uno', 'is_correct' => true], ['text' => 'Emme due', 'is_correct' => true], ['text' => 'Emme tre', 'is_correct' => false]]);
        $q4 = App\\Models\\QuizQuestionModel::create($q, '[ricalcolo] Racconta', 'open');
        $pdo->prepare('DELETE FROM quiz_attempts WHERE quiz_id = ?')->execute([$q]);
        $pdo->prepare('UPDATE modules SET quiz_required = 1 WHERE id = ?')->execute([(int) $argv[4]]);
        [$a1, $a2] = $o($q1); [$v, $f] = $o($q2); [$m1, $m2, $m3] = $o($q3);
        $r = function (array $scelte) use ($q1, $q2, $q3, $q4) {
            $out = [];
            foreach ($scelte as $qid => $ids) { foreach ($ids as $id) { $out[] = ['question_id' => $qid, 'selected_option_id' => $id, 'answer_text' => null, 'is_correct' => false]; } }
            $out[] = ['question_id' => $q4, 'selected_option_id' => null, 'answer_text' => 'Una risposta aperta', 'is_correct' => false];
            return $out;
        };
        $x1 = App\\Models\\QuizAttemptModel::create((int) $argv[2], $q, 100.0, true, $r([$q1 => [$a1], $q2 => [$v], $q3 => [$m1, $m2]]));
        $x2 = App\\Models\\QuizAttemptModel::create((int) $argv[3], $q, 33.33, false, $r([$q1 => [$a2], $q2 => [$v], $q3 => [$m1]]));
        $x3 = App\\Models\\QuizAttemptModel::create((int) $argv[3], $q, 66.67, true, $r([$q1 => [$a1], $q2 => [$v], $q3 => [$m1, $m3]]));
        echo json_encode(compact('q1', 'q2', 'q3', 'q4', 'a1', 'a2', 'v', 'f', 'm1', 'm2', 'm3', 'x1', 'x2', 'x3'));
    `, quiz, studente, compagno, A.modulo);
    const { q1, q3, q4, m1, m2, m3, x1, x2, x3 } = prep;

    const domande = () => Number(uno('SELECT COUNT(*) AS n FROM quiz_questions WHERE quiz_id = ?', quiz).n);
    const tentativo = (id) => { const r = uno('SELECT score_pct, passed FROM quiz_attempts WHERE id = ?', id); return Number(r.score_pct).toFixed(2) + '/' + r.passed; };
    const giusta = (id, q) => Number(uno('SELECT MAX(is_correct) AS g FROM quiz_attempt_answers WHERE attempt_id = ? AND question_id = ?', id, q).g);
    const corrette = (q) => tutte('SELECT id FROM quiz_options WHERE question_id = ? AND is_correct = 1 ORDER BY id', q).map((r) => Number(r.id)).join(',');
    const soglia = () => Number(uno('SELECT passing_score_pct AS s FROM quizzes WHERE id = ?', quiz).s);
    const fotografia = () => [x1, x2, x3].map(tentativo).join(' ') + ' | ' + corrette(q3) + ' | ' + soglia() + ' | ' + domande();

    const browser = await chromium.launch();

    try {
        const { page } = await entra(browser, 'admin@test.it');
        const pagina = '/quizzes/' + quiz + '/edit';

        // --- 1. la pagina del questionario --------------------------------
        await page.goto(BASE + pagina);
        const vista = await page.evaluate(() => ({
            frase: [...document.querySelectorAll('section.card p.riquadro-intro')].map((p) => p.textContent.replace(/\s+/g, ' ').trim()).find((t) => t.startsWith('Il questionario ha')) || '',
            nuova: !!document.querySelector('form[action$="/questions"]'),
            elimina: document.querySelectorAll('form[action*="/questions/"][action$="/delete"]').length,
            spenti: document.querySelectorAll('.question-item-actions .comando-spento').length,
            soglia: document.getElementById('quiz-soglia-aiuto').textContent.replace(/\s+/g, ' '),
        }));
        check('la frase: «Il questionario ha 3 tentativi di 2 studenti: non è possibile aggiungere…»',
            vista.frase.startsWith('Il questionario ha 3 tentativi di 2 studenti: non è possibile aggiungere o eliminare domande, né cambiare il loro tipo.'), [vista.frase]);
        check('niente «Nuova domanda», e «Elimina» spento su tutte e 4 le domande', !vista.nuova && vista.elimina === 0 && vista.spenti === 4, [JSON.stringify(vista)]);
        check('sotto la soglia: «Modificandola, i voti dei tentativi già svolti vengono ricalcolati»', vista.soglia.includes('Modificandola, i voti dei tentativi già svolti vengono ricalcolati'), [vista.soglia]);

        // --- 2. aggiungere ed eliminare: no --------------------------------
        let prima = fotografia();
        await posta(page, '/quizzes/' + quiz + '/questions', [['question_text', 'Nuova'], ['question_type', 'open']], pagina);
        check('una domanda nuova mandata a mano non entra', domande() === 4 && fotografia() === prima);
        await posta(page, '/questions/' + q4 + '/delete', [], pagina);
        check('una domanda non si elimina, e le risposte aperte restano', domande() === 4 && Number(uno('SELECT COUNT(*) AS n FROM quiz_attempt_answers WHERE question_id = ?', q4).n) === 3);

        // --- 3. la modifica della domanda ----------------------------------
        await page.goto(BASE + '/questions/' + q3 + '/edit');
        const dom = await page.evaluate(() => ({
            menu: !!document.querySelector('select[name="question_type"]'),
            tipo: [...document.querySelectorAll('form[data-question-form] p')].map((p) => p.textContent.trim()).find((t) => t.startsWith('Tipo:')) || '',
            righe: document.querySelectorAll('fieldset[data-options="multiple_choice"] .option-row').length,
            altri: document.querySelectorAll('fieldset[data-options]').length,
            frase: document.querySelector('fieldset[data-options="multiple_choice"] .form-hint').textContent.replace(/\s+/g, ' ').trim(),
        }));
        check('la domanda: «Tipo: Scelta multipla» senza menu, solo le 3 opzioni che ci sono',
            !dom.menu && dom.tipo === 'Tipo: Scelta multipla' && dom.righe === 3 && dom.altri === 1, [JSON.stringify(dom)]);
        check('la frase spiega il «tutto o niente» e il ricalcolo',
            dom.frase.includes('conta come giusta solo se lo studente sceglie tutte le risposte corrette e nessuna sbagliata')
            && dom.frase.endsWith('Se cambi le risposte corrette, i voti dei tentativi già svolti vengono ricalcolati: prima di salvare puoi vedere quanti cambiano.'), [dom.frase]);

        const multipla = (testoDomanda, opzioni, giuste, tipo = 'multiple_choice') => [['question_text', testoDomanda], ['question_type', tipo],
            ...opzioni.map((o) => ['options[]', o]), ...giuste.map((g) => [tipo === 'multiple_choice' ? 'correct_options[]' : 'correct_option', String(g)])];
        const indietroDomanda = '/questions/' + q3 + '/edit';

        prima = fotografia();
        // Da scelta singola a vero/falso con la stessa opzione corretta (la
        // prima): l'unico motivo per rifiutarlo e' il tipo, non le corrette.
        const testoQ1 = uno('SELECT question_text AS t FROM quiz_questions WHERE id = ?', q1).t;
        await posta(page, '/questions/' + q1, [['question_text', testoQ1], ['question_type', 'true_false'], ['correct_option', '0']], '/questions/' + q1 + '/edit');
        check('il tipo non cambia (scelta singola → vero/falso mandata a mano)', uno('SELECT question_type AS t FROM quiz_questions WHERE id = ?', q1).t === 'single_choice' && fotografia() === prima);
        await posta(page, '/questions/' + q3, multipla('[ricalcolo] Quali sono giuste?', ['Emme uno', 'Emme due', 'Emme tre', 'Emme quattro'], [0, 1]), indietroDomanda);
        check('un\'opzione in più non entra', Number(uno('SELECT COUNT(*) AS n FROM quiz_options WHERE question_id = ?', q3).n) === 3 && fotografia() === prima);
        await posta(page, '/questions/' + q3, multipla('[ricalcolo] Quali sono giuste?', ['Emme uno', 'Emme due'], [0, 1]), indietroDomanda);
        check('un\'opzione in meno nemmeno', Number(uno('SELECT COUNT(*) AS n FROM quiz_options WHERE question_id = ?', q3).n) === 3 && fotografia() === prima);

        // --- 4. un refuso si salva subito -----------------------------------
        let r = await posta(page, '/questions/' + q3, multipla('[ricalcolo] Quali sono giuste? (corretta)', ['Emme uno', 'Emme 2', 'Emme tre'], [0, 1]), indietroDomanda);
        check('un refuso si salva subito, senza conferma, e i voti restano',
            r.rimando && uno('SELECT question_text AS t FROM quiz_questions WHERE id = ?', q3).t.endsWith('(corretta)')
            && uno('SELECT option_text AS t FROM quiz_options WHERE id = ?', m2).t === 'Emme 2' && fotografia() === prima, [JSON.stringify(r).slice(0, 200)]);
        prima = fotografia();

        // --- 5. le corrette cambiano: prima la conferma ---------------------
        // Da «uno e due» a «uno e tre» (una multipla ne vuole almeno due):
        // il primo tentativo (uno e due) diventa sbagliato su quella domanda,
        // il terzo (uno e tre) giusto, il secondo (solo uno) resta sbagliato.
        const campiCorrette = multipla('[ricalcolo] Quali sono giuste? (corretta)', ['Emme uno', 'Emme 2', 'Emme tre'], [0, 2]);
        r = await posta(page, '/questions/' + q3, campiCorrette, indietroDomanda);
        const conferma = testo(r.testo);
        check('le corrette cambiano: la pagina «Ricalcolo dei voti», e niente è ancora salvato',
            r.stato === 200 && conferma.includes('Ricalcolo dei voti') && fotografia() === prima, [conferma.slice(0, 200), fotografia()]);
        check('la conferma dice che cosa: «da «Emme uno» e «Emme 2» a «Emme uno» e «Emme tre»» e «2 tentativi di 2 studenti»',
            conferma.includes('Hai cambiato le risposte corrette della domanda «[ricalcolo] Quali sono giuste? (corretta)»: da «Emme uno» e «Emme 2» a «Emme uno» e «Emme tre».')
            && conferma.includes('Il ricalcolo cambia il punteggio di 2 tentativi di 2 studenti.'), [conferma.slice(conferma.indexOf('Hai cambiato'), conferma.indexOf('Hai cambiato') + 300)]);
        check('nella tabella i due studenti il cui miglior risultato cambia (100% → 67%, 67% → 100%)',
            conferma.includes('Prova 100% · superato 67% · superato') && conferma.includes('Compagno 1 67% · superato 100% · superato')
            && (conferma.match(/· superato/g) || []).length === 4, [conferma.slice(conferma.indexOf('STUDENTE') - 10, conferma.indexOf('STUDENTE') + 300)]);
        r = await posta(page, '/questions/' + q3, [...campiCorrette, ['conferma_ricalcolo', '1']], indietroDomanda);
        check('«Salva e ricalcola»: corrette «uno e tre», e i due tentativi ricalcolati (66,67% e 100%)',
            r.rimando && corrette(q3) === m1 + ',' + m3 && tentativo(x1) === '66.67/1' && tentativo(x2) === '33.33/0' && tentativo(x3) === '100.00/1', [fotografia()]);
        check('e le risposte alla multipla dicono il giusto: sbagliata per il primo e il secondo, giusta per il terzo',
            giusta(x1, q3) === 0 && giusta(x2, q3) === 0 && giusta(x3, q3) === 1);
        await page.goto(BASE + pagina);
        const avviso = await page.evaluate(() => document.querySelector('.alert-success')?.textContent.trim() || '');
        check('l\'avviso: «Domanda salvata. Voti ricalcolati: cambiano 2 tentativi di 2 studenti.»', avviso === 'Domanda salvata. Voti ricalcolati: cambiano 2 tentativi di 2 studenti.', [avviso]);

        // --- 6. la soglia ----------------------------------------------------
        prima = fotografia();
        const impostazioni = (s, piu = []) => [['title', uno('SELECT title AS t FROM quizzes WHERE id = ?', quiz).t], ['passing_score_pct', String(s)], ...piu];
        r = await posta(page, '/quizzes/' + quiz, impostazioni(70), pagina);
        const conferma2 = testo(r.testo);
        check('la soglia da 60% a 70%: prima la conferma, con chi non supera più e i moduli che si richiudono',
            r.stato === 200 && conferma2.includes('Hai cambiato la soglia di superamento da 60% a 70%.')
            && conferma2.includes('Il ricalcolo cambia l’esito di 1 tentativo di 1 studente.')
            && conferma2.includes('1 studente non risulta più aver superato il questionario.')
            && conferma2.includes('Il questionario è obbligatorio: per questo studente i moduli successivi si richiudono.') && fotografia() === prima,
        [conferma2.slice(conferma2.indexOf('Hai cambiato'), conferma2.indexOf('Hai cambiato') + 400)]);
        r = await posta(page, '/quizzes/' + quiz, impostazioni(70, [['conferma_ricalcolo', '1']]), pagina);
        check('confermata: soglia 70%, il 66,67% non è più superato, il 100% sì', r.rimando && soglia() === 70
            && tentativo(x1) === '66.67/0' && tentativo(x2) === '33.33/0' && tentativo(x3) === '100.00/1', [fotografia()]);
        prima = fotografia();
        r = await posta(page, '/quizzes/' + quiz, impostazioni(71), pagina);
        check('una soglia che non sposta nessun esito (70% → 71%) si salva subito', r.rimando && soglia() === 71 && fotografia().split(' | ')[0] === prima.split(' | ')[0]);
        await page.goto(BASE + pagina);
        const avviso2 = await page.evaluate(() => document.querySelector('.alert-success')?.textContent.trim() || '');
        check('l\'avviso: «Impostazioni salvate. Nessun voto cambia.»', avviso2 === 'Impostazioni salvate. Nessun voto cambia.', [avviso2]);

        // --- 7. lo studente vede il nuovo esito ------------------------------
        const stud = await entra(browser, 'stud@test.it');
        await stud.page.goto(BASE + '/attempts/' + x1);
        const esito = await stud.page.evaluate(() => (document.querySelector('.result-panel')?.textContent || '').replace(/\s+/g, ' ').trim());
        check('lo studente: «67% Questionario non superato · 2 risposte corrette su 3 · soglia richiesta 71%»',
            esito.startsWith('67% Questionario non superato 2 risposte corrette su 3 · soglia richiesta 71%'), [esito]);

        // --- 8. l'H5P: la soglia non si cambia --------------------------------
        const h5p = Number(php(`$pdo->prepare("INSERT INTO quizzes (module_id, title, passing_score_pct, kind, h5p_dir, h5p_library, h5p_file) VALUES (?, '[ricalcolo] H5P', 60, 'h5p', 'q999-aaaaaaaaaaaa', 'H5P.DragText 1.10', 'prova.h5p')")->execute([(int) $argv[1]]);`
            + ` $id = (int) $pdo->lastInsertId(); App\\Models\\QuizAttemptModel::create((int) $argv[2], $id, 50.0, false, [], ['raw' => 1, 'max' => 2]); echo $id;`, A.modulo_dopo, studente));
        await page.goto(BASE + '/quizzes/' + h5p + '/edit');
        const campo = await page.evaluate(() => ({ spento: document.getElementById('quiz-soglia').disabled, frase: document.getElementById('quiz-soglia-aiuto').textContent.replace(/\s+/g, ' ') }));
        check('H5P con un tentativo: il campo della soglia è spento, con la frase', campo.spento && campo.frase.includes('Il questionario ha già 1 tentativo: la soglia non si può più cambiare.'), [JSON.stringify(campo)]);
        await posta(page, '/quizzes/' + h5p, [['title', '[ricalcolo] H5P rinominato'], ['passing_score_pct', '90']], '/quizzes/' + h5p + '/edit');
        const dopoH5p = uno('SELECT title, passing_score_pct AS s FROM quizzes WHERE id = ?', h5p);
        check('H5P: il titolo si salva, una soglia mandata a mano no', dopoH5p.title === '[ricalcolo] H5P rinominato' && Number(dopoH5p.s) === 60, [JSON.stringify(dopoH5p)]);

        // --- 9. controprova: senza tentativi si fa tutto ----------------------
        php('$pdo->prepare("DELETE FROM quiz_attempts WHERE quiz_id = ?")->execute([(int) $argv[1]]);', quiz);
        await page.goto(BASE + pagina);
        check('controprova, senza tentativi: c\'è «Nuova domanda» e nessun «Elimina» spento',
            await page.locator('form[action$="/questions"]').count() === 1 && await page.locator('.question-item-actions .comando-spento').count() === 0);
        await posta(page, '/quizzes/' + quiz + '/questions', [['question_text', '[ricalcolo] Aggiunta'], ['question_type', 'open']], pagina);
        const aggiunta = uno("SELECT id FROM quiz_questions WHERE quiz_id = ? AND question_text = '[ricalcolo] Aggiunta'", quiz);
        await posta(page, '/questions/' + q3, multipla('[ricalcolo] Ora singola', ['Emme uno', 'Emme 2', 'Emme tre'], [1], 'single_choice'), indietroDomanda);
        if (aggiunta) {
            await posta(page, '/questions/' + aggiunta.id + '/delete', [], pagina);
        }
        check('controprova: si aggiunge, si cambia il tipo e si elimina',
            aggiunta !== null && uno('SELECT question_type AS t FROM quiz_questions WHERE id = ?', q3).t === 'single_choice' && domande() === 4);
        r = await posta(page, '/quizzes/' + quiz, impostazioni(50), pagina);
        check('controprova: senza tentativi la soglia si cambia senza conferma', r.rimando && soglia() === 50);
    } finally {
        await browser.close();
        semina();
    }

    console.log('\n' + ok + ' OK, ' + fail + ' FAIL');
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
