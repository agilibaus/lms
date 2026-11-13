/**
 * Il supervisore (30/10, Alessandro): aiuta l'admin a costruire i corsi.
 *
 *   - lavora solo sui corsi in bozza: li vede, li crea, ne modifica i dati e
 *     i contenuti, ne elimina moduli, lezioni e questionari;
 *   - non pubblica (lo fa l'admin) e non elimina un corso;
 *   - dal 06/11 legge i corsi pubblicati (in «Corsi», pagina, lezioni,
 *     questionari) senza modificarli; non invia questionari e non segna
 *     lezioni come fatte, in nessun corso;
 *   - non gestisce utenti, iscrizioni dalla scheda del corso, incontri, catalogo
 *     (dal 13/11 il catalogo rimanda a «Corsi», come per tutto lo staff: lo
 *     prova `staff_studenti.js`);
 *   - dal 06/11 crea e gestisce tutti i gruppi (dati, tutor, membri, corsi
 *     anche pubblicati, date dei moduli), ma non li elimina e non apre la
 *     pagina del gruppo dei partecipanti;
 *   - dal 06/11 gestisce la Bacheca di tutti i corsi, anche pubblicati
 *     (risorse, proposte, categorie), dalla voce «Bacheche» del menu (cosi'
 *     dal 13/11: dentro la bacheca di un corso e' accesa solo la «Bacheca»
 *     del ramo del corso);
 *   - dal 06/11 vede i report di tutti, come l'admin, anche dei corsi
 *     pubblicati, con i tentativi, e scarica i file;
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
const STUDENTE_GRUPPO = 'studente-gruppo-supervisore@test.it';

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
        + ' $pdo->exec("DELETE FROM `groups` WHERE name LIKE \'[prova-supervisore]%\'");'
        + ' $pdo->prepare("DELETE FROM users WHERE email IN (?, ?)")->execute([$argv[1], $argv[2]]);', EMAIL, STUDENTE_GRUPPO);
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
        const devono = ['Corsi', 'Report', 'Bacheche', 'Gestione corsi', 'Gruppi', 'Impostazioni', 'Profilo'];
        const nonDevono = ['I miei corsi', 'Esplora corsi', 'Agenda', 'Sessioni live', 'Certificati', 'Utenti', 'Domande'];
        check('menu: «Corsi», «Report», «Bacheche», «Gestione corsi», «Gruppi», «Impostazioni», «Profilo», e niente altro di quello che non e\' suo',
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
            // I report di tutti, come l'admin (06/11), anche dei corsi pubblicati.
            ['/reports', 'i report'],
            ['/reports/fermi', 'gli studenti fermi'],
            ['/reports/courses/' + A.corso, 'il report di un corso pubblicato'],
            ['/reports/courses/' + A.corso + '/griglia', 'la griglia di un corso pubblicato'],
            ['/reports/courses/' + bozza.corso, 'il report del corso in bozza'],
            ['/reports/students/' + A.studente, 'il report di uno studente'],
            ['/reports/groups/' + A.gruppo, 'il report di un gruppo'],
            ['/reports/live/' + A.incontro_concluso, 'il report di un incontro'],
            ['/reports/fruizione/' + A.corso, 'la fruizione dei video di un corso pubblicato'],
            ['/lessons/' + A.lezione + '/fruizione', 'la fruizione di una lezione di un corso pubblicato'],
            ['/lessons/' + bozza.lezione + '/fruizione', 'la fruizione di una lezione del corso in bozza'],
            ['/attempts/' + A.tentativo, 'il tentativo di uno studente, dal suo report'],
            // I contenuti dei corsi pubblicati, in lettura (06/11).
            ['/courses/' + A.corso, 'la pagina di un corso pubblicato'],
            ['/lessons/' + A.lezione, 'una lezione di un corso pubblicato'],
            ['/quizzes/' + A.quiz, 'un questionario di un corso pubblicato'],
            // I gruppi (06/11): tutti, come l'admin.
            ['/admin/groups', 'i gruppi'],
            ['/admin/groups/create', 'nuovo gruppo'],
            ['/admin/groups/' + A.gruppo + '/edit', 'la scheda di un gruppo'],
            ['/admin/groups/' + A.gruppo + '/courses/' + A.corso + '/date', 'le date dei moduli di un gruppo'],
            // La Bacheca di tutti i corsi (06/11).
            ['/bacheca/corsi', 'l\'elenco delle bacheche'],
            ['/bacheca?corso=' + A.corso, 'la bacheca di un corso pubblicato'],
            ['/bacheca/corsi/' + A.corso + '/categorie', 'le categorie della bacheca di un corso pubblicato'],
            ['/bacheca/proposte?corso=' + A.corso, 'le proposte da valutare di un corso pubblicato'],
            ['/bacheca/nuova?corso=' + A.corso, 'nuova risorsa in un corso pubblicato'],
            ['/bacheca/risorse/' + A.bacheca_risorsa + '/modifica', 'modifica di una risorsa di un corso pubblicato'],
        ];
        for (const [url, cosa] of consentite) {
            const e = await esitoGet(page, url);
            check('supervisore → ' + url + ' (' + cosa + '): consentito', e === 'consentito', [e]);
        }

        // Una voce accesa sola (13/11, Alessandro, A): nella bacheca di un
        // corso, e nelle sue categorie, la «Bacheca» del ramo; nell'elenco di
        // tutte, «Bacheche», che e' anche il titolo della pagina. Prima nella
        // bacheca di un corso erano accese tutte e due.
        const accese = async (url) => {
            await page.goto(BASE + url);
            return page.evaluate(() => ({
                accese: [...document.querySelectorAll('.sidebar-nav .nav-link-active')].map((a) => (a.closest('.nav-corso') ? 'ramo: ' : '') + a.textContent.trim()),
                titolo: (document.querySelector('h1') || {}).textContent || '',
                scheda: document.title,
            }));
        };
        for (const [url, cosa] of [
            ['/bacheca?corso=' + A.corso, 'nella bacheca di un corso'],
            ['/bacheca/corsi/' + A.corso + '/categorie', 'nelle categorie della bacheca di un corso'],
        ]) {
            const v = await accese(url);
            check('menu ' + cosa + ': accesa solo la «Bacheca» del ramo del corso', // accanto alla voce del ramo puo' esserci il numero delle proposte da valutare
                v.accese.length === 1 && /^ramo: Bacheca( |$)/.test(v.accese[0]), [JSON.stringify(v)]);
        }
        const tutte = await accese('/bacheca/corsi');
        check('nell\'elenco di tutte le bacheche: accesa solo «Bacheche», e la pagina si chiama «Bacheche»',
            tutte.accese.length === 1 && tutte.accese[0] === 'Bacheche' && tutte.titolo.trim() === 'Bacheche' && tutte.scheda.startsWith('Bacheche'), [JSON.stringify(tutte)]);

        // Arrivati da «Bacheche» (13/11, Alessandro): nella bacheca del corso
        // il ritorno e' «◀ Bacheche», e lo resta passando da categorie,
        // proposte, nuova risorsa e gestione delle categorie, anche dopo
        // un'azione. Arrivati dal ramo del corso, il ritorno e' il corso.
        const indietro = () => page.evaluate(() => {
            const a = document.querySelector('a.back-link');
            return a ? { testo: a.textContent.trim(), href: a.getAttribute('href') } : null;
        });
        const conSegno = (h) => typeof h === 'string' && h.includes('da=bacheche');
        const titoloA = php('$s = $pdo->prepare("SELECT title FROM courses WHERE id = ?"); $s->execute([$argv[1]]); echo $s->fetchColumn();', A.corso).trim();

        await page.goto(BASE + '/bacheca/corsi');
        const versoA = await page.evaluate((id) => [...document.querySelectorAll('a[href^="/bacheca?corso=' + id + '"]')].map((a) => a.getAttribute('href')), A.corso);
        check('«Bacheche»: il nome del corso e «Apri la bacheca» portano il segno da=bacheche',
            versoA.length === 2 && versoA.every(conSegno), [JSON.stringify(versoA)]);
        await page.click('a[href^="/bacheca?corso=' + A.corso + '&"]:has-text("Apri la bacheca")');
        await page.waitForLoadState('load');
        let r = await indietro();
        check('aperta da «Bacheche», la bacheca del corso ha «◀ Bacheche», che riporta all\'elenco',
            r !== null && r.testo === 'Bacheche' && r.href === '/bacheca/corsi', [JSON.stringify(r)]);

        // Dentro la bacheca: ogni pagina rimanda indietro con il segno.
        const dentro = await page.evaluate(() => [...document.querySelectorAll('main a[href^="/bacheca/"]')]
            .map((a) => a.getAttribute('href')).filter((h) => !h.startsWith('/bacheca/corsi?') && h !== '/bacheca/corsi'));
        check('nella bacheca, categorie, «Valuta», «Gestione categorie» e «Nuova risorsa» portano il segno',
            dentro.length >= 4 && dentro.every(conSegno), [JSON.stringify(dentro)]);
        for (const [url, cosa] of [
            ['/bacheca/categorie/' + A.bacheca_categoria, 'una categoria'],
            ['/bacheca/proposte?corso=' + A.corso, 'le proposte da valutare'],
            ['/bacheca/nuova?corso=' + A.corso, 'nuova risorsa'],
            ['/bacheca/risorse/' + A.bacheca_risorsa + '/modifica', 'modifica di una risorsa'],
            ['/bacheca/corsi/' + A.corso + '/categorie', 'gestione categorie'],
        ]) {
            await page.goto(BASE + url + (url.includes('?') ? '&' : '?') + 'da=bacheche');
            const v = await page.evaluate(() => ({
                indietro: (document.querySelector('a.back-link') || {}).getAttribute ? document.querySelector('a.back-link').getAttribute('href') : null,
                annulla: [...document.querySelectorAll('main a.btn')].filter((a) => a.textContent.trim() === 'Annulla').map((a) => a.getAttribute('href')),
                moduli: [...document.querySelectorAll('main form[method="post"]')].filter((f) => !f.action.includes('/tengo') && !f.action.includes('/uso')),
            }));
            const moduliSenza = await page.evaluate(() => [...document.querySelectorAll('main form[method="post"]')]
                .filter((f) => !f.action.includes('/tengo') && !f.action.includes('/uso'))
                .filter((f) => !f.querySelector('input[name="da"][value="bacheche"]')).map((f) => f.getAttribute('action')));
            check('da «Bacheche», ' + cosa + ': il ritorno, «Annulla» e i moduli portano il segno',
                conSegno(v.indietro) && v.annulla.every(conSegno) && moduliSenza.length === 0, [JSON.stringify({ indietro: v.indietro, annulla: v.annulla, moduliSenza })]);
        }

        // Dopo un'azione si resta «da Bacheche»: metti in evidenza e togli,
        // dalla pagina della categoria; sposta giu' e su una categoria,
        // dalla gestione delle categorie.
        const dopoAzione = async (pagina, bottone) => {
            await page.goto(BASE + pagina);
            // Il modulo come lo invia il suo pulsante: alcuni (le categorie)
            // stanno in un pannello che si apre, e il clic non li raggiunge.
            await Promise.all([page.waitForNavigation(), page.evaluate((t) => {
                const b = [...document.querySelectorAll('main button')].find((x) => x.textContent.includes(t));
                b.form.requestSubmit(b);
            }, bottone)]);
            return page.url();
        };
        let u1 = await dopoAzione('/bacheca/categorie/' + A.bacheca_categoria + '?da=bacheche', 'Metti in evidenza');
        let u2 = await dopoAzione('/bacheca/categorie/' + A.bacheca_categoria + '?da=bacheche', 'Togli dall’evidenza');
        check('da «Bacheche», dopo «Metti in evidenza» e «Togli dall’evidenza» si resta con il segno', conSegno(u1) && conSegno(u2), [u1, u2]);
        let u3 = await dopoAzione('/bacheca/corsi/' + A.corso + '/categorie?da=bacheche', 'Sposta giù');
        let u4 = await dopoAzione('/bacheca/corsi/' + A.corso + '/categorie?da=bacheche', 'Sposta su');
        check('da «Bacheche», dopo «Sposta giù» e «Sposta su» di una categoria si resta con il segno', conSegno(u3) && conSegno(u4), [u3, u4]);
        await page.goto(BASE + '/bacheca/categorie/' + A.bacheca_categoria + '?da=bacheche');
        await page.click('a.back-link');
        await page.waitForLoadState('load');
        r = await indietro();
        check('dalla categoria, tornati alla bacheca: ancora «◀ Bacheche»', r !== null && r.testo === 'Bacheche', [JSON.stringify(r)]);

        // Controprova: dal ramo del corso, senza segno, tutto come prima.
        await page.goto(BASE + '/bacheca?corso=' + A.corso);
        r = await indietro();
        const senza = await page.evaluate(() => [...document.querySelectorAll('main a[href^="/bacheca/"]')].map((a) => a.getAttribute('href')).filter((h) => h.includes('da=')));
        check('controprova: dal ramo del corso il ritorno è il corso, e nessun collegamento porta il segno',
            r !== null && r.testo === titoloA && r.href === '/courses/' + A.corso && senza.length === 0, [JSON.stringify(r), JSON.stringify(senza)]);
        u1 = await dopoAzione('/bacheca/categorie/' + A.bacheca_categoria, 'Metti in evidenza');
        u2 = await dopoAzione('/bacheca/categorie/' + A.bacheca_categoria, 'Togli dall’evidenza');
        check('controprova: senza segno, dopo un\'azione il segno non compare', !conSegno(u1) && !conSegno(u2), [u1, u2]);

        // E vede davvero tutti, non pagine vuote: gli studenti del corso
        // pubblicato, come l'admin.
        await page.goto(BASE + '/reports/courses/' + A.corso);
        const righeReport = await page.$$eval('table tbody tr', (r) => r.length);
        const iscrittiA = conta('SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? AND u.role = \'studente\'', A.corso);
        check('il report del corso pubblicato mostra tutti i suoi studenti (' + iscrittiA + ')', iscrittiA > 0 && righeReport === iscrittiA, [righeReport + ' righe']);

        // I file scaricati: si chiedono come file, non come pagine (06/11).
        for (const [url, cosa] of [
            ['/reports/courses/' + A.corso + '/csv', 'il CSV del report di un corso'],
            ['/reports/courses/' + A.corso + '/xlsx', 'l\'XLSX del report di un corso'],
            ['/reports/students/' + A.studente + '/xlsx', 'l\'XLSX del report di uno studente'],
            ['/lessons/' + A.lezione + '/fruizione/csv', 'il CSV della fruizione di una lezione'],
        ]) {
            const r = await ctx.request.get(BASE + url, { maxRedirects: 0 });
            const tipo = String(r.headers()['content-type'] || '');
            check('supervisore → ' + url + ' (' + cosa + '): scaricato', r.status() === 200 && !tipo.includes('text/html'), [r.status() + ' ' + tipo]);
        }

        const negate = [
            ['/admin/courses/' + A.corso + '/edit', 'la scheda di un corso pubblicato'],
            ['/lessons/' + A.lezione + '/edit', 'modifica di una lezione di un corso pubblicato'],
            ['/modules/' + A.modulo + '/edit', 'un modulo di un corso pubblicato'],
            ['/quizzes/' + A.quiz + '/edit', 'modifica di un questionario di un corso pubblicato'],
            ['/admin/users', 'gli utenti'],
            ['/gruppi/' + A.gruppo, 'la pagina del gruppo dei partecipanti'],
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
        check('«Corsi»: il corso in bozza e, dal 06/11, anche quello pubblicato', html.includes('[prova-supervisore] corso in bozza') && html.includes('href="/courses/' + A.corso + '"'));

        // --- i corsi pubblicati, in sola lettura (06/11) ----------------------
        const idSup = conta('SELECT id FROM users WHERE email = ?', EMAIL);
        await page.goto(BASE + '/courses/' + A.corso);
        const comandiCorso = await page.evaluate(() => ({
            modifica: [...document.querySelectorAll('main a[href], .main-content a[href]')].map((a) => a.getAttribute('href'))
                .filter((h) => /\/(edit|new|create)(\b|$)|\/admin\/courses\//.test(h)),
        }));
        check('nella pagina del corso pubblicato nessun comando di modifica', comandiCorso.modifica.length === 0, [JSON.stringify(comandiCorso)]);
        await page.goto(BASE + '/quizzes/' + A.quiz);
        const quizVista = await page.evaluate(() => ({
            domande: document.querySelectorAll('.quiz-question-list > li').length,
            spento: !!document.querySelector('fieldset.quiz-concluso-campi[disabled]'),
            invia: !!document.querySelector('.quiz-form button[type="submit"]'),
            frase: [...document.querySelectorAll('.quiz-form .form-hint')].map((p) => p.textContent.trim()).join(' | '),
        }));
        check('il questionario: le domande si vedono, spente, senza «Invia», con «Sola lettura: le risposte non si inviano.»',
            quizVista.domande > 0 && quizVista.spento && !quizVista.invia && quizVista.frase.includes('Sola lettura: le risposte non si inviano.'), [JSON.stringify(quizVista)]);
        const mieiTentativi = () => conta('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ?', idSup);
        const mieFatte = () => conta('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ?', idSup);
        const t0 = mieiTentativi();
        // Le risposte a tutte le domande, come le manderebbe il modulo: senza,
        // l'invio si ferma prima («Rispondi a tutte le domande»).
        const risposte = (quiz) => JSON.parse(php('$c = []; $q = $pdo->prepare("SELECT id, question_type FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id"); $q->execute([(int) $argv[1]]);'
            + ' foreach ($q->fetchAll() as $d) { $o = $pdo->prepare("SELECT id FROM quiz_options WHERE question_id = ? ORDER BY id LIMIT 1"); $o->execute([$d["id"]]); $op = $o->fetchColumn();'
            + ' if ($d["question_type"] === "open") { $c[] = ["answers[" . $d["id"] . "]", "Una risposta"]; }'
            + ' elseif ($d["question_type"] === "multiple_choice") { $c[] = ["answers[" . $d["id"] . "][]", (string) $op]; }'
            + ' else { $c[] = ["answers[" . $d["id"] . "]", (string) $op]; } } echo json_encode($c);', quiz));
        for (const quiz of [A.quiz, bozza.quiz]) {
            await posta(page, '/quizzes/' + quiz + '/attempts', risposte(quiz), '/quizzes/' + quiz);
        }
        check('un invio del questionario non salva tentativi, ne\' nel pubblicato ne\' nella bozza', mieiTentativi() === t0 && t0 === 0, [String(mieiTentativi())]);
        await posta(page, '/lessons/' + A.lezione + '/complete', [], '/lessons/' + A.lezione);
        await posta(page, '/lessons/' + bozza.lezione + '/complete', [], '/lessons/' + bozza.lezione);
        check('«Segna come fatta» non scrive niente, ne\' nel pubblicato ne\' nella bozza', mieFatte() === 0, [String(mieFatte())]);
        await page.goto(BASE + '/lessons/' + A.lezione);
        await page.waitForTimeout(500);
        check('aprire una lezione del corso pubblicato non scrive niente a suo nome',
            mieFatte() === 0 && conta('SELECT COUNT(*) FROM lesson_video_progress WHERE user_id = ?', idSup) === 0 && conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', idSup) === 0);

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

        // --- controprova: pubblicato dall'admin, il corso non e' piu' suo -----
        php('$pdo->prepare("UPDATE courses SET is_published = 1 WHERE id = ?")->execute([$argv[1]]);', bozza.corso);
        const pubblicato = await esitoGet(page, '/admin/courses/' + bozza.corso + '/edit');
        const letto = await esitoGet(page, '/courses/' + bozza.corso);
        await posta(page, '/courses/' + bozza.corso + '/modules', [['title', 'Modulo dopo la pubblicazione'], ['objective', '']], '/admin/courses');
        check('controprova: pubblicato dall\'admin, il corso lo legge ma non lo modifica piu\'',
            pubblicato === 'negato' && letto === 'consentito' && conta('SELECT COUNT(*) FROM modules WHERE course_id = ? AND title = ?', bozza.corso, 'Modulo dopo la pubblicazione') === 0, [pubblicato, letto]);


        // --- i gruppi: le azioni, giudicate dal database (06/11) -----------------
        const studenteGruppo = Number(php('$h = password_hash($argv[1], PASSWORD_DEFAULT);'
            + ' $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Studente\', \'Gruppo\', \'studente\', 1, NOW(), NOW())")->execute([$argv[2], $h]);'
            + ' echo (int) $pdo->lastInsertId();', PASSWORD, STUDENTE_GRUPPO).trim());
        await posta(page, '/admin/groups', [['name', '[prova-supervisore] gruppo'], ['description', 'Creato dal supervisore.'], ['tutor_id', String(A.tutor)]], '/admin/groups/create');
        const gruppoNuovo = conta('SELECT id FROM `groups` WHERE name = ?', '[prova-supervisore] gruppo');
        check('crea un gruppo, con il tutor responsabile', gruppoNuovo > 0 && conta('SELECT tutor_id FROM `groups` WHERE id = ?', gruppoNuovo) === Number(A.tutor), [String(gruppoNuovo)]);
        await posta(page, '/admin/groups/' + gruppoNuovo, [['name', '[prova-supervisore] gruppo'], ['description', 'Modificato dal supervisore.'], ['tutor_id', String(A.tutor)]], '/admin/groups/' + gruppoNuovo + '/edit');
        check('modifica il gruppo', conta('SELECT COUNT(*) FROM `groups` WHERE id = ? AND description = ?', gruppoNuovo, 'Modificato dal supervisore.') === 1);
        await posta(page, '/admin/groups/' + gruppoNuovo + '/courses', [['course_id', String(A.corso)]], '/admin/groups/' + gruppoNuovo + '/edit');
        check('assegna al gruppo un corso pubblicato', conta('SELECT COUNT(*) FROM group_course_access WHERE group_id = ? AND course_id = ?', gruppoNuovo, A.corso) === 1);
        await posta(page, '/admin/groups/' + gruppoNuovo + '/members', [['user_id', String(studenteGruppo)]], '/admin/groups/' + gruppoNuovo + '/edit');
        check('aggiunge un membro, che viene iscritto al corso del gruppo',
            conta('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?', gruppoNuovo, studenteGruppo) === 1
            && conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?', studenteGruppo, A.corso) === 1);
        await posta(page, '/admin/groups/' + gruppoNuovo + '/members/' + studenteGruppo + '/delete', [], '/admin/groups/' + gruppoNuovo + '/edit');
        check('toglie il membro', conta('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?', gruppoNuovo, studenteGruppo) === 0);
        await posta(page, '/admin/groups/' + gruppoNuovo + '/courses/' + A.corso + '/delete', [], '/admin/groups/' + gruppoNuovo + '/edit');
        check('toglie il corso dal gruppo', conta('SELECT COUNT(*) FROM group_course_access WHERE group_id = ? AND course_id = ?', gruppoNuovo, A.corso) === 0);

        // Non elimina: niente pulsanti, e una POST non elimina.
        await page.goto(BASE + '/admin/groups/' + gruppoNuovo + '/edit');
        const scheda2 = await page.evaluate(() => ({
            elimina: !!document.querySelector('form[action$="/delete"] button.link-btn-danger:not([formaction])')
                && [...document.querySelectorAll('form[action$="/delete"]')].some((f) => /\/admin\/groups\/\d+\/delete$/.test(f.getAttribute('action'))),
            vaiAlGruppo: !!document.querySelector('a[href^="/gruppi/"]'),
        }));
        check('nella scheda del gruppo niente «Elimina gruppo» e niente «vai al gruppo»', !scheda2.elimina && !scheda2.vaiAlGruppo, [JSON.stringify(scheda2)]);
        await page.goto(BASE + '/admin/groups');
        const elenco = await page.evaluate(() => ({
            elimina: [...document.querySelectorAll('form')].some((f) => /\/admin\/groups\/\d+\/delete$/.test(f.getAttribute('action') || '')),
            pagina: !!document.querySelector('a[href^="/gruppi/"]'),
            nuovo: !!document.querySelector('a[href="/admin/groups/create"]'),
            aiuto: ((document.querySelector('.gruppi-aiuto') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
        }));
        check('nell\'elenco dei gruppi «Nuovo gruppo», ma niente «Elimina» e niente pagina del gruppo; l\'aiuto dice solo il report',
            elenco.nuovo && !elenco.elimina && !elenco.pagina && elenco.aiuto === 'Tocca un gruppo per i dettagli; «⋯» per il report.', [JSON.stringify(elenco)]);
        await posta(page, '/admin/groups/' + gruppoNuovo + '/delete', [], '/admin/groups/' + gruppoNuovo + '/edit');
        check('una POST non elimina il gruppo', conta('SELECT COUNT(*) FROM `groups` WHERE id = ?', gruppoNuovo) === 1);

        // --- la Bacheca: le azioni, giudicate dal database (06/11) ---------------
        await page.goto(BASE + '/bacheca?corso=' + A.corso);
        const testaB = await page.evaluate((corso) => ({
            ritorno: (document.querySelector('.page-header .back-link') || {}).getAttribute ? document.querySelector('.page-header .back-link').getAttribute('href') : null,
            // Nessun collegamento ai contenuti di questo corso (la barra
            // laterale ha i suoi corsi in bozza, che apre).
            alCorso: [...document.querySelectorAll('a[href]')].some((a) => /^\/courses\/(\d+)/.test(a.getAttribute('href')) && a.getAttribute('href').match(/^\/courses\/(\d+)/)[1] === String(corso)),
            categorie: (document.querySelector('a.bacheca-comando[href$="/categorie"]') || {}).getAttribute ? document.querySelector('a.bacheca-comando[href$="/categorie"]').getAttribute('href') : null,
        }), A.corso);
        check('nella bacheca di un corso pubblicato: il ritorno al corso, che dal 06/11 legge, e «Gestione categorie» alla pagina nuova',
            testaB.ritorno === '/courses/' + A.corso && testaB.alCorso && testaB.categorie === '/bacheca/corsi/' + A.corso + '/categorie', [JSON.stringify(testaB)]);
        await page.goto(BASE + '/bacheca/corsi');
        const righeB = await page.$$eval('table tbody tr td[data-label="Corso"]', (t) => t.map((x) => x.textContent.trim()));
        const tuttiCorsi = conta('SELECT COUNT(*) FROM courses');
        check('l\'elenco «Bacheca» ha tutti i corsi, anche pubblicati e in bozza (' + tuttiCorsi + ')', righeB.length === tuttiCorsi, [righeB.length + ' righe']);

        await posta(page, '/admin/courses/' + A.corso + '/bacheca/categorie', [['name', '[prova-supervisore] categoria'], ['color', '#4f7256'], ['torna', 'bacheca']], '/bacheca/corsi/' + A.corso + '/categorie');
        const catNuova = conta('SELECT id FROM board_categories WHERE course_id = ? AND name = ?', A.corso, '[prova-supervisore] categoria');
        check('crea una categoria nella bacheca di un corso pubblicato', catNuova > 0, [String(catNuova)]);
        await page.goto(BASE + '/bacheca/corsi/' + A.corso + '/categorie');
        const tornaVisto = await page.$$eval('form input[name="torna"][value="bacheca"]', (x) => x.length);
        check('nella pagina delle categorie ogni modulo torna li\' («torna» = bacheca)', tornaVisto >= 2, [String(tornaVisto)]);
        await posta(page, '/admin/bacheca/categorie/' + catNuova, [['name', '[prova-supervisore] categoria rinominata'], ['color', '#4f7256'], ['torna', 'bacheca']], '/bacheca/corsi/' + A.corso + '/categorie');
        check('la rinomina', conta('SELECT COUNT(*) FROM board_categories WHERE id = ? AND name = ?', catNuova, '[prova-supervisore] categoria rinominata') === 1);
        await posta(page, '/admin/bacheca/categorie/' + catNuova + '/elimina', [['torna', 'bacheca']], '/bacheca/corsi/' + A.corso + '/categorie');
        check('la elimina (e\' vuota)', conta('SELECT COUNT(*) FROM board_categories WHERE id = ?', catNuova) === 0);
        // E dopo l'azione si torna alla pagina delle categorie della bacheca.
        await page.goto(BASE + '/bacheca/corsi/' + A.corso + '/categorie');
        const tokenB = await page.$eval('input[name="_token"]', (i) => i.value);
        const rB = await ctx.request.post(BASE + '/admin/courses/' + A.corso + '/bacheca/categorie',
            { form: { _token: tokenB, name: '[prova-supervisore] ritorno', color: '#4f7256', torna: 'bacheca' }, maxRedirects: 0 });
        check('dopo l\'azione si torna alla pagina delle categorie della bacheca', String(rB.headers()['location'] || '').endsWith('/bacheca/corsi/' + A.corso + '/categorie'), [String(rB.status()), String(rB.headers()['location'])]);
        php('$pdo->prepare("DELETE FROM board_categories WHERE name = ?")->execute([$argv[1]]);', '[prova-supervisore] ritorno');

        const statoProposta = () => php('$s = $pdo->prepare("SELECT status FROM board_resources WHERE id = ?"); $s->execute([(int) $argv[1]]); echo $s->fetchColumn();', A.bacheca_proposta).trim();
        await posta(page, '/bacheca/risorse/' + A.bacheca_proposta + '/pubblica', [['category_id', String(A.bacheca_categoria)], ['module_id', String(A.modulo)]], '/bacheca/proposte?corso=' + A.corso);
        check('pubblica la proposta di uno studente', statoProposta() === 'published', [statoProposta()]);
        await posta(page, '/bacheca/risorse/' + A.bacheca_risorsa + '/togli', [], '/bacheca?corso=' + A.corso);
        check('toglie dalla bacheca una risorsa pubblicata', php('$s = $pdo->prepare("SELECT status FROM board_resources WHERE id = ?"); $s->execute([(int) $argv[1]]); echo $s->fetchColumn();', A.bacheca_risorsa).trim() === 'discarded');
        await ctx.close();

        // --- l'admin vede il ruolo nuovo -------------------------------------
        const admin = await entra(browser, 'admin@test.it');
        await admin.page.goto(BASE + '/admin/users/create');
        const ruoli = await admin.page.evaluate(() => [...document.querySelectorAll('#role option')].map((o) => o.textContent.trim()));
        await admin.page.goto(BASE + '/admin/permissions');
        const pagRuoli = await admin.page.content();
        check('admin: «Supervisore» fra i ruoli di «Nuovo utente», e descritto nella pagina «Ruoli»',
            ruoli.includes('Supervisore') && pagRuoli.includes('Supervisore') && pagRuoli.includes('Costruisce i corsi in bozza'), [ruoli.join(',')]);
        // Controprova della Bacheca: per l'admin il ritorno porta al corso, come prima.
        await admin.page.goto(BASE + '/bacheca?corso=' + A.corso);
        const ritornoAdmin = await admin.page.evaluate(() => document.querySelector('.page-header .back-link').getAttribute('href'));
        check('controprova: nella bacheca l\'admin torna al corso, come prima', ritornoAdmin === '/courses/' + A.corso, [ritornoAdmin]);
        // Controprova dell'eliminazione: l'admin il gruppo lo elimina.
        await posta(admin.page, '/admin/groups/' + gruppoNuovo + '/delete', [], '/admin/groups/' + gruppoNuovo + '/edit');
        check('controprova: l\'admin elimina il gruppo', conta('SELECT COUNT(*) FROM `groups` WHERE id = ?', gruppoNuovo) === 0);
        await admin.ctx.close();
    } finally {
        await browser.close();
        pulisci();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
