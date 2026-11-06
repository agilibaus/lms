/**
 * Una bozza e' un corso non ancora finito, e nessuno vi e' iscritto (06/11,
 * Alessandro, strada (c)):
 *
 *   1. uno studente non si iscrive mai a una bozza: ne' a mano, ne' da una
 *      richiesta, ne' entrando in un gruppo che ce l'ha;
 *   2. la bozza si assegna ai gruppi senza iscrivere nessuno (il tutor
 *      prepara benvenuto, date e incontri);
 *   3. alla pubblicazione vengono iscritti i membri dei gruppi a cui e'
 *      assegnata, con la conferma in coda;
 *   4. un corso con iscritti non torna in bozza.
 *
 * Gli incontri (06/11): un incontro del gruppo su un modulo di una bozza non
 * e' dei membri finche' il corso non e' pubblicato. Il tutor lo vede.
 *
 * E lo studente del gruppo, finche' il corso e' in bozza, non lo vede. Ogni
 * rifiuto ha la sua controprova.
 *
 * Esecuzione:  node tests/bozze.js   (server di sviluppo acceso)
 *
 * Crea i corsi «[prova-bozze] …» e lo studente nuovo-bozze@test.it, e alla
 * fine li toglie, anche se un controllo fallisce. Usa la semina dei due mondi.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const NUOVO = 'nuovo-bozze@test.it';

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
const sql = (query, ...a) => php('$pdo->prepare($argv[1])->execute(array_slice($argv, 2));', query, ...a);
const conta = (query, ...a) => Number(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo (int) $s->fetchColumn();', query, ...a).trim());
const iscritti = (corso) => conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ?', corso);
const iscritto = (corso, utente) => conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND user_id = ?', corso, utente) === 1;
const pubblicato = (corso) => conta('SELECT is_published FROM courses WHERE id = ?', corso) === 1;

function semina() {
    return JSON.parse(execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php')], { encoding: 'utf8', cwd: RADICE }));
}

function pulisci() {
    php('$pdo->exec("DELETE FROM courses WHERE title LIKE \'[prova-bozze]%\'");'
        + ' $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', NUOVO);
}

/** Una bozza con un modulo e una lezione, un corso pubblicato vuoto, uno su richiesta, e uno studente fuori dai gruppi. */
function prepara(A) {
    pulisci();
    return json('$admin = (int) $pdo->query("SELECT id FROM users WHERE email = \'admin@test.it\'")->fetchColumn();'
        + ' $nuovo = function (string $t, int $pub, string $modo) use ($pdo, $admin): int {'
        + '   $pdo->prepare("INSERT INTO courses (title, slug, description, is_published, enrollment_mode, created_by) VALUES (?, ?, \'Descrizione.\', ?, ?, ?)")'
        + '       ->execute([$t, "prova-bozze-" . bin2hex(random_bytes(4)), $pub, $modo, $admin]);'
        + '   return (int) $pdo->lastInsertId(); };'
        + ' $bozza = $nuovo("[prova-bozze] corso in bozza", 0, "closed");'
        + ' $modulo = App\\Models\\ModuleModel::create($bozza, "Modulo");'
        + ' $lezione = App\\Models\\LessonModel::create($modulo, "Lezione", "<p>Testo.</p>", "none", null, 0);'
        + ' $vuoto = $nuovo("[prova-bozze] pubblicato senza iscritti", 1, "closed");'
        + ' $richiesta = $nuovo("[prova-bozze] su richiesta", 1, "request");'
        + ' $pdo->prepare("INSERT INTO enrollment_requests (user_id, course_id, status, requested_at) VALUES (?, ?, \'pending\', NOW())")->execute([(int) $argv[1], $richiesta]);'
        + ' $req = (int) $pdo->lastInsertId();'
        + ' $h = password_hash($argv[3], PASSWORD_DEFAULT);'
        + ' $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at, name_display) VALUES (?, ?, \'Nuovo\', \'Bozze\', \'studente\', 1, NOW(), NOW(), \'full\')")->execute([$argv[2], $h]);'
        + ' $studenteNuovo = (int) $pdo->lastInsertId();'
        + ' echo json_encode(compact("bozza", "modulo", "lezione", "vuoto", "richiesta", "req", "studenteNuovo"));',
    A.studente, NUOVO, PASSWORD);
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
    return page;
}

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

