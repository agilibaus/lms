/**
 * I corsi a pagamento (06/11, Alessandro; disegno del 31/10), prima patch:
 * «A pagamento» come quarta modalita' d'iscrizione, con tutte le vie
 * gratuite chiuse. L'avviso di Stripe che iscrive arriva con la patch dopo,
 * e con lui le prove del pagamento.
 *
 *   - l'interruttore generale (Impostazioni › Pagamenti), spento all'inizio:
 *     spento, «A pagamento» non si sceglie; un corso gia' a pagamento la
 *     tiene;
 *   - la sceglie solo l'admin, con prezzo, link e identificativo controllati;
 *     il supervisore non la vede e non la cambia;
 *   - un corso assegnato a un gruppo, o con richieste in attesa, non diventa
 *     a pagamento; un corso a pagamento non si assegna a un gruppo;
 *   - nel catalogo non c'e', e dal catalogo non si entra;
 *   - l'admin lo iscrive a mano.
 *
 * Ogni rifiuto ha la sua controprova: la stessa azione dove e' permessa.
 *
 * Esecuzione:  node tests/pagamenti.js   (server di sviluppo acceso)
 *
 * Crea i corsi «[prova-pagamenti] …» e il supervisore supervisore-pagamenti@test.it,
 * e alla fine li toglie e rimette PAYMENTS_ENABLED com'era, anche se un
 * controllo fallisce. Usa la semina dei due mondi (`semina_permessi.php`).
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const SUPERVISORE = 'supervisore-pagamenti@test.it';
const LINK = 'https://buy.stripe.com/test_cNicN46Yfg8m6s1cVd6J200';

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
const sql = (query, ...a) => php('$pdo->prepare($argv[1])->execute(array_slice($argv, 2));', query, ...a);
const riga = (query, ...a) => json('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo json_encode($s->fetch() ?: null);', query, ...a);
const conta = (query, ...a) => Number(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo (int) $s->fetchColumn();', query, ...a).trim());
const corso = (id) => riga('SELECT enrollment_mode, price_cents, payment_link_url, payment_link_id FROM courses WHERE id = ?', id);
const interruttore = (v) => php('App\\Core\\Settings::set("PAYMENTS_ENABLED", $argv[1] === "" ? null : $argv[1]);', v);
const imposta = (k, v) => php('App\\Core\\Settings::set($argv[1], $argv[2] === "" ? null : $argv[2]);', k, v);

function semina() {
    return JSON.parse(execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php')], { encoding: 'utf8', cwd: RADICE }));
}

function pulisci() {
    php('$pdo->exec("DELETE FROM courses WHERE title LIKE \'[prova-pagamenti]%\'");'
        + ' $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', SUPERVISORE);
}

/** I corsi della suite: uno aperto senza gruppi, uno su richiesta con una richiesta, una bozza, e il supervisore. */
function prepara(A) {
    pulisci();
    return json('$admin = (int) $pdo->query("SELECT id FROM users WHERE email = \'admin@test.it\'")->fetchColumn();'
        + ' $nuovo = function (string $titolo, int $pub, string $modo) use ($pdo, $admin): int {'
        + '   $pdo->prepare("INSERT INTO courses (title, slug, description, is_published, enrollment_mode, created_by) VALUES (?, ?, \'\', ?, ?, ?)")'
        + '       ->execute([$titolo, "prova-pagamenti-" . bin2hex(random_bytes(4)), $pub, $modo, $admin]);'
        + '   return (int) $pdo->lastInsertId(); };'
        + ' $libero = $nuovo("[prova-pagamenti] corso libero", 1, "open");'
        + ' $altro = $nuovo("[prova-pagamenti] altro corso", 1, "open");'
        + ' $richiesta = $nuovo("[prova-pagamenti] corso su richiesta", 1, "request");'
        + ' $bozza = $nuovo("[prova-pagamenti] bozza", 0, "closed");'
        + ' $bozzaPagata = $nuovo("[prova-pagamenti] bozza a pagamento", 0, "paid");'
        + ' $pdo->prepare("UPDATE courses SET price_cents = 3000, payment_link_url = ?, payment_link_id = \'plink_bozza1\' WHERE id = ?")->execute([$argv[3], $bozzaPagata]);'
        + ' $pdo->prepare("INSERT INTO enrollment_requests (user_id, course_id, status, requested_at) VALUES (?, ?, \'pending\', NOW())")->execute([(int) $argv[1], $richiesta]);'
        + ' $h = password_hash($argv[4], PASSWORD_DEFAULT);'
        + ' $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Supervisore\', \'Pagamenti\', \'supervisore\', 1, NOW(), NOW())")->execute([$argv[2], $h]);'
        + ' echo json_encode(compact("libero", "altro", "richiesta", "bozza", "bozzaPagata", "admin"));',
    A.studente, SUPERVISORE, LINK, PASSWORD);
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

/** L'avviso rosso della pagina, dopo un rimando. */
async function errore(page) {
    return page.evaluate(() => ((document.querySelector('.alert-error') || {}).textContent || '').trim());
}

/**
 * Salva il modulo vero del corso: sceglie la modalita', scrive i tre campi
 * se ci sono, preme «Salva» del riquadro «Dati del corso».
 */
async function salvaCorso(page, id, modo, campi = {}) {
    await page.goto(BASE + '/admin/courses/' + id + '/edit');
    await page.selectOption('#enrollment_mode', modo);
    for (const [nome, valore] of Object.entries(campi)) {
        await page.fill('[name="' + nome + '"]', valore);
    }
    await Promise.all([
        page.waitForNavigation(),
        page.locator('#enrollment_mode').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click(),
    ]);
    return errore(page);
}

const VALIDI = { price: '49,00', payment_link_url: LINK, payment_link_id: 'plink_prova1' };

(async () => {
    console.log('Semina dei due mondi e dei corsi della suite…');
    const { A } = semina();
    const C = prepara(A);
    const prima = php('echo App\\Core\\Settings::stored("PAYMENTS_ENABLED") ?? "";').trim();
    const primaChiave = php('echo App\\Core\\Settings::stored("STRIPE_WEBHOOK_SECRET") ?? "";').trim();
    const primaProva = php('echo App\\Core\\Settings::stored("PAYMENTS_TEST_MODE") ?? "";').trim();
    const browser = await chromium.launch();

    try {
        const admin = (await entra(browser, 'admin@test.it')).page;
        const nomeGruppoA = riga('SELECT name FROM `groups` WHERE id = ?', A.gruppo).name;

        // --- senza corsi a pagamento, nei gruppi niente riga ------------------
        sql('UPDATE courses SET enrollment_mode = \'closed\' WHERE id = ?', C.bozzaPagata);
        const giaPagati = conta('SELECT COUNT(*) FROM courses WHERE enrollment_mode = \'paid\'');
        await admin.goto(BASE + '/admin/groups/' + A.gruppo + '/edit');
        if (giaPagati === 0) {
            check('senza corsi a pagamento, sotto «Assegna corso» la riga non c\'e\'',
                !(await admin.content()).includes('I corsi a pagamento non si assegnano ai gruppi'));
        } else {
            console.log('  --   nel database ci sono gia\' ' + giaPagati + ' corsi a pagamento: la prova senza riga si salta');
        }
        sql('UPDATE courses SET enrollment_mode = \'paid\' WHERE id = ?', C.bozzaPagata);

        // --- l'interruttore spento ------------------------------------------
        interruttore('');
        await admin.goto(BASE + '/admin/courses/' + C.libero + '/edit');
        let opzioni = await admin.$$eval('#enrollment_mode option', (o) => o.map((x) => x.value));
        check('spento: nel modulo del corso «A pagamento» non c\'e\'', !opzioni.includes('paid') && opzioni.length === 3, [opzioni.join(',')]);
        check('spento: i campi del pagamento non ci sono', (await admin.$('#campi-pagamento')) === null);
        await posta(admin, '/admin/courses/' + C.libero, [['title', '[prova-pagamenti] corso libero'], ['is_published', '1'],
            ['enrollment_mode', 'paid'], ['price', '49,00'], ['payment_link_url', LINK], ['payment_link_id', 'plink_prova1']], '/admin/courses/' + C.libero + '/edit');
        check('spento: una POST con «paid» lascia il corso com\'era', corso(C.libero).enrollment_mode === 'open', [JSON.stringify(corso(C.libero))]);

        // --- Impostazioni › Pagamenti -----------------------------------------
        await admin.goto(BASE + '/admin/settings');
        check('l\'indice delle impostazioni ha «Pagamenti»', await admin.$('a.settings-card[href="/admin/settings/pagamenti"]') !== null);
        await admin.goto(BASE + '/admin/settings/pagamenti');
        check('spento: la casella e\' spenta', !(await admin.isChecked('#pagamenti-attivi')));
        await admin.check('#pagamenti-attivi');
        await Promise.all([admin.waitForNavigation(), admin.click('form.form button[type="submit"]')]);
        check('accendendo la casella e salvando, PAYMENTS_ENABLED = 1', php('echo App\\Core\\Settings::stored("PAYMENTS_ENABLED");').trim() === '1');
        check('accesa: la casella e\' accesa', await admin.isChecked('#pagamenti-attivi'));
        await admin.uncheck('#pagamenti-attivi');
        await Promise.all([admin.waitForNavigation(), admin.click('form.form button[type="submit"]')]);
        check('spegnendola e salvando, PAYMENTS_ENABLED = 0', php('echo App\\Core\\Settings::stored("PAYMENTS_ENABLED");').trim() === '0');
        interruttore('1');

        // --- accesi: il modulo del corso --------------------------------------
        await admin.goto(BASE + '/admin/courses/' + C.libero + '/edit');
        opzioni = await admin.$$eval('#enrollment_mode option', (o) => o.map((x) => x.value));
        check('accesi: nel modulo c\'e\' «A pagamento»', opzioni.includes('paid'), [opzioni.join(',')]);
        check('su «Aperta» i campi del pagamento sono nascosti', await admin.$eval('#campi-pagamento', (e) => e.hidden));
        await admin.selectOption('#enrollment_mode', 'paid');
        check('scegliendo «A pagamento» compaiono', !(await admin.$eval('#campi-pagamento', (e) => e.hidden)));

        // --- i rifiuti dei tre campi -----------------------------------------
        const casi = [
            ['senza prezzo', { ...VALIDI, price: '' }, 'servono il prezzo, il link di pagamento e il suo identificativo'],
            ['senza identificativo', { ...VALIDI, payment_link_id: '' }, 'servono il prezzo, il link di pagamento e il suo identificativo'],
            ['un prezzo che non si legge', { ...VALIDI, price: 'quaranta' }, 'Il prezzo va scritto in euro'],
            ['un prezzo di zero', { ...VALIDI, price: '0' }, 'Il prezzo va scritto in euro'],
            ['un link in http://', { ...VALIDI, payment_link_url: 'http://buy.stripe.com/x' }, 'deve cominciare con https://'],
            ['un identificativo senza «plink_»', { ...VALIDI, payment_link_id: 'price_123' }, 'comincia con «plink_»'],
            ['l\'identificativo di un altro corso a pagamento', { ...VALIDI, payment_link_id: 'plink_bozza1' }, 'è già usato dal corso «[prova-pagamenti] bozza a pagamento»'],
        ];
        for (const [cosa, campi, frase] of casi) {
            const avviso = await salvaCorso(admin, C.libero, 'paid', campi);
            check('rifiutato con ' + cosa + ', e il corso resta «Aperta»',
                avviso.includes(frase) && corso(C.libero).enrollment_mode === 'open', [avviso, JSON.stringify(corso(C.libero))]);
        }
        const riproposto = await admin.evaluate(() => ({
            modo: document.querySelector('#enrollment_mode').value,
            link: document.querySelector('[name="payment_link_url"]').value,
            plink: document.querySelector('[name="payment_link_id"]').value,
        }));
        check('dopo un rifiuto il modulo torna con quello che era stato scritto',
            riproposto.modo === 'paid' && riproposto.link === LINK && riproposto.plink === 'plink_bozza1', [JSON.stringify(riproposto)]);

        // --- controprova: tutto in regola ------------------------------------
        let avviso = await salvaCorso(admin, C.libero, 'paid', VALIDI);
        let c = corso(C.libero);
        check('controprova: con i tre campi in regola il corso diventa a pagamento, 49,00 € = 4900 centesimi',
            avviso === '' && c.enrollment_mode === 'paid' && Number(c.price_cents) === 4900 && c.payment_link_url === LINK && c.payment_link_id === 'plink_prova1',
            [avviso, JSON.stringify(c)]);
        await admin.goto(BASE + '/admin/courses/' + C.libero + '/edit');
        check('riaperto, il modulo mostra «A pagamento» e «49,00»',
            (await admin.$eval('#enrollment_mode', (s) => s.value)) === 'paid' && (await admin.$eval('#price', (i) => i.value)) === '49,00');
        // A 320 px, con i tre campi aperti, la pagina non scorre di lato.
        await admin.setViewportSize({ width: 320, height: 800 });
        await admin.goto(BASE + '/admin/courses/' + C.libero + '/edit');
        const larghezza = await admin.evaluate(() => document.documentElement.scrollWidth);
        check('a 320 px il modulo con i campi del pagamento non allarga la pagina', larghezza <= 320, [larghezza + ' px']);
        await admin.setViewportSize({ width: 1280, height: 900 });

        // --- gli impedimenti: gruppo e richieste ------------------------------
        avviso = await salvaCorso(admin, A.corso, 'paid', { ...VALIDI, payment_link_id: 'plink_prova2' });
        check('un corso assegnato a un gruppo non diventa a pagamento, con il nome del gruppo',
            avviso === 'Il corso è assegnato al gruppo ' + nomeGruppoA + ': va rimosso dal gruppo prima di renderlo a pagamento.'
            || (avviso.startsWith('Il corso è assegnato ai gruppi ') && avviso.includes(nomeGruppoA) && avviso.endsWith('va rimosso dai gruppi prima di renderlo a pagamento.')),
            [avviso]);
        check('… e resta com\'era', corso(A.corso).enrollment_mode !== 'paid', [JSON.stringify(corso(A.corso))]);

        avviso = await salvaCorso(admin, C.richiesta, 'paid', { ...VALIDI, payment_link_id: 'plink_prova3' });
        check('un corso con una richiesta in attesa non diventa a pagamento',
            avviso === 'C’è 1 richiesta di iscrizione in attesa: va decisa prima di rendere il corso a pagamento.'
            && corso(C.richiesta).enrollment_mode === 'request', [avviso]);
        sql('UPDATE enrollment_requests SET status = \'rejected\', decided_at = NOW() WHERE course_id = ?', C.richiesta);
        avviso = await salvaCorso(admin, C.richiesta, 'paid', { ...VALIDI, payment_link_id: 'plink_prova3' });
        check('controprova: decisa la richiesta, diventa a pagamento', avviso === '' && corso(C.richiesta).enrollment_mode === 'paid', [avviso]);

        // --- i gruppi -----------------------------------------------------------
        await admin.goto(BASE + '/admin/groups/' + A.gruppo + '/edit');
        const assegnabili = await admin.$$eval('select[name="course_id"] option', (o) => o.map((x) => Number(x.value)));
        check('fra i corsi da assegnare a un gruppo quello a pagamento non c\'e\', l\'altro si',
            !assegnabili.includes(C.libero) && assegnabili.includes(C.altro), [assegnabili.join(',')]);
        check('sotto «Assegna corso» la riga sui corsi a pagamento',
            (await admin.content()).includes('I corsi a pagamento non si assegnano ai gruppi: non compaiono qui.'));
        await posta(admin, '/admin/groups/' + A.gruppo + '/courses', [['course_id', String(C.libero)]], '/admin/groups/' + A.gruppo + '/edit');
        check('una POST che assegna il corso a pagamento al gruppo non lo assegna e non iscrive nessuno',
            conta('SELECT COUNT(*) FROM group_course_access WHERE course_id = ?', C.libero) === 0
            && conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ?', C.libero) === 0);
        // L'avviso si legge dove porta il rimando (la `fetch` non lo segue).
        await admin.goto(BASE + '/admin/groups/' + A.gruppo + '/edit');
        const avvisoGruppo = await errore(admin);
        check('… e lo dice', avvisoGruppo === 'Un corso a pagamento non si assegna a un gruppo.', [avvisoGruppo]);
        await posta(admin, '/admin/groups/' + A.gruppo + '/courses', [['course_id', String(C.altro)]], '/admin/groups/' + A.gruppo + '/edit');
        check('controprova: il corso aperto si assegna, e iscrive i membri',
            conta('SELECT COUNT(*) FROM group_course_access WHERE course_id = ?', C.altro) === 1
            && conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ?', C.altro) > 0);

        // --- Gestione corsi -------------------------------------------------------
        await admin.goto(BASE + '/admin/courses');
        const etichetta = await admin.evaluate((t) => {
            const r = [...document.querySelectorAll('tr')].find((x) => x.textContent.includes(t));
            return r ? [...r.querySelectorAll('.badge')].map((b) => b.textContent.trim()) : [];
        }, '[prova-pagamenti] corso libero');
        check('Gestione corsi: «a pagamento»', etichetta.includes('a pagamento'), [etichetta.join(' | ')]);

        // --- il catalogo e lo studente ----------------------------------------------
        const studente = (await entra(browser, 'stud@test.it')).page;
        await studente.goto(BASE + '/catalogo');
        let catalogo = await studente.content();
        // Dal 06/11 (Stripe) il corso a pagamento c'e', ma senza «Iscriviti»:
        // dal catalogo si entra solo pagando.
        const gratis = await studente.evaluate((t) => {
            const c = [...document.querySelectorAll('.catalog-card')].find((x) => x.textContent.includes(t));
            return c ? !!c.querySelector('form[action$="/iscrizione"]') : null;
        }, '[prova-pagamenti] corso libero');
        check('il corso a pagamento e\' nel catalogo senza il modulo «Iscriviti»', gratis === false, [String(gratis)]);
        check('controprova: il corso aperto della suite si', catalogo.includes('[prova-pagamenti] altro corso') || conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND user_id = ?', C.altro, A.studente) === 1);
        await posta(studente, '/catalogo/' + C.libero + '/iscrizione', [], '/catalogo');
        check('dal catalogo una POST non iscrive al corso a pagamento',
            conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, A.studente) === 0);
        check('… ne\' crea una richiesta',
            conta('SELECT COUNT(*) FROM enrollment_requests WHERE course_id = ? AND user_id = ?', C.libero, A.studente) === 0);
        sql('UPDATE courses SET enrollment_mode = \'open\' WHERE id = ?', C.libero);
        await posta(studente, '/catalogo/' + C.libero + '/iscrizione', [], '/catalogo');
        check('controprova: tornato «Aperta», la stessa POST iscrive',
            conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, A.studente) === 1);
        sql('DELETE FROM enrollments WHERE course_id = ?', C.libero);
        sql('UPDATE courses SET enrollment_mode = \'paid\' WHERE id = ?', C.libero);

        // --- l'iscrizione a mano dell'admin -------------------------------------------
        await posta(admin, '/admin/courses/' + C.libero + '/enrollments', [['user_id', String(A.studente)]], '/admin/courses/' + C.libero + '/edit');
        check('l\'admin iscrive a mano a un corso a pagamento',
            conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, A.studente) === 1);

        // --- spento, il corso gia' a pagamento resta tale --------------------------------
        interruttore('0');
        await admin.goto(BASE + '/admin/courses/' + C.libero + '/edit');
        opzioni = await admin.$$eval('#enrollment_mode option', (o) => o.map((x) => x.value));
        check('spento: un corso gia\' a pagamento ha ancora «A pagamento», scelta',
            opzioni.includes('paid') && (await admin.$eval('#enrollment_mode', (s) => s.value)) === 'paid', [opzioni.join(',')]);
        avviso = await salvaCorso(admin, C.libero, 'paid', {});
        check('spento: salvandolo resta a pagamento, con il suo prezzo', avviso === '' && corso(C.libero).enrollment_mode === 'paid'
            && Number(corso(C.libero).price_cents) === 4900, [avviso, JSON.stringify(corso(C.libero))]);
        await admin.goto(BASE + '/admin/courses/' + C.altro + '/edit');
        opzioni = await admin.$$eval('#enrollment_mode option', (o) => o.map((x) => x.value));
        check('spento: un altro corso non ha «A pagamento»', !opzioni.includes('paid'), [opzioni.join(',')]);
        await admin.goto(BASE + '/admin/groups/' + A.gruppo + '/edit');
        check('spento: la riga dei gruppi c\'e\' ancora, perche\' il corso a pagamento c\'e\'',
            (await admin.content()).includes('I corsi a pagamento non si assegnano ai gruppi'));
        interruttore('1');

        // --- il supervisore -------------------------------------------------------------
        const sup = (await entra(browser, SUPERVISORE)).page;
        await sup.goto(BASE + '/admin/courses/' + C.bozza + '/edit');
        opzioni = await sup.$$eval('#enrollment_mode option', (o) => o.map((x) => x.value));
        check('supervisore, interruttore acceso: nel modulo non c\'e\' «A pagamento»', !opzioni.includes('paid') && opzioni.length === 3, [opzioni.join(',')]);
        await posta(sup, '/admin/courses/' + C.bozza, [['title', '[prova-pagamenti] bozza'], ['enrollment_mode', 'paid'],
            ['price', '10'], ['payment_link_url', LINK], ['payment_link_id', 'plink_sup1']], '/admin/courses/' + C.bozza + '/edit');
        c = corso(C.bozza);
        check('supervisore: una POST con «paid» lascia la bozza «Chiusa», senza prezzo', c.enrollment_mode === 'closed' && c.price_cents === null, [JSON.stringify(c)]);
        await posta(sup, '/admin/courses/' + C.bozza, [['title', '[prova-pagamenti] bozza'], ['enrollment_mode', 'open']], '/admin/courses/' + C.bozza + '/edit');
        check('controprova: il supervisore cambia le altre modalita\'', corso(C.bozza).enrollment_mode === 'open');

        await sup.goto(BASE + '/admin/courses/' + C.bozzaPagata + '/edit');
        const vista = await sup.evaluate(() => ({
            spenta: document.querySelector('#enrollment_mode').disabled,
            campi: document.querySelector('#campi-pagamento') !== null,
            frase: ((document.getElementById('enrollment-mode-hint') || {}).textContent || '').trim(),
        }));
        check('supervisore, bozza a pagamento: la modalita\' si vede spenta, senza prezzo e link, con la frase',
            vista.spenta && !vista.campi && vista.frase === 'La modalità «A pagamento», con prezzo e link, la cambia solo l’Amministratore.', [JSON.stringify(vista)]);
        await posta(sup, '/admin/courses/' + C.bozzaPagata, [['title', '[prova-pagamenti] bozza a pagamento'], ['enrollment_mode', 'open'],
            ['price', '1'], ['payment_link_url', 'https://esempio.it/x'], ['payment_link_id', 'plink_sup2']], '/admin/courses/' + C.bozzaPagata + '/edit');
        c = corso(C.bozzaPagata);
        check('supervisore: salvando la bozza a pagamento, modalita\', prezzo e link restano',
            c.enrollment_mode === 'paid' && Number(c.price_cents) === 3000 && c.payment_link_url === LINK && c.payment_link_id === 'plink_bozza1', [JSON.stringify(c)]);
        await posta(sup, '/admin/courses', [['title', '[prova-pagamenti] nuovo del supervisore'], ['enrollment_mode', 'paid'],
            ['price', '10'], ['payment_link_url', LINK], ['payment_link_id', 'plink_sup3']], '/admin/courses/create');
        const nuovo = riga('SELECT enrollment_mode, price_cents FROM courses WHERE title = ?', '[prova-pagamenti] nuovo del supervisore');
        check('supervisore: un corso nuovo con «paid» nasce «Chiuso», senza prezzo',
            nuovo !== null && nuovo.enrollment_mode === 'closed' && nuovo.price_cents === null, [JSON.stringify(nuovo)]);
        check('supervisore: Impostazioni › Pagamenti negata', (await esitoGet(sup, '/admin/settings/pagamenti')) === 'negato');

        // --- Stripe: gli avvisi (06/11) --------------------------------------------------------
        const crypto = require('node:crypto');
        const CHIAVE = 'whsec_provaPistacchio123';
        imposta('STRIPE_WEBHOOK_SECRET', CHIAVE);
        imposta('PAYMENTS_TEST_MODE', '1');
        interruttore('1');
        const altroS = json('$s = $pdo->prepare("SELECT id, email FROM users WHERE email = ?"); $s->execute(["strano@test.it"]); echo json_encode($s->fetch());');
        let numero = 0;
        const sessione = (campi = {}) => ({
            id: 'cs_test_' + (++numero) + '_' + Date.now(), object: 'checkout.session', livemode: false, payment_status: 'paid',
            payment_link: 'plink_prova1', client_reference_id: String(altroS.id), currency: 'eur',
            amount_subtotal: 4900, amount_total: 4900, customer_details: { email: altroS.email }, ...campi,
        });
        const evento = (s, tipo = 'checkout.session.completed', id = null) => ({ id: id || 'evt_test_' + (++numero) + '_' + Date.now(), type: tipo, data: { object: s } });
        const manda = async (ev, { chiave = CHIAVE, tempo = Math.floor(Date.now() / 1000), firma = null } = {}) => {
            const corpo = JSON.stringify(ev);
            const intestazioni = { 'Content-Type': 'application/json' };
            if (firma !== false) {
                intestazioni['Stripe-Signature'] = firma || ('t=' + tempo + ',v1=' + crypto.createHmac('sha256', chiave).update(tempo + '.' + corpo).digest('hex'));
            }
            const r = await fetch(BASE + '/stripe/avvisi', { method: 'POST', headers: intestazioni, body: corpo });
            return { stato: r.status, testo: await r.text() };
        };
        const rigaP = (s) => json('$q = $pdo->prepare("SELECT esito, motivo, user_id, course_id FROM payments WHERE stripe_session_id = ?"); $q->execute([$argv[1]]); echo json_encode($q->fetch() ?: null);', s.id);
        const iscrittoAltro = () => conta('SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, altroS.id) === 1;
        const mailAdmin = () => conta('SELECT COUNT(*) FROM email_queue WHERE subject LIKE ?', 'Pagamento da controllare%');
        sql('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, altroS.id);
        sql('DELETE FROM email_queue WHERE subject LIKE ?', 'Pagamento da controllare%');

        let s1 = sessione();
        let e = await manda(evento(s1), { firma: false });
        check('avviso senza firma: 400, e nessuno iscritto', e.stato === 400 && !iscrittoAltro() && rigaP(s1) === null, [JSON.stringify(e)]);
        e = await manda(evento(s1), { chiave: 'whsec_sbagliata' });
        check('avviso con la firma di un\'altra chiave: 400', e.stato === 400 && !iscrittoAltro(), [JSON.stringify(e)]);
        e = await manda(evento(s1), { tempo: Math.floor(Date.now() / 1000) - 600 });
        check('avviso firmato ma di dieci minuti fa: 400', e.stato === 400 && !iscrittoAltro(), [JSON.stringify(e)]);
        e = await manda({ ...evento(s1), type: 'customer.created' });
        check('un altro tipo di avviso: 200, ignorato, niente registrato', e.stato === 200 && rigaP(s1) === null, [JSON.stringify(e)]);

        const ev1 = evento(s1);
        e = await manda(ev1);
        check('avviso firmato e in regola: lo studente e\' iscritto, con la conferma in coda',
            e.stato === 200 && iscrittoAltro()
            && conta('SELECT confirmation_pending FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, altroS.id) === 1, [JSON.stringify(e)]);
        check('… e il pagamento e\' registrato come iscrizione completata', JSON.stringify(rigaP(s1)) === JSON.stringify({ esito: 'iscritto', motivo: null, user_id: altroS.id, course_id: C.libero }), [JSON.stringify(rigaP(s1))]);
        e = await manda(ev1);
        check('lo stesso avviso ripetuto si conta una volta', e.stato === 200 && conta('SELECT COUNT(*) FROM payments WHERE stripe_session_id = ?', s1.id) === 1, [JSON.stringify(e)]);
        e = await manda(evento(s1, 'checkout.session.async_payment_succeeded'));
        check('… e un secondo avviso dello stesso pagamento anche', conta('SELECT COUNT(*) FROM payments WHERE stripe_session_id = ?', s1.id) === 1);

        const rossi = [
            ['lo studente non riconosciuto', { client_reference_id: null }, 'Studente non riconosciuto'],
            ['un corso non riconosciuto', { payment_link: 'plink_nessuno' }, 'Corso non riconosciuto'],
            ['un prezzo diverso prima dello sconto', { amount_subtotal: 3000, amount_total: 3000 }, 'Importo diverso dal prezzo del corso: 30,00 € invece di 49,00 €.'],
            ['un pagamento vero con la modalita\' di prova accesa', { livemode: true }, 'Pagamento vero con la modalità di prova accesa.'],
            ['l\'iscrizione che c\'era gia\'', {}, 'L’iscrizione c’era già: il pagamento va rimborsato su Stripe.'],
        ];
        for (const [cosa, campi, frase] of rossi) {
            const prima1 = mailAdmin();
            const s = sessione(campi);
            e = await manda(evento(s));
            const r = rigaP(s);
            check('in rosso con ' + cosa + ', e l\'Amministratore riceve l\'email',
                e.stato === 200 && r !== null && r.esito === 'non_riuscito' && String(r.motivo).includes(frase) && mailAdmin() > prima1, [JSON.stringify(e), JSON.stringify(r)]);
        }
        sql('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, altroS.id);

        imposta('PAYMENTS_TEST_MODE', '0');
        let s2 = sessione();
        e = await manda(evento(s2));
        check('un pagamento di prova con la modalita\' di prova spenta: in rosso, nessuno iscritto',
            rigaP(s2) !== null && rigaP(s2).esito === 'non_riuscito' && !iscrittoAltro(), [JSON.stringify(rigaP(s2))]);
        s2 = sessione({ livemode: true });
        e = await manda(evento(s2));
        check('controprova: un pagamento vero con la modalita\' di prova spenta iscrive', rigaP(s2) !== null && rigaP(s2).esito === 'iscritto' && iscrittoAltro(), [JSON.stringify(rigaP(s2))]);
        imposta('PAYMENTS_TEST_MODE', '1');
        sql('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, altroS.id);

        let s3 = sessione({ payment_status: 'unpaid' });
        e = await manda(evento(s3));
        check('un pagamento completato ma non ancora pagato (bonifico) aspetta', e.stato === 200 && rigaP(s3) === null && !iscrittoAltro());
        e = await manda(evento({ ...s3, payment_status: 'paid' }, 'checkout.session.async_payment_succeeded'));
        check('… e la sua conferma iscrive', rigaP(s3) !== null && rigaP(s3).esito === 'iscritto' && iscrittoAltro());
        sql('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, altroS.id);

        interruttore('0');
        const s4 = sessione();
        await manda(evento(s4));
        check('con i pagamenti spenti, chi ha pagato e\' iscritto lo stesso', rigaP(s4) !== null && rigaP(s4).esito === 'iscritto' && iscrittoAltro());
        interruttore('1');
        imposta('STRIPE_WEBHOOK_SECRET', '');
        e = await manda(evento(sessione()));
        check('senza chiave degli avvisi: 400', e.stato === 400 && e.testo === 'Chiave degli avvisi non impostata.', [JSON.stringify(e)]);
        imposta('STRIPE_WEBHOOK_SECRET', CHIAVE);

        // --- il catalogo: la schedaP del corso a pagamento --------------------------------------
        sql('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', C.libero, A.studente);
        await studente.goto(BASE + '/catalogo');
        const schedaP = await studente.evaluate((t) => {
            const c = [...document.querySelectorAll('.catalog-card')].find((x) => x.textContent.includes(t));
            if (!c) return null;
            const a = c.querySelector('a.btn-primary');
            return { badge: !!c.querySelector('.card-head .badge'), lezioni: !!c.querySelector('.catalogo-lezioni'),
                prezzo: (c.querySelector('.catalogo-paga-prezzo') || {}).textContent, testo: a && a.textContent.trim(), href: a && a.getAttribute('href'),
                descritto: a && a.getAttribute('aria-describedby') === (c.querySelector('.catalogo-paga-prezzo') || {}).id };
        }, '[prova-pagamenti] corso libero');
        const attesoP = new URL(LINK); attesoP.searchParams.set('client_reference_id', String(A.studente)); attesoP.searchParams.set('prefilled_email', 'stud@test.it');
        check('accesi: nel catalogo la scheda con il pulsante diviso, «49 €» e «Iscriviti», senza etichetta e senza lezioni',
            schedaP !== null && !schedaP.badge && !schedaP.lezioni && schedaP.prezzo === '49 €' && schedaP.testo === 'Iscriviti' && schedaP.descritto, [JSON.stringify(schedaP)]);
        check('… e il pulsante porta al link di Stripe con il numero e l\'email dello studente',
            schedaP !== null && schedaP.href === attesoP.toString(), [schedaP && schedaP.href, attesoP.toString()]);
        interruttore('0');
        await studente.goto(BASE + '/catalogo');
        check('spenti: nel catalogo non c\'e\'', !(await studente.content()).includes('[prova-pagamenti] corso libero'));
        interruttore('1');

        // --- Impostazioni › Pagamenti: chiave, prova, pagamenti ricevuti -------------------------
        await admin.goto(BASE + '/admin/settings/pagamenti');
        let paginaP = await admin.content();
        check('la chiave non torna mai nella paginaP, si dice solo che c\'e\'', !paginaP.includes(CHIAVE) && paginaP.includes('impostata — scrivi qui per sostituirla'));
        check('l\'indirizzo degli avvisi da copiare su Stripe', (await admin.$eval('#stripe-indirizzo', (i) => i.value)).endsWith('/stripe/avvisi'));
        await admin.fill('#stripe-chiave', 'sk_sbagliata');
        await Promise.all([admin.waitForNavigation(), admin.click('form.form .form-actions button[type="submit"]')]);
        check('una chiave che non comincia con «whsec_» si rifiuta, e resta quella di prima',
            (await errore(admin)).startsWith('La chiave degli avvisi comincia con «whsec_»')
            && php('echo App\\Core\\Settings::stored("STRIPE_WEBHOOK_SECRET");').trim() === CHIAVE);
        await admin.uncheck('#pagamenti-prova');
        await Promise.all([admin.waitForNavigation(), admin.click('form.form .form-actions button[type="submit"]')]);
        check('la modalita\' di prova si spegne dalla paginaP', php('echo App\\Core\\Settings::stored("PAYMENTS_TEST_MODE");').trim() === '0');
        imposta('PAYMENTS_TEST_MODE', '1');
        const righeP = await admin.$$eval('.pagamenti-ricevuti tbody tr', (t) => t.map((r) => r.textContent.replace(/\s+/g, ' ')));
        check('i pagamenti ricevuti, con «iscrizione completata» e «iscrizione non riuscita» e il motivo',
            righeP.some((r) => r.includes('iscrizione completata')) && righeP.some((r) => r.includes('iscrizione non riuscita') && r.includes('Studente non riconosciuto')), [righeP.length + ' righeP']);
        await admin.goto(BASE + '/admin/settings/pagamenti?corso=' + A.corso);
        check('filtrati per un corso senza pagamenti: nessuno', (await admin.$$('.pagamenti-ricevuti tbody tr')).length === 0);
        await admin.goto(BASE + '/admin/settings/pagamenti?corso=' + C.libero);
        check('filtrati per il corso: solo i suoi', (await admin.$$eval('.pagamenti-ricevuti tbody tr td[data-label="Corso"]', (t) => t.map((x) => x.textContent.trim()))).every((x) => x === '[prova-pagamenti] corso libero'));

        // --- il ritorno da Stripe: la pagina d'attesa e l'avviso (06/11) ------------------------
        // La pagina compare sempre, per 5 secondi, poi porta a «I miei corsi»
        // con il codice; la sola differenza e' l'avviso, finche' il pagamento
        // non risulta registrato per quello studente.
        const sessRitorno = 'cs_test_ritorno_' + Date.now();
        const vai = (u) => studente.request.get(BASE + u, { maxRedirects: 0 });
        const paginaAttesa = async () => {
            const r = await vai('/pagamento-ricevuto?sessione=' + sessRitorno);
            const t = await r.text();
            return { stato: r.status(), refresh: r.headers()['refresh'],
                testi: t.includes('Pagamento ricevuto') && t.includes('Un momento: stiamo preparando il tuo corso.') && t.includes('Tra pochi secondi tornerai a «I miei corsi».') };
        };
        const avvisoIMieiCorsi = async () => {
            await studente.goto(BASE + '/?pagamento=' + sessRitorno);
            return studente.evaluate(() => ((document.querySelector('.alert-info') || {}).textContent || '').replace(/\s+/g, ' ').trim());
        };
        const AVVISO = 'Il pagamento non è ancora confermato da Stripe: il corso comparirà qui appena arriva la conferma. Se non lo vedi, ricarica la pagina tra poco.';
        let pa = await paginaAttesa();
        check('pagamento non ancora registrato: la pagina d\'attesa, con i suoi testi, e dopo 5 secondi «I miei corsi» con il codice',
            pa.stato === 200 && pa.testi && pa.refresh === '5; url=/?pagamento=' + sessRitorno, [JSON.stringify(pa)]);
        let avvisoR = await avvisoIMieiCorsi();
        check('… e in «I miei corsi» l\'avviso: pagamento non ancora confermato', avvisoR === AVVISO, [avvisoR]);
        // Il pagamento di un altro studente con quel codice non conta per lui.
        sql('INSERT INTO payments (stripe_event_id, stripe_session_id, livemode, course_id, user_id, amount_total, amount_subtotal, currency, esito) VALUES (?, ?, 0, ?, ?, 4900, 4900, \'eur\', \'iscritto\')',
            'evt_ritorno_' + sessRitorno, sessRitorno, C.libero, altroS.id);
        avvisoR = await avvisoIMieiCorsi();
        check('il pagamento di un altro studente con lo stesso codice non conta: l\'avviso resta', avvisoR === AVVISO, [avvisoR]);
        sql('UPDATE payments SET user_id = ? WHERE stripe_session_id = ?', A.studente, sessRitorno);
        pa = await paginaAttesa();
        check('pagamento gia\' registrato: la pagina d\'attesa compare lo stesso, per 5 secondi',
            pa.stato === 200 && pa.testi && pa.refresh === '5; url=/?pagamento=' + sessRitorno, [JSON.stringify(pa)]);
        check('… e in «I miei corsi» l\'avviso non c\'e\'', (await avvisoIMieiCorsi()) === '');
        sql('DELETE FROM payments WHERE stripe_session_id = ?', sessRitorno);
        const senza = await vai('/pagamento-ricevuto');
        check('senza codice porta subito a «I miei corsi»', senza.status() === 302 && senza.headers()['location'] === '/', [String(senza.headers()['location'])]);
        const ospite = await browser.newContext();
        const ro = await ospite.request.get(BASE + '/pagamento-ricevuto?sessione=' + sessRitorno, { maxRedirects: 0 });
        check('senza accesso, la pagina d\'attesa manda al login', ro.status() === 302 && String(ro.headers()['location']).includes('/login'), [String(ro.status()), String(ro.headers()['location'])]);
        await ospite.close();
        await admin.goto(BASE + '/admin/settings/pagamenti');
        check('in Impostazioni › Pagamenti l\'indirizzo dopo il pagamento, con il segnaposto di Stripe',
            (await admin.$eval('#stripe-ritorno', (i) => i.value)).endsWith('/pagamento-ricevuto?sessione={CHECKOUT_SESSION_ID}'));

        // --- il collegamento dell'email porta al corso, anche passando dall'accesso (06/11)
        const ospiteE = await (await browser.newContext()).newPage();
        await ospiteE.goto(BASE + '/courses/' + A.corso);
        check('senza accesso, il corso manda al login', ospiteE.url().includes('/login'), [ospiteE.url()]);
        await ospiteE.fill('input[name="email"]', 'stud@test.it');
        await ospiteE.fill('input[name="password"]', PASSWORD);
        await ospiteE.click('form.auth-form button[type="submit"]');
        await ospiteE.waitForLoadState('networkidle');
        check('… e dopo l\'accesso apre proprio quel corso', new URL(ospiteE.url()).pathname === '/courses/' + A.corso, [ospiteE.url()]);
        await ospiteE.goto(BASE + '/logout').catch(() => {});
        await ospiteE.context().close();
        const ospiteF = await (await browser.newContext()).newPage();
        await ospiteF.goto(BASE + '/login');
        await ospiteF.fill('input[name="email"]', 'stud@test.it');
        await ospiteF.fill('input[name="password"]', PASSWORD);
        await ospiteF.click('form.auth-form button[type="submit"]');
        await ospiteF.waitForLoadState('networkidle');
        check('controprova: entrando dalla pagina di accesso si arriva a «I miei corsi»', new URL(ospiteF.url()).pathname === '/', [ospiteF.url()]);
        await ospiteF.context().close();

        // --- chi apre Impostazioni › Pagamenti -----------------------------------------------
        for (const email of ['tutor1@test.it', 'assist@test.it', 'stud@test.it']) {
            const p = (await entra(browser, email)).page;
            check(email + ' → Impostazioni › Pagamenti: negato', (await esitoGet(p, '/admin/settings/pagamenti')) === 'negato');
            const prima2 = php('echo App\\Core\\Settings::stored("PAYMENTS_ENABLED");').trim();
            await posta(p, '/admin/settings/pagamenti', [['PAYMENTS_ENABLED', '0']], '/profilo');
            check(email + ' → una POST non spegne i pagamenti', php('echo App\\Core\\Settings::stored("PAYMENTS_ENABLED");').trim() === prima2);
        }
        check('controprova: l\'admin apre Impostazioni › Pagamenti', (await esitoGet(admin, '/admin/settings/pagamenti')) === 'consentito');
    } catch (e) {
        check('la suite e\' arrivata in fondo', false, [String(e && e.stack || e)]);
    } finally {
        await browser.close();
        sql('DELETE FROM group_course_access WHERE course_id IN (SELECT id FROM courses WHERE title LIKE \'[prova-pagamenti]%\')');
        pulisci();
        interruttore(prima);
        imposta('STRIPE_WEBHOOK_SECRET', primaChiave);
        imposta('PAYMENTS_TEST_MODE', primaProva);
        php('$pdo->exec("DELETE FROM payments WHERE stripe_session_id LIKE \'cs_test_%\'"); $pdo->exec("DELETE FROM email_queue WHERE subject LIKE \'Pagamento da controllare%\'");');
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
