/**
 * Il supervisore (30/10, Alessandro): aiuta l'admin a costruire i corsi.
 *
 *   - lavora solo sui corsi in bozza: li vede, li crea, ne modifica i dati e
 *     i contenuti, ne elimina moduli, lezioni e questionari;
 *   - non pubblica (lo fa l'admin) e non elimina un corso;
 *   - i corsi pubblicati, per lui, non ci sono: non li vede e non li tocca;
 *   - non gestisce utenti, gruppi, iscrizioni, incontri, report, catalogo;
 *   - delle impostazioni apre solo «Video di benvenuto» e «Informazioni».
 *
 * Una suite a se', accanto a `permessi.js` (che gia' sfiora il limite di
 * tempo di un comando nel contenitore di Claude): usa la stessa semina dei
 * due mondi e lo stesso modo di decidere «consentito» e «negato» per le
 * pagine. Le azioni si giudicano da quello che e' cambiato nel database,
 * perche' diverse pagine rifiutano rimandando con un avviso.
 *
 * Esecuzione:  node tests/supervisore.js   (server di sviluppo acceso)
 *
 * Crea il supervisore supervisore@test.it e un corso «[prova-supervisore]»
 * in bozza, e alla fine li toglie, anche se un controllo fallisce.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const EMAIL = 'supervisore@test.it';

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

const json = (codice, ...argomenti) => JSON.parse(php(codice, ...argomenti));

/** Toglie quello che la suite crea: il corso in bozza, quelli creati dal supervisore, lui. */
function pulisci() {
    php('$pdo->exec("DELETE FROM courses WHERE title LIKE \'[prova-supervisore]%\'");'
        + ' $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', EMAIL);
}

/** Il supervisore di prova e un corso in bozza con un modulo, una lezione e un questionario. */
function prepara() {
    pulisci();
    return json('$h = password_hash($argv[1], PASSWORD_DEFAULT);'
        + ' $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at)'
        + ' VALUES (?, ?, \'Supervisore\', \'Prova\', \'supervisore\', 1, NOW(), NOW())")->execute([$argv[2], $h]);'
        + ' $u = (int) $pdo->lastInsertId();'
        + ' $pdo->prepare("INSERT INTO courses (title, slug, description, is_published, enrollment_mode, created_by) VALUES (?, ?, \'\', 0, \'closed\', ?)")'
        + '     ->execute([\'[prova-supervisore] corso in bozza\', \'prova-supervisore-bozza-\' . time(), $u]);'
        + ' $c = (int) $pdo->lastInsertId();'
        + ' $m = App\\Models\\ModuleModel::create($c, \'Modulo uno\');'
        + ' $l = App\\Models\\LessonModel::create($m, \'Lezione uno\', \'<p>Testo della lezione.</p>\', \'none\', null, 0);'
        + ' $q = App\\Models\\QuizModel::create($m, \'Questionario uno\', 70);'
        + ' App\\Core\\PrimaDomanda::salva($q, [\'Una domanda?\', \'open\', []]);'
        + ' echo json_encode([\'corso\' => $c, \'modulo\' => $m, \'lezione\' => $l, \'quiz\' => $q]);', PASSWORD, EMAIL);
}

