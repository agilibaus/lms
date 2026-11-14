/**
 * Il passaggio della bozza dal supervisore all'Amministratore (14/11,
 * Alessandro).
 *
 *   - una bozza e' presso chi la modifica: quella creata dal supervisore
 *     presso di lui, quella creata dall'Amministratore presso di lui;
 *   - chi non la ha presso di se' la legge soltanto: nella scheda i campi
 *     spenti e niente «Salva», nella pagina dei contenuti niente comandi di
 *     modifica, e il server rifiuta;
 *   - un solo passaggio, dal supervisore all'Amministratore: «Invia
 *     all'Amministratore» (con l'email all'Amministratore) o «Riprendi il
 *     corso» (con l'email al supervisore); non si torna indietro;
 *   - in «Gestione corsi» lo stato («Bozza presso supervisore», «Bozza presso
 *     Amministratore», «Pubblicato»), «Report» spento sulle bozze, «Elimina»
 *     spento sulla bozza presso il supervisore, e il server non la elimina.
 *
 * Ogni rifiuto ha la sua controprova. Le azioni si giudicano dal database;
 * le email dai file della posta su registro (`storage/mail`, la posta del
 * contenitore).
 *
 * Esecuzione:  node tests/passaggio_bozza.js   (server di sviluppo acceso)
 *
 * Crea il supervisore «supervisore-passaggio@test.it» e i corsi
 * «[prova-passaggio]», e alla fine li toglie.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const POSTA = path.join(RADICE, 'storage', 'mail');
const SUP = 'supervisore-passaggio@test.it';
const ADM = 'admin@test.it';
const MARCHIO = '[prova-passaggio]';

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

const riga = (sql, ...a) => JSON.parse(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo json_encode($s->fetch(PDO::FETCH_ASSOC) ?: null);', sql, ...a));
const corso = (titolo) => riga('SELECT id, title, is_published, presso, passato_il, passaggio FROM courses WHERE title = ?', titolo);
const corsoId = (id) => riga('SELECT id, title, is_published, presso, passato_il, passaggio FROM courses WHERE id = ?', id);
const moduli = (id) => Number(riga('SELECT COUNT(*) AS n FROM modules WHERE course_id = ?', id).n);

function pulisci() {
    php('$pdo->exec("DELETE FROM courses WHERE title LIKE \'[prova-passaggio]%\'");'
        + ' $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', SUP);
}

/** Le email arrivate dopo `prima` (nomi dei file), con destinatario e oggetto. */
function emailDopo(prima) {
    return fs.readdirSync(POSTA).filter((f) => f.endsWith('.eml') && !prima.has(f)).map((f) => {
        const testo = fs.readFileSync(path.join(POSTA, f), 'utf8');
        const oggetto = (testo.match(/^Subject: (.*)$/m) || [])[1] || '';
        const a = (testo.match(/^To: (.*)$/m) || [])[1] || '';
        // L'oggetto puo' essere codificato (=?UTF-8?B?...?=).
        const chiaro = oggetto.replace(/=\?UTF-8\?B\?([^?]+)\?=/gi, (_, b) => Buffer.from(b, 'base64').toString('utf8'))
            .replace(/=\?UTF-8\?Q\?([^?]+)\?=/gi, (_, q) => decodeURIComponent(q.replace(/_/g, ' ').replace(/=([0-9A-F]{2})/gi, '%$1')));
        return { a, oggetto: chiaro.replace(/\s+/g, ' ').trim() };
    });
}
const fotografiaPosta = () => new Set(fs.readdirSync(POSTA));

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

/** Una POST con il token di una pagina che l'utente apre; torna lo stato e il testo. */
async function posta(page, url, campi, paginaToken) {
    await page.goto(BASE + paginaToken);
    return page.evaluate(async ([url, campi]) => {
        const dati = new URLSearchParams();
        dati.append('_token', document.querySelector('input[name="_token"]').value);
        for (const [k, v] of campi) dati.append(k, v);
        const res = await fetch(url, { method: 'POST', body: dati, credentials: 'same-origin', redirect: 'manual' });
        return { stato: res.status, testo: res.type === 'opaqueredirect' ? '' : await res.text() };
    }, [url, campi]);
}

const campiCorso = (titolo, pubblicato = false) => [['title', titolo], ['slug', ''], ['description', ''], ['objective', ''], ['enrollment_mode', 'closed']]
    .concat(pubblicato ? [['is_published', '1']] : []);

