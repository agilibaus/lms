/**
 * Lo staff non e' mai iscritto a un corso ne' membro di un gruppo (13/11,
 * Alessandro).
 *
 *   - «Esplora corsi» e' solo degli studenti: lo staff, supervisore
 *     compreso, non ha la voce nel menu, la pagina lo rimanda a «Corsi», e un
 *     invio fatto a mano non iscrive e non chiede;
 *   - nessuna strada iscrive chi non e' studente: «Iscrivi» dalla scheda del
 *     corso, l'approvazione di una richiesta fatta da studente;
 *   - nessuna strada lo mette in un gruppo: la scheda del gruppo e la sezione
 *     «Gruppi» di «Modifica utente», che per lo staff non c'e';
 *   - uno studente che diventa staff esce dai suoi gruppi e tiene le
 *     iscrizioni; fra gli iscritti (scheda del corso e Report per corso) lo
 *     dice «Ora è assistente: iscrizione di quando era studente.», e il
 *     certificato non gli si emette piu', ne' dal pulsante ne' a mano;
 *   - i suoi video non lasciano fruizione.
 *
 * Ogni rifiuto ha la controprova: la stessa azione per uno studente.
 * Le azioni si giudicano dal database.
 *
 * Esecuzione:  node tests/staff_studenti.js   (server di sviluppo acceso)
 *
 * Crea tre studenti «[prova-staff]» e li toglie alla fine; risemina, per
 * lasciare il database come lo vogliono le altre suite.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const FRASE_EX = 'Ora è assistente: iscrizione di quando era studente.';
const PROVA = ['prova-staff-1@test.it', 'prova-staff-2@test.it', 'prova-staff-3@test.it'];

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

const conta = (sql, ...a) => Number(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo (int) $s->fetchColumn();', sql, ...a).trim());
const idUtente = (email) => conta('SELECT id FROM users WHERE email = ?', email);

function semina() {
    return JSON.parse(execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php')], { encoding: 'utf8', cwd: RADICE }));
}

function pulisci() {
    php('$s = $pdo->prepare("DELETE FROM users WHERE email = ?"); foreach (array_slice($argv, 1) as $e) { $s->execute([$e]); }', ...PROVA);
}

/** Uno studente di prova, attivo, con la password delle prove. */
function studente(email, nome) {
    php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at, name_display)'
        + ' VALUES (?, ?, ?, \'Prova\', \'studente\', 1, NOW(), NOW(), \'full\')")->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT), $argv[3]]);', email, PASSWORD, nome);
    return idUtente(email);
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