/** L'avviso della pagina di destinazione (la `fetch` non segue il rimando). */
async function avviso(page, url) {
    await page.goto(BASE + url);
    return page.evaluate(() => ((document.querySelector('.alert-error, .alert-success') || {}).textContent || '').trim());
}

/** Accende o spegne «Pubblicato» dal modulo vero e preme il suo «Salva». */
async function pubblica(page, corso, acceso) {
    await page.goto(BASE + '/admin/courses/' + corso + '/edit');
    acceso ? await page.check('input[name="is_published"]') : await page.uncheck('input[name="is_published"]');
    await Promise.all([
        page.waitForNavigation(),
        page.locator('input[name="is_published"]').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click(),
    ]);
    return page.evaluate(() => ((document.querySelector('.alert-error, .alert-success') || {}).textContent || '').trim());
}

async function vedeIlCorso(page, corso, titolo) {
    await page.goto(BASE + '/');
    const elenco = (await page.content()).includes(titolo);
    const r = await page.goto(BASE + '/courses/' + corso);
    return { elenco, pagina: r ? r.status() : 0 };
}

(async () => {
    console.log('Semina dei due mondi e dei corsi della suite…');
    const { A } = semina();
    const C = prepara(A);
    const membri = json('$s = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = ?"); $s->execute([(int) $argv[1]]); echo json_encode(array_map("intval", $s->fetchAll(PDO::FETCH_COLUMN)));', A.gruppo);
    const browser = await chromium.launch();

    try {
        const admin = await entra(browser, 'admin@test.it');
        check('il gruppo A ha dei membri, fra cui lo studente di A', membri.length > 0 && membri.includes(A.studente), [JSON.stringify(membri)]);

        // --- 2. la bozza si assegna al gruppo senza iscrivere nessuno -----------
        await posta(admin, '/admin/groups/' + A.gruppo + '/courses', [['course_id', String(C.bozza)]], '/admin/groups/' + A.gruppo + '/edit');
        check('la bozza si assegna al gruppo', conta('SELECT COUNT(*) FROM group_course_access WHERE group_id = ? AND course_id = ?', A.gruppo, C.bozza) === 1);
        check('… e non iscrive nessuno', iscritti(C.bozza) === 0, [String(iscritti(C.bozza))]);
        let testo = await avviso(admin, '/admin/groups/' + A.gruppo + '/edit');
        check('… e lo dice', testo === 'Corso assegnato. È in bozza: i membri saranno iscritti quando viene pubblicato.', [testo]);
        check('nella scheda del gruppo la frase sulle bozze',
            (await admin.content()).includes('Un corso in bozza li iscrive quando viene pubblicato.'));

        // --- 1. nessuna iscrizione a una bozza ---------------------------------
        await posta(admin, '/admin/courses/' + C.bozza + '/enrollments', [['user_id', String(C.studenteNuovo)]], '/admin/courses/' + C.bozza + '/edit');
        check('l\'iscrizione a mano a una bozza si rifiuta', !iscritto(C.bozza, C.studenteNuovo));
        testo = await avviso(admin, '/admin/courses/' + C.bozza + '/edit');
        check('… con la frase', testo === 'Il corso è in bozza: gli studenti si iscrivono quando viene pubblicato.', [testo]);
        const scheda = await admin.evaluate(() => {
            const s = [...document.querySelectorAll('section.card')].find((x) => (x.querySelector('h2') || {}).textContent === 'Iscritti');
            return s ? { form: !!s.querySelector('form[action$="/enrollments"]'), testo: s.textContent.replace(/\s+/g, ' ') } : null;
        });
        check('nella pagina della bozza «Iscritti» non ha «Iscrivi», e lo dice',
            scheda !== null && !scheda.form && scheda.testo.includes('Il corso è in bozza: gli studenti si iscrivono quando viene pubblicato.'), [JSON.stringify(scheda)]);
        await posta(admin, '/admin/courses/' + C.vuoto + '/enrollments', [['user_id', String(C.studenteNuovo)]], '/admin/courses/' + C.vuoto + '/edit');
        check('controprova: a un corso pubblicato l\'iscrizione a mano riesce', iscritto(C.vuoto, C.studenteNuovo));
        sql('DELETE FROM enrollments WHERE course_id = ?', C.vuoto);

        await posta(admin, '/admin/groups/' + A.gruppo + '/members', [['user_id', String(C.studenteNuovo)]], '/admin/groups/' + A.gruppo + '/edit');
        check('entrando nel gruppo, lo studente nuovo non e\' iscritto alla bozza del gruppo', !iscritto(C.bozza, C.studenteNuovo));
        check('controprova: e\' iscritto al corso pubblicato del gruppo', iscritto(A.corso, C.studenteNuovo));

        php('App\\Models\\EnrollmentModel::enrollMany([(int) $argv[1]], [(int) $argv[2]], true);', A.studente, C.bozza);
        check('la funzione che iscrive salta le bozze, qualunque strada la chiami', !iscritto(C.bozza, A.studente));

        sql('UPDATE courses SET is_published = 0 WHERE id = ?', C.richiesta);
        await posta(admin, '/admin/requests/' + C.req, [['decision', 'approve']], '/admin/courses/' + C.richiesta + '/edit');
        check('una richiesta su un corso tornato in bozza non si approva',
            !iscritto(C.richiesta, A.studente) && conta('SELECT COUNT(*) FROM enrollment_requests WHERE id = ? AND status = \'pending\'', C.req) === 1);
        testo = await avviso(admin, '/admin/courses/' + C.richiesta + '/edit');
        check('… con la frase', testo === 'Il corso è in bozza: la richiesta si approva quando viene pubblicato.', [testo]);
        sql('UPDATE courses SET is_published = 1 WHERE id = ?', C.richiesta);
        await posta(admin, '/admin/requests/' + C.req, [['decision', 'approve']], '/admin/courses/' + C.richiesta + '/edit');
        check('controprova: pubblicato, la stessa richiesta si approva e iscrive', iscritto(C.richiesta, A.studente));

        // --- lo studente del gruppo non vede la bozza ----------------------------
        const studente = await entra(browser, 'stud@test.it');
        let vista = await vedeIlCorso(studente, C.bozza, '[prova-bozze] corso in bozza');
        check('lo studente del gruppo non ha la bozza in «I miei corsi», e la pagina non si apre',
            !vista.elenco && vista.pagina >= 400, [JSON.stringify(vista)]);
        let r = await studente.goto(BASE + '/lessons/' + C.lezione);
        check('… ne\' la lezione', r.status() >= 400, [String(r.status())]);

        // --- gli incontri del gruppo su un modulo della bozza ---------------------
        const incontro = Number(php('$pdo->prepare("INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, created_by) VALUES (?, ?, \'[prova-bozze] incontro\', NOW() - INTERVAL 3 DAY, NOW() + INTERVAL 1 DAY, ?)")'
            + '->execute([(int) $argv[1], (int) $argv[2], (int) $argv[3]]); echo (int) $pdo->lastInsertId();', C.modulo, A.gruppo, A.tutor).trim());
        const attesiIncontro = () => php('echo count(App\\Models\\LiveSessionModel::participants((int) $argv[1]));', incontro).trim();
        // Cominciato tre giorni fa e non ancora finito: e' il «prossimo» del
        // gruppo, prima degli incontri della semina.
        const vedeIncontro = async (page) => {
            const out = {};
            for (const [n, u] of [['live', '/live'], ['agenda', '/agenda'], ['gruppo', '/gruppi/' + A.gruppo]]) {
                await page.goto(BASE + u);
                out[n] = (await page.content()).includes('[prova-bozze] incontro');
            }
            const r = await page.goto(BASE + '/live/' + incontro);
            out.pagina = r ? r.status() : 0;
            return out;
        };
        let inc = await vedeIncontro(studente);
        check('l\'incontro del gruppo su un modulo della bozza: lo studente del gruppo non lo vede (Sessioni live, Agenda, gruppo) e non lo apre',
            !inc.live && !inc.agenda && !inc.gruppo && inc.pagina >= 400, [JSON.stringify(inc)]);
        check('… e non e\' fra gli attesi (inviti, promemoria, presenze)', attesiIncontro() === '0', [attesiIncontro()]);
        const tutor = await entra(browser, 'tutor1@test.it');
        inc = await vedeIncontro(tutor);
        check('il tutor del gruppo lo vede: Sessioni live, pagina del gruppo, e lo apre', inc.live && inc.gruppo && inc.pagina === 200, [JSON.stringify(inc)]);

        // --- 3. la pubblicazione iscrive i membri ----------------------------------
        testo = await pubblica(admin, C.bozza, true);
        const attesi = new Set([...membri, C.studenteNuovo]).size;
        check('pubblicando, sono iscritti i membri del gruppo', pubblicato(C.bozza) && iscritti(C.bozza) === attesi, [String(iscritti(C.bozza)), String(attesi)]);
        check('… e lo dice', testo === 'Corso pubblicato: iscritti ' + attesi + ' membri dei gruppi a cui è assegnato.', [testo]);
        check('… con la conferma in coda',
            conta('SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? AND e.confirmation_pending = 1 AND u.invite_pending = 0', C.bozza)
            === conta('SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? AND u.invite_pending = 0', C.bozza));
        vista = await vedeIlCorso(studente, C.bozza, '[prova-bozze] corso in bozza');
        check('controprova: pubblicato, lo studente lo vede e lo apre', vista.elenco && vista.pagina === 200, [JSON.stringify(vista)]);
        r = await studente.goto(BASE + '/lessons/' + C.lezione);
        check('… e apre la lezione', r.status() === 200, [String(r.status())]);
        inc = await vedeIncontro(studente);
        check('controprova: pubblicato, lo studente vede l\'incontro del gruppo ovunque e lo apre',
            inc.live && inc.agenda && inc.gruppo && inc.pagina === 200, [JSON.stringify(inc)]);
        check('… ed e\' fra gli attesi', Number(attesiIncontro()) === membri.length + 1, [attesiIncontro(), String(membri.length + 1)]);

        // --- 4. con iscritti non torna in bozza ---------------------------------------
        testo = await pubblica(admin, C.bozza, false);
        check('un corso con iscritti non torna in bozza, e lo dice',
            pubblicato(C.bozza) && testo === 'Il corso ha ' + attesi + ' iscritti: non può tornare in bozza.', [testo]);
        testo = await pubblica(admin, C.vuoto, false);
        check('controprova: un corso senza iscritti torna in bozza', !pubblicato(C.vuoto), [testo]);
        testo = await pubblica(admin, C.vuoto, true);
        check('… e ripubblicato senza gruppi resta senza iscritti, con «Corso aggiornato.»',
            pubblicato(C.vuoto) && iscritti(C.vuoto) === 0 && testo === 'Corso aggiornato.', [testo]);
        sql('INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)', C.studenteNuovo, C.vuoto);
        testo = await pubblica(admin, C.vuoto, false);
        check('con 1 iscritto, al singolare', pubblicato(C.vuoto) && testo === 'Il corso ha 1 iscritto: non può tornare in bozza.', [testo]);
    } catch (e) {
        check('la suite e\' arrivata in fondo', false, [String(e && e.stack || e)]);
    } finally {
        await browser.close();
        pulisci();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