/** Come si presenta la scheda del corso a chi e' entrato. */
async function scheda(page, id) {
    await page.goto(BASE + '/admin/courses/' + id + '/edit');
    return page.evaluate(() => ({
        stato: ((document.querySelector('.page-header .page-subtitle') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
        riquadro: ((document.querySelector('.bozza-passaggio p') || {}).textContent || '').trim(),
        invia: [...document.querySelectorAll('.bozza-passaggio button')].map((b) => b.textContent.trim()),
        conferma: (document.querySelector('.bozza-passaggio form') || { getAttribute: () => '' }).getAttribute('onsubmit') || '',
        spenti: document.querySelectorAll('fieldset.campi-sola-lettura[disabled]').length,
        salva: [...document.querySelectorAll('main button[type="submit"]')].map((b) => b.textContent.trim()).filter((t) => /^Salva/.test(t)),
        report: !!document.querySelector('.page-header a[href^="/reports/"]'),
    }));
}

/** La riga del corso in «Gestione corsi». */
async function gestione(page, id) {
    await page.goto(BASE + '/admin/courses');
    return page.evaluate((id) => {
        const r = [...document.querySelectorAll('tbody tr')].find((t) => t.querySelector('a[href="/admin/courses/' + id + '/edit"]'));
        if (!r) return null;
        return {
            stato: (r.querySelector('td[data-label="Stato"] .badge') || {}).textContent.trim(),
            report: !!r.querySelector('a[href^="/reports/courses/"]'),
            reportSpento: [...r.querySelectorAll('.comando-spento')].some((s) => s.textContent.includes('Report')),
            elimina: !!r.querySelector('form[action$="/delete"]'),
            eliminaSpento: [...r.querySelectorAll('.comando-spento')].some((s) => s.textContent.includes('Elimina')),
        };
    }, id);
}

(async () => {
    pulisci();
    php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Supervisore\', \'Passaggio\', \'supervisore\', 1, NOW(), NOW())")->execute([$argv[1], password_hash($argv[2], PASSWORD_DEFAULT)]);', SUP, PASSWORD);
    const browser = await chromium.launch();

    try {
        const sup = await entra(browser, SUP);
        const adm = await entra(browser, ADM);

        // --- 1. la bozza del supervisore nasce presso di lui ------------------
        await posta(sup.page, '/admin/courses', campiCorso(MARCHIO + ' uno'), '/admin/courses/create');
        const uno = corso(MARCHIO + ' uno');
        check('il supervisore crea un corso: in bozza, presso di lui', uno !== null && Number(uno.is_published) === 0 && uno.presso === 'supervisore', [JSON.stringify(uno)]);
        const U = uno.id;

        let s = await scheda(sup.page, U);
        check('supervisore: «Bozza presso supervisore», il riquadro e «Invia all’Amministratore», con la conferma',
            s.stato.startsWith('Bozza presso supervisore') && s.riquadro.startsWith('Il corso è presso di te') && s.invia.join() === 'Invia all’Amministratore'
                && s.conferma.includes('Dopo l’invio non potrai più modificare il corso'), [JSON.stringify(s)]);
        check('supervisore: la scheda si modifica (campi accesi, «Salva»), e niente «report»', s.spenti === 0 && s.salva.length > 0 && !s.report, [JSON.stringify(s)]);
        await posta(sup.page, '/admin/courses/' + U, campiCorso(MARCHIO + ' uno, rinominato'), '/admin/courses/' + U + '/edit');
        await posta(sup.page, '/courses/' + U + '/modules', [['title', 'Modulo del supervisore']], '/courses/' + U + '/modules/create');
        check('controprova: il supervisore salva i dati e crea un modulo', corsoId(U).title === MARCHIO + ' uno, rinominato' && moduli(U) === 1, [JSON.stringify(corsoId(U))]);

        // --- 2. l'Amministratore la legge soltanto ---------------------------
        s = await scheda(adm.page, U);
        check('Amministratore: «Bozza presso supervisore», il riquadro e «Riprendi il corso», con la conferma',
            s.stato.startsWith('Bozza presso supervisore') && s.riquadro.startsWith('Ci sta lavorando il supervisore') && s.invia.join() === 'Riprendi il corso'
                && s.conferma.includes('Il supervisore non potrà più modificare il corso'), [JSON.stringify(s)]);
        check('Amministratore: dati e copertina in sola lettura (campi spenti, niente «Salva»), niente «report»',
            s.spenti === 2 && s.salva.length === 0 && !s.report, [JSON.stringify(s)]);
        await adm.page.goto(BASE + '/courses/' + U);
        const comandiAdm = await adm.page.evaluate(() => !!document.querySelector('a[href$="/modules/create"]'));
        check('Amministratore: nella pagina dei contenuti niente «Nuovo modulo»', !comandiAdm);
        let r = await posta(adm.page, '/admin/courses/' + U, campiCorso(MARCHIO + ' uno, dall’Amministratore'), '/admin/courses/' + U + '/edit');
        await posta(adm.page, '/courses/' + U + '/modules', [['title', 'Modulo dell’Amministratore']], '/admin/courses/' + U + '/edit');
        check('Amministratore: il server rifiuta i dati e il modulo (403), con la frase che dice dov’è la bozza',
            corsoId(U).title === MARCHIO + ' uno, rinominato' && moduli(U) === 1 && r.stato === 403 && r.testo.includes('presso il supervisore'), [JSON.stringify(r), JSON.stringify(corsoId(U))]);
        await posta(adm.page, '/admin/courses/' + U + '/delete', [], '/admin/courses/' + U + '/edit');
        check('Amministratore: la bozza presso il supervisore non si elimina', corsoId(U) !== null);
        let g = await gestione(adm.page, U);
        check('«Gestione corsi»: «Bozza presso supervisore», «Report» ed «Elimina» spenti',
            g !== null && g.stato === 'Bozza presso supervisore' && !g.report && g.reportSpento && !g.elimina && g.eliminaSpento, [JSON.stringify(g)]);

        // --- 3. il supervisore la invia --------------------------------------
        r = await posta(adm.page, '/admin/courses/' + U + '/invia', [], '/admin/courses/' + U + '/edit');
        check('l’Amministratore non può «inviare» (403)', corsoId(U).presso === 'supervisore' && r.stato === 403, [JSON.stringify(r)]);
        let prima = fotografiaPosta();
        await posta(sup.page, '/admin/courses/' + U + '/invia', [], '/admin/courses/' + U + '/edit');
        let c = corsoId(U);
        check('il supervisore la invia: presso l’Amministratore, con data e «invio»',
            c.presso === 'amministratore' && c.passaggio === 'invio' && c.passato_il !== null && Number(c.is_published) === 0, [JSON.stringify(c)]);
        // Un'email per ogni Amministratore attivo: in produzione e' uno solo
        // (0305); nel contenitore c'e' anche quello di prova.
        const amministratori = Number(riga("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND is_active = 1").n);
        let posta1 = emailDopo(prima);
        check('ogni Amministratore attivo riceve l’email «Corso inviato», e nessun altro',
            posta1.length === amministratori && posta1.some((e) => e.a.includes(ADM))
                && posta1.every((e) => e.oggetto === 'Corso inviato: ' + MARCHIO + ' uno, rinominato'), [String(amministratori), JSON.stringify(posta1)]);
        const passato = c.passato_il;
        await posta(sup.page, '/admin/courses/' + U + '/invia', [], '/admin/courses/' + U + '/edit');
        check('un secondo invio non cambia niente, e non manda altre email', corsoId(U).passato_il === passato && emailDopo(prima).length === amministratori);

        s = await scheda(sup.page, U);
        check('supervisore, dopo l’invio: «Bozza presso Amministratore», «L’hai inviato all’Amministratore il …», sola lettura',
            s.stato.startsWith('Bozza presso Amministratore') && /^L’hai inviato all’Amministratore il \d\d\/\d\d\/\d{4} \d\d:\d\d: lo puoi leggere, non modificare\.$/.test(s.riquadro)
                && s.invia.length === 0 && s.spenti === 2 && s.salva.length === 0, [JSON.stringify(s)]);
        r = await posta(sup.page, '/admin/courses/' + U, campiCorso(MARCHIO + ' uno, ancora dal supervisore'), '/admin/courses/' + U + '/edit');
        await posta(sup.page, '/courses/' + U + '/modules', [['title', 'Modulo in ritardo']], '/admin/courses/' + U + '/edit');
        check('supervisore, dopo l’invio: il server rifiuta i dati e il modulo, con la frase',
            corsoId(U).title === MARCHIO + ' uno, rinominato' && moduli(U) === 1 && r.stato === 403 && r.testo.includes('passata all’Amministratore'), [JSON.stringify(r)]);

        s = await scheda(adm.page, U);
        check('Amministratore, dopo l’invio: «Il supervisore te l’ha inviato il …», campi accesi e «Salva»',
            /^Il supervisore te l’ha inviato il \d\d\/\d\d\/\d{4} \d\d:\d\d: lo modifichi tu, e lo pubblichi quando è pronto\.$/.test(s.riquadro)
                && s.invia.length === 0 && s.spenti === 0 && s.salva.length > 0, [JSON.stringify(s)]);
        await posta(adm.page, '/admin/courses/' + U, campiCorso(MARCHIO + ' uno, dall’Amministratore'), '/admin/courses/' + U + '/edit');
        await posta(adm.page, '/courses/' + U + '/modules', [['title', 'Modulo dell’Amministratore']], '/courses/' + U + '/modules/create');
        check('controprova: l’Amministratore ora salva i dati e crea un modulo', corsoId(U).title === MARCHIO + ' uno, dall’Amministratore' && moduli(U) === 2);
        g = await gestione(adm.page, U);
        check('«Gestione corsi»: «Bozza presso Amministratore», «Report» spento, «Elimina» acceso',
            g !== null && g.stato === 'Bozza presso Amministratore' && g.reportSpento && !g.report && g.elimina && !g.eliminaSpento, [JSON.stringify(g)]);
        await posta(adm.page, '/admin/courses/' + U, campiCorso(MARCHIO + ' uno, dall’Amministratore', true), '/admin/courses/' + U + '/edit');
        g = await gestione(adm.page, U);
        check('pubblicato: «Pubblicato», «Report» ed «Elimina» accesi', Number(corsoId(U).is_published) === 1 && g.stato === 'Pubblicato' && g.report && g.elimina, [JSON.stringify(g)]);

        // --- 4. l'Amministratore la riprende ---------------------------------
        await posta(sup.page, '/admin/courses', campiCorso(MARCHIO + ' due'), '/admin/courses/create');
        const D = corso(MARCHIO + ' due').id;
        r = await posta(sup.page, '/admin/courses/' + D + '/riprendi', [], '/admin/courses/' + D + '/edit');
        check('il supervisore non può «riprendere» (403)', corsoId(D).presso === 'supervisore' && r.stato === 403);
        prima = fotografiaPosta();
        await posta(adm.page, '/admin/courses/' + D + '/riprendi', [], '/admin/courses/' + D + '/edit');
        c = corsoId(D);
        check('l’Amministratore la riprende: presso di lui, con data e «ripresa»', c.presso === 'amministratore' && c.passaggio === 'ripresa' && c.passato_il !== null, [JSON.stringify(c)]);
        posta1 = emailDopo(prima);
        check('il supervisore riceve l’email «Corso ripreso dall’Amministratore»',
            posta1.length === 1 && posta1[0].a.includes(SUP) && posta1[0].oggetto === 'Corso ripreso dall’Amministratore: ' + MARCHIO + ' due', [JSON.stringify(posta1)]);
        await posta(adm.page, '/admin/courses/' + D + '/riprendi', [], '/admin/courses/' + D + '/edit');
        check('una seconda ripresa non cambia niente', corsoId(D).passato_il === c.passato_il && emailDopo(prima).length === 1);
        s = await scheda(sup.page, D);
        check('supervisore, dopo la ripresa: «L’Amministratore l’ha ripreso il …», sola lettura',
            /^L’Amministratore l’ha ripreso il \d\d\/\d\d\/\d{4} \d\d:\d\d: lo puoi leggere, non modificare\.$/.test(s.riquadro) && s.spenti === 2 && s.salva.length === 0, [JSON.stringify(s)]);
        s = await scheda(adm.page, D);
        check('Amministratore, dopo la ripresa: «L’hai ripreso il …», campi accesi',
            /^L’hai ripreso il \d\d\/\d\d\/\d{4} \d\d:\d\d: lo modifichi tu, e lo pubblichi quando è pronto\.$/.test(s.riquadro) && s.spenti === 0, [JSON.stringify(s)]);
        r = await posta(sup.page, '/admin/courses/' + D + '/invia', [], '/admin/courses/' + D + '/edit');
        check('dopo la ripresa il supervisore non può inviarla', corsoId(D).passaggio === 'ripresa');

        // --- 5. la bozza dell'Amministratore --------------------------------
        await posta(adm.page, '/admin/courses', campiCorso(MARCHIO + ' tre'), '/admin/courses/create');
        const T = corso(MARCHIO + ' tre');
        check('l’Amministratore crea un corso: in bozza, presso di lui', T !== null && T.presso === 'amministratore' && T.passaggio === null, [JSON.stringify(T)]);
        s = await scheda(adm.page, T.id);
        check('Amministratore, bozza sua: «Bozza presso Amministratore», niente riquadro, campi accesi',
            s.stato.startsWith('Bozza presso Amministratore') && s.riquadro === '' && s.spenti === 0 && s.salva.length > 0, [JSON.stringify(s)]);
        s = await scheda(sup.page, T.id);
        check('supervisore, bozza dell’Amministratore: niente riquadro, sola lettura', s.riquadro === '' && s.spenti === 2 && s.salva.length === 0, [JSON.stringify(s)]);
        r = await posta(sup.page, '/admin/courses/' + T.id, campiCorso(MARCHIO + ' tre, dal supervisore'), '/admin/courses/' + T.id + '/edit');
        check('supervisore, bozza dell’Amministratore: il server rifiuta', corsoId(T.id).title === MARCHIO + ' tre' && r.stato === 403);
        r = await posta(sup.page, '/admin/courses/' + T.id + '/invia', [], '/admin/courses/' + T.id + '/edit');
        check('supervisore, bozza dell’Amministratore: non la può «inviare»', corsoId(T.id).passaggio === null);

        await sup.ctx.close();
        await adm.ctx.close();
    } finally {
        await browser.close();
        pulisci();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