/** L'avviso rosso della pagina, dopo un rifiuto che rimanda li'. */
async function avviso(page, url) {
    await page.goto(BASE + url);
    return page.evaluate(() => [...document.querySelectorAll('.alert-error, .flash-error, .alert-danger')].map((a) => a.textContent.trim()).join(' | '));
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

/** Dove porta «/catalogo» a chi e' entrato, e che cosa c'e'. */
async function catalogo(page) {
    await page.goto(BASE + '/');
    const menu = await page.evaluate(() => [...document.querySelectorAll('.sidebar-nav a')].some((a) => a.textContent.trim() === 'Esplora corsi'));
    const r = await page.goto(BASE + '/catalogo');
    return {
        menu,
        stato: r ? r.status() : 0,
        dove: new URL(page.url()).pathname,
        ...(await page.evaluate(() => ({
            titolo: (document.querySelector('h1') || {}).textContent || '',
            schede: document.querySelectorAll('.catalog-card').length,
            moduli: document.querySelectorAll('form[action*="/iscrizione"]').length,
            richieste: document.querySelectorAll('.catalog-card textarea').length,
        }))),
    };
}

(async () => {
    console.log('Semina dei due mondi…');
    pulisci();
    const seme = semina();
    const A = seme.A;
    const B = seme.B;
    const C = seme.C;
    const browser = await chromium.launch();

    try {
        // --- 1. «Esplora corsi» per lo staff ----------------------------------
        const corsoAperto = C.corso; // aperto, senza gruppi: nessuno dello staff vi e' iscritto
        const corsoRichiesta = B.corso; // su richiesta
        for (const [ruolo, email] of [['Amministratore', 'admin@test.it'], ['tutor', 'tutor1@test.it'], ['assistente', 'assist@test.it'], ['supervisore', 'supervisore-staff@test.it']]) {
            if (ruolo === 'supervisore') {
                php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Supervisore\', \'Staff\', \'supervisore\', 1, NOW(), NOW())")->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT)]);', email, PASSWORD);
            }
            const id = idUtente(email);
            const { ctx, page } = await entra(browser, email);
            const v = await catalogo(page);
            check(ruolo + ': niente «Esplora corsi» nel menu', !v.menu, [JSON.stringify(v)]);
            check(ruolo + ': «/catalogo» rimanda a «Corsi», senza schede del catalogo',
                v.stato === 200 && v.dove === '/' && v.titolo.trim() === 'Corsi' && v.schede === 0, [JSON.stringify(v)]);

            const e0 = conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', id);
            const r0 = conta('SELECT COUNT(*) FROM enrollment_requests WHERE user_id = ?', id);
            // Il token da una pagina che lo staff apre: il catalogo lo rimanda via.
            await posta(page, '/catalogo/' + corsoAperto + '/iscrizione', [], '/profilo');
            await posta(page, '/catalogo/' + corsoRichiesta + '/iscrizione', [['message', 'Una prova']], '/profilo');
            check(ruolo + ': un invio a mano dal catalogo non iscrive e non chiede',
                conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', id) === e0
                    && conta('SELECT COUNT(*) FROM enrollment_requests WHERE user_id = ?', id) === r0);
            await ctx.close();
            if (ruolo === 'supervisore') {
                php('$pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', email);
            }
        }

        // Controprova: lo studente vede il catalogo di prima e si iscrive.
        const s1 = studente(PROVA[0], 'Uno');
        {
            const { ctx, page } = await entra(browser, PROVA[0]);
            const v = await catalogo(page);
            check('controprova: lo studente ha «Esplora corsi» nel menu, e la pagina con «Iscriviti» e il modulo della richiesta',
                v.stato === 200 && v.menu && v.dove === '/catalogo' && v.titolo.trim() === 'Esplora corsi' && v.moduli > 0 && v.richieste > 0, [JSON.stringify(v)]);
            await posta(page, '/catalogo/' + corsoAperto + '/iscrizione', [], '/catalogo');
            check('controprova: lo studente si iscrive dal catalogo',
                conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?', s1, corsoAperto) === 1);
            await ctx.close();
        }

        // --- 2. l'Amministratore non iscrive e non mette in un gruppo lo staff ---
        const admin = await entra(browser, 'admin@test.it');
        const tutor2 = idUtente('tutor2@test.it');
        const s2 = studente(PROVA[1], 'Due');

        // La regola nel modello, che vale per ogni strada (anche Stripe, la
        // pubblicazione di una bozza, l'importazione, un corso dato a un gruppo).
        php('App\\Models\\EnrollmentModel::enroll((int) $argv[1], (int) $argv[2], true); App\\Models\\GroupModel::addMember((int) $argv[3], (int) $argv[1]);', tutor2, A.corso, A.gruppo);
        check('la regola nel modello: un tutor non si iscrive e non entra in un gruppo, qualunque sia la strada',
            conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', tutor2) === 0 && conta('SELECT COUNT(*) FROM group_members WHERE user_id = ?', tutor2) === 0);

        await posta(admin.page, '/admin/courses/' + A.corso + '/enrollments', [['user_id', tutor2]], '/admin/courses/' + A.corso + '/edit');
        const avvisoIscrivi = await avviso(admin.page, '/admin/courses/' + A.corso + '/edit');
        check('«Iscrivi» dalla scheda del corso, a mano, non iscrive un tutor, e lo dice',
            conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?', tutor2, A.corso) === 0
                && avvisoIscrivi.includes('Ai corsi si iscrivono solo gli studenti.'), [avvisoIscrivi]);
        await posta(admin.page, '/admin/courses/' + A.corso + '/enrollments', [['user_id', s2]], '/admin/courses/' + A.corso + '/edit');
        check('controprova: «Iscrivi» iscrive uno studente',
            conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?', s2, A.corso) === 1);

        await posta(admin.page, '/admin/groups/' + A.gruppo + '/members', [['user_id', tutor2]], '/admin/groups/' + A.gruppo + '/edit');
        const avvisoGruppo = await avviso(admin.page, '/admin/groups/' + A.gruppo + '/edit');
        await posta(admin.page, '/admin/users/' + tutor2 + '/groups', [['group_id', A.gruppo]], '/admin/users/' + tutor2 + '/edit');
        const avvisoUtente = await avviso(admin.page, '/admin/users/' + tutor2 + '/edit');
        check('né dalla scheda del gruppo né da «Modifica utente» un tutor entra in un gruppo, e non viene iscritto',
            conta('SELECT COUNT(*) FROM group_members WHERE user_id = ?', tutor2) === 0
                && conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', tutor2) === 0);
        check('scheda del gruppo: l\'avviso «Nei gruppi entrano solo gli studenti.»', avvisoGruppo.includes('Nei gruppi entrano solo gli studenti.'), [avvisoGruppo]);
        check('«Modifica utente»: l\'avviso «Nei gruppi entrano solo gli studenti.»', avvisoUtente.includes('Nei gruppi entrano solo gli studenti.'), [avvisoUtente]);
        await admin.page.goto(BASE + '/admin/users/' + tutor2 + '/edit');
        const sezioneStaff = await admin.page.evaluate(() => [...document.querySelectorAll('section.card h2')].some((h) => h.textContent.trim() === 'Gruppi'));
        check('in «Modifica utente» di un tutor la sezione «Gruppi» non c\'è', !sezioneStaff);

        await posta(admin.page, '/admin/users/' + s2 + '/groups', [['group_id', A.gruppo]], '/admin/users/' + s2 + '/edit');
        await admin.page.goto(BASE + '/admin/users/' + s2 + '/edit');
        const sezioneStud = await admin.page.evaluate(() => [...document.querySelectorAll('section.card h2')].some((h) => h.textContent.trim() === 'Gruppi'));
        check('controprova: uno studente entra nel gruppo da «Modifica utente», che per lui ha la sezione',
            conta('SELECT COUNT(*) FROM group_members WHERE user_id = ? AND group_id = ?', s2, A.gruppo) === 1 && sezioneStud);

        // --- 3. lo studente che diventa staff -------------------------------
        // s2 e' nel gruppo di A e iscritto ad A; in piu' a un corso con un
        // video (per la fruizione) e con una richiesta in attesa su B.
        const video = JSON.parse(php('$r = $pdo->query("SELECT l.id, m.course_id FROM lessons l JOIN modules m ON m.id = l.module_id JOIN courses c ON c.id = m.course_id'
            + ' WHERE l.video_provider <> \'none\' AND c.is_published = 1 ORDER BY l.id LIMIT 1")->fetch(PDO::FETCH_ASSOC); echo json_encode($r ?: null);'));
        if (video !== null) {
            php('$pdo->prepare("INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)")->execute([$argv[1], $argv[2]]);', s2, video.course_id);
        }
        php('$pdo->prepare("INSERT INTO enrollment_requests (user_id, course_id, status) VALUES (?, ?, \'pending\')")->execute([$argv[1], $argv[2]]);', s2, B.corso);
        const richiesta = conta('SELECT id FROM enrollment_requests WHERE user_id = ? AND course_id = ?', s2, B.corso);

        // Prima, da studente: nel Report per corso ha «Emetti certificato».
        await admin.page.goto(BASE + '/reports/courses/' + A.corso);
        const riga = (email) => admin.page.evaluate((e) => {
            const r = [...document.querySelectorAll('tr')].find((t) => t.textContent.includes(e));
            return r ? { testo: r.textContent.replace(/\s+/g, ' ').trim(), emetti: !!r.querySelector('form[action="/certificates/issue"]') } : null;
        }, email);
        const daStudente = await riga(PROVA[1]);
        check('controprova: da studente, nel Report per corso, ha «Emetti certificato» e nessuna frase',
            daStudente !== null && daStudente.emetti && !daStudente.testo.includes('Ora è'), [JSON.stringify(daStudente)]);

        const iscrizioniPrima = conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', s2);
        await posta(admin.page, '/admin/users/' + s2, [['first_name', 'Due'], ['last_name', 'Prova'], ['email', PROVA[1]], ['role', 'assistente'], ['is_active', '1']], '/admin/users/' + s2 + '/edit');
        check('il cambio di ruolo è salvato', conta('SELECT COUNT(*) FROM users WHERE id = ? AND role = \'assistente\'', s2) === 1);
        check('diventato assistente, esce dai gruppi e tiene le iscrizioni',
            conta('SELECT COUNT(*) FROM group_members WHERE user_id = ?', s2) === 0
                && conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', s2) === iscrizioniPrima, [String(iscrizioniPrima)]);

        await admin.page.goto(BASE + '/admin/courses/' + A.corso + '/edit');
        const nellaScheda = await admin.page.evaluate((e) => {
            const r = [...document.querySelectorAll('tr')].find((t) => t.textContent.includes(e));
            return r ? r.querySelector('td').textContent.replace(/\s+/g, ' ').trim() : null;
        }, PROVA[1]);
        check('scheda del corso › Iscritti: sotto il nome «' + FRASE_EX + '»', nellaScheda !== null && nellaScheda.endsWith(FRASE_EX), [String(nellaScheda)]);

        await admin.page.goto(BASE + '/reports/courses/' + A.corso);
        const daStaff = await riga(PROVA[1]);
        check('Report per corso: la frase, e niente «Emetti certificato»',
            daStaff !== null && daStaff.testo.includes(FRASE_EX) && !daStaff.emetti, [JSON.stringify(daStaff)]);

        await posta(admin.page, '/certificates/issue', [['user_id', s2], ['course_id', A.corso], ['redirect_to', '/reports/courses/' + A.corso]], '/reports/courses/' + A.corso);
        const avvisoCert = await avviso(admin.page, '/reports/courses/' + A.corso);
        check('un certificato chiesto a mano per lui non si emette, e lo dice',
            conta('SELECT COUNT(*) FROM certificates WHERE user_id = ?', s2) === 0 && avvisoCert.includes('Il certificato si emette solo agli studenti.'), [avvisoCert]);
        await posta(admin.page, '/certificates/issue', [['user_id', s1], ['course_id', corsoAperto], ['redirect_to', '/reports/courses/' + corsoAperto]], '/reports/courses/' + corsoAperto);
        check('controprova: a uno studente il certificato si emette', conta('SELECT COUNT(*) FROM certificates WHERE user_id = ? AND course_id = ?', s1, corsoAperto) === 1);

        await posta(admin.page, '/admin/requests/' + richiesta, [['decision', 'approve']], '/admin/courses/' + B.corso + '/edit');
        const avvisoRichiesta = await avviso(admin.page, '/admin/courses/' + B.corso + '/edit');
        check('la sua richiesta fatta da studente non si approva, non lo iscrive, e lo dice',
            conta('SELECT COUNT(*) FROM enrollment_requests WHERE id = ? AND status = \'pending\'', richiesta) === 1
                && conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?', s2, B.corso) === 0
                && avvisoRichiesta.includes('non è più uno studente'), [avvisoRichiesta]);
        const s3 = studente(PROVA[2], 'Tre');
        php('$pdo->prepare("INSERT INTO enrollment_requests (user_id, course_id, status) VALUES (?, ?, \'pending\')")->execute([$argv[1], $argv[2]]);', s3, B.corso);
        const richiesta3 = conta('SELECT id FROM enrollment_requests WHERE user_id = ? AND course_id = ?', s3, B.corso);
        await posta(admin.page, '/admin/requests/' + richiesta3, [['decision', 'approve']], '/admin/courses/' + B.corso + '/edit');
        check('controprova: la richiesta di uno studente si approva e lo iscrive',
            conta('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?', s3, B.corso) === 1);
        await admin.ctx.close();

        // --- 4. la fruizione dei video --------------------------------------
        if (video === null) {
            check('c\'è una lezione con un video in un corso pubblicato, per provare la fruizione', false);
        } else {
            const fruizione = (id) => conta('SELECT COUNT(*) FROM lesson_video_progress WHERE user_id = ?', id)
                + conta('SELECT COUNT(*) FROM lesson_video_intervals WHERE user_id = ?', id);
            const ex = await entra(browser, PROVA[1]);
            await posta(ex.page, '/lessons/' + video.id + '/fruizione', [['posizione', '30'], ['durata', '120'], ['intervalli', '[[0,30]]']], '/profilo');
            check('diventato assistente, iscritto, guardare un video non lascia fruizione', fruizione(s2) === 0, [String(fruizione(s2))]);
            await ex.ctx.close();

            php('$pdo->prepare("INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)")->execute([$argv[1], $argv[2]]);', s3, video.course_id);
            const st = await entra(browser, PROVA[2]);
            await posta(st.page, '/lessons/' + video.id + '/fruizione', [['posizione', '30'], ['durata', '120'], ['intervalli', '[[0,30]]']], '/catalogo');
            check('controprova: uno studente iscritto lascia la fruizione', fruizione(s3) > 0, [String(fruizione(s3))]);
            await st.ctx.close();
        }
    } finally {
        await browser.close();
        pulisci();
        semina();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