function semina() {
    const uscita = execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php')], { encoding: 'utf8', cwd: RADICE });
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

/** Come `permessi.js`: `consentito` se la pagina si apre, `negato` se il server la rifiuta. */
async function esitoGet(page, url) {
    const risposta = await page.goto(BASE + url);
    const stato = risposta ? risposta.status() : 0;
    if (page.url().includes('/login')) {
        return 'negato';
    }
    return stato >= 400 ? 'negato' : 'consentito';
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

(async () => {
    console.log('Semina dei due mondi e del corso in bozza…');
    const A = semina().A;
    const bozza = prepara();
    const browser = await chromium.launch();

    try {
        const { ctx, page } = await entra(browser, EMAIL);
        const conta = (sql, ...a) => Number(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo (int) $s->fetchColumn();', sql, ...a).trim());

        // --- il menu -------------------------------------------------------
        await page.goto(BASE + '/admin/courses');
        const menu = await page.evaluate(() => [...document.querySelectorAll('.sidebar-nav a')].map((a) => a.textContent.trim()));
        const devono = ['Corsi', 'Gestione corsi', 'Impostazioni', 'Profilo'];
        const nonDevono = ['I miei corsi', 'Esplora corsi', 'Agenda', 'Sessioni live', 'Certificati', 'Report', 'Gruppi', 'Utenti', 'Domande'];
        check('menu: «Corsi», «Gestione corsi», «Impostazioni», «Profilo», e niente altro di quello che non e\' suo',
            devono.every((v) => menu.includes(v)) && nonDevono.every((v) => !menu.includes(v)), [menu.join(' | ')]);

        // --- le pagine -----------------------------------------------------
        const consentite = [
            ['/', 'i corsi (in bozza)'],
            ['/admin/courses', 'Gestione corsi'],
            ['/admin/courses/create', 'nuovo corso'],
            ['/admin/courses/' + bozza.corso + '/edit', 'la scheda del corso in bozza'],
            ['/courses/' + bozza.corso, 'i contenuti del corso in bozza'],
            ['/modules/' + bozza.modulo + '/edit', 'un modulo del corso in bozza'],
            ['/modules/' + bozza.modulo + '/lessons/create', 'nuova lezione nel corso in bozza'],
            ['/lessons/' + bozza.lezione, 'una lezione del corso in bozza'],
            ['/lessons/' + bozza.lezione + '/edit', 'modifica di una lezione del corso in bozza'],
            ['/quizzes/' + bozza.quiz + '/edit', 'il questionario del corso in bozza'],
            ['/quizzes/' + bozza.quiz, 'il questionario del corso in bozza, come lo vede lo studente'],
            ['/courses/' + bozza.corso + '/verifica-finale/create', 'nuova verifica finale nel corso in bozza'],
            ['/admin/settings', 'Impostazioni'],
            ['/admin/settings/benvenuto', 'Video di benvenuto'],
            ['/admin/settings/informazioni', 'Informazioni'],
        ];
        for (const [url, cosa] of consentite) {
            const e = await esitoGet(page, url);
            check('supervisore → ' + url + ' (' + cosa + '): consentito', e === 'consentito', [e]);
        }

        const negate = [
            ['/admin/courses/' + A.corso + '/edit', 'la scheda di un corso pubblicato'],
            ['/courses/' + A.corso, 'i contenuti di un corso pubblicato'],
            ['/lessons/' + A.lezione, 'una lezione di un corso pubblicato'],
            ['/lessons/' + A.lezione + '/edit', 'modifica di una lezione di un corso pubblicato'],
            ['/modules/' + A.modulo + '/edit', 'un modulo di un corso pubblicato'],
            ['/quizzes/' + A.quiz, 'un questionario di un corso pubblicato'],
            ['/quizzes/' + A.quiz + '/edit', 'modifica di un questionario di un corso pubblicato'],
            ['/catalogo', 'il catalogo, che iscrive ai corsi pubblicati'],
            ['/admin/users', 'gli utenti'],
            ['/admin/groups', 'i gruppi'],
            ['/reports', 'i report'],
            ['/reports/courses/' + bozza.corso, 'il report anche del corso in bozza'],
            ['/lessons/' + bozza.lezione + '/fruizione', 'la fruizione del video, che e\' un report'],
            ['/admin/settings/posta', 'il server di posta'],
            ['/admin/settings/email', 'i messaggi email'],
            ['/admin/settings/aspetto', 'l\'aspetto'],
            ['/admin/settings/meet', 'Google Meet'],
            ['/admin/settings/bunny', 'Bunny Stream'],
            ['/admin/permissions', 'i ruoli'],
            ['/tre-parole', 'le tre parole degli studenti'],
            ['/domande', 'le domande degli studenti'],
        ];
        for (const [url, cosa] of negate) {
            const e = await esitoGet(page, url);
            check('supervisore → ' + url + ' (' + cosa + '): negato', e === 'negato', [e]);
        }

        // --- gli elenchi: solo le bozze ------------------------------------
        await page.goto(BASE + '/admin/courses');
        let html = await page.content();
        check('Gestione corsi: c\'e\' il corso in bozza, non quello pubblicato, e niente «Report» ne\' file del server',
            html.includes('[prova-supervisore] corso in bozza') && !html.includes('href="/admin/courses/' + A.corso + '/edit"')
            && !html.includes('/reports/courses/') && !html.includes('orphan-summary'));
        await page.goto(BASE + '/');
        html = await page.content();
        check('«Corsi»: il corso in bozza si', html.includes('[prova-supervisore] corso in bozza') && !html.includes('href="/courses/' + A.corso + '"'));

        // --- la scheda del corso: «Pubblicato» spenta, niente iscritti -----
        await page.goto(BASE + '/admin/courses/' + bozza.corso + '/edit');
        const scheda = await page.evaluate(() => ({
            pubblica: (document.querySelector('input[name="is_published"]') || {}).disabled,
            frase: ((document.getElementById('pubblica-admin-hint') || {}).textContent || '').trim(),
            iscritti: [...document.querySelectorAll('h2')].some((h) => /Iscritti|Richieste/.test(h.textContent)),
            report: !!document.querySelector('a[href^="/reports/"]'),
        }));
        check('scheda del corso: «Pubblicato» spenta con «Il corso lo pubblica l’Amministratore», niente iscritti, richieste e report',
            scheda.pubblica === true && scheda.frase === 'Il corso lo pubblica l’Amministratore.' && !scheda.iscritti && !scheda.report, [JSON.stringify(scheda)]);

        // --- le azioni sul corso in bozza: si fanno -------------------------
        const campiCorso = (titolo) => [['title', titolo], ['slug', ''], ['description', ''], ['objective', ''], ['enrollment_mode', 'closed'], ['is_published', '1']];
        await posta(page, '/admin/courses/' + bozza.corso, campiCorso('[prova-supervisore] corso in bozza rinominato'), '/admin/courses/' + bozza.corso + '/edit');
        const dopo = json('$s = $pdo->prepare("SELECT title, is_published FROM courses WHERE id = ?"); $s->execute([$argv[1]]); echo json_encode($s->fetch(PDO::FETCH_ASSOC));', bozza.corso);
        check('salva la scheda del corso in bozza, ma «Pubblicato» mandato a mano non pubblica', dopo.title === '[prova-supervisore] corso in bozza rinominato' && Number(dopo.is_published) === 0, [JSON.stringify(dopo)]);

        await posta(page, '/admin/courses', campiCorso('[prova-supervisore] corso nuovo'), '/admin/courses/create');
        const nuovo = json('$s = $pdo->query("SELECT id, is_published FROM courses WHERE title = \'[prova-supervisore] corso nuovo\'"); echo json_encode($s->fetch(PDO::FETCH_ASSOC) ?: null);');
        check('crea un corso: nasce in bozza anche con «Pubblicato» mandato a mano', !!nuovo && Number(nuovo.is_published) === 0, [JSON.stringify(nuovo)]);

        await posta(page, '/courses/' + bozza.corso + '/modules', [['title', 'Modulo del supervisore'], ['objective', '']], '/courses/' + bozza.corso + '/modules/create');
        check('aggiunge un modulo al corso in bozza', conta('SELECT COUNT(*) FROM modules WHERE course_id = ? AND title = ?', bozza.corso, 'Modulo del supervisore') === 1);

        await posta(page, '/modules/' + bozza.modulo + '/lessons', [['title', 'Lezione del supervisore'], ['content', '<p>Ciao.</p>']], '/modules/' + bozza.modulo + '/lessons/create');
        check('aggiunge una lezione al corso in bozza', conta('SELECT COUNT(*) FROM lessons WHERE module_id = ? AND title = ?', bozza.modulo, 'Lezione del supervisore') === 1);

        await posta(page, '/lessons/' + bozza.lezione + '/delete', [], '/lessons/' + bozza.lezione + '/edit');
        check('elimina una lezione del corso in bozza', conta('SELECT COUNT(*) FROM lessons WHERE id = ?', bozza.lezione) === 0);

        // --- quello che non fa nemmeno sul corso in bozza --------------------
        await posta(page, '/admin/courses/' + bozza.corso + '/delete', [], '/admin/courses/' + bozza.corso + '/edit');
        check('non elimina il corso, nemmeno in bozza', conta('SELECT COUNT(*) FROM courses WHERE id = ?', bozza.corso) === 1);

        const studente = Number(php('echo (int) $pdo->query("SELECT id FROM users WHERE role = \'studente\' ORDER BY id LIMIT 1")->fetchColumn();').trim());
        await posta(page, '/admin/courses/' + bozza.corso + '/enrollments', [['user_id', String(studente)]], '/admin/courses/' + bozza.corso + '/edit');
        check('non iscrive studenti, nemmeno al corso in bozza', conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ?', bozza.corso) === 0);

        // --- sul corso pubblicato non fa niente ------------------------------
        const moduliA = conta('SELECT COUNT(*) FROM modules WHERE course_id = ?', A.corso);
        await posta(page, '/courses/' + A.corso + '/modules', [['title', 'Modulo intruso'], ['objective', '']], '/admin/courses/' + bozza.corso + '/edit');
        await posta(page, '/lessons/' + A.lezione + '/delete', [], '/admin/courses/' + bozza.corso + '/edit');
        check('sul corso pubblicato non aggiunge moduli e non elimina lezioni',
            conta('SELECT COUNT(*) FROM modules WHERE course_id = ?', A.corso) === moduliA && conta('SELECT COUNT(*) FROM lessons WHERE id = ?', A.lezione) === 1);

        // --- controprova: pubblicato dall'admin, il corso sparisce ------------
        php('$pdo->prepare("UPDATE courses SET is_published = 1 WHERE id = ?")->execute([$argv[1]]);', bozza.corso);
        const pubblicato = await esitoGet(page, '/admin/courses/' + bozza.corso + '/edit');
        await posta(page, '/courses/' + bozza.corso + '/modules', [['title', 'Modulo dopo la pubblicazione'], ['objective', '']], '/admin/courses');
        check('controprova: pubblicato dall\'admin, il corso per lui non c\'e\' piu\': non lo apre e non lo modifica',
            pubblicato === 'negato' && conta('SELECT COUNT(*) FROM modules WHERE course_id = ? AND title = ?', bozza.corso, 'Modulo dopo la pubblicazione') === 0, [pubblicato]);

        await ctx.close();

        // --- l'admin vede il ruolo nuovo -------------------------------------
        const admin = await entra(browser, 'admin@test.it');
        await admin.page.goto(BASE + '/admin/users/create');
        const ruoli = await admin.page.evaluate(() => [...document.querySelectorAll('#role option')].map((o) => o.textContent.trim()));
        await admin.page.goto(BASE + '/admin/permissions');
        const pagRuoli = await admin.page.content();
        check('admin: «Supervisore» fra i ruoli di «Nuovo utente», e descritto nella pagina «Ruoli»',
            ruoli.includes('Supervisore') && pagRuoli.includes('Supervisore') && pagRuoli.includes('Costruisce i corsi in bozza'), [ruoli.join(',')]);
        await admin.ctx.close();
    } finally {
        await browser.close();
        pulisci();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
