/**
 * Privacy (18/11, Alessandro): l'informativa, l'avviso e la cancellazione.
 *
 * COSA VERIFICA:
 *
 *   1. **Prima della pubblicazione** nessuna pagina nomina l'informativa:
 *      ne' la registrazione, ne' l'accesso, ne' il profilo; /privacy lo dice.
 *   2. **La pubblicazione** dall'Amministratore: il testo passa dal
 *      sanificatore; la pagina pubblica ha titolo, data e testo.
 *   3. **La pagina /privacy**: la barra resta in cima scorrendo, il pulsante
 *      dipende dalla provenienza e riporta li'; una provenienza confezionata
 *      non porta altrove; le versioni precedenti solo all'Amministratore.
 *   4. **La registrazione**: la frase sotto «Registrati»; andata e ritorno
 *      dall'informativa con i campi rimessi (mai la password); la versione
 *      comunicata si registra.
 *   5. **L'accesso**: «Privacy» in fondo, grigio e senza sottolineatura.
 *   6. **L'avviso** sulla pagina dei corsi, solo li': chi non ha ricevuto la
 *      versione in vigore lo vede, la x lo chiude; una correzione non lo
 *      riapre, una nuova versione si'.
 *   7. **La cancellazione**: dal profilo alla conferma; l'accesso si chiude
 *      subito anche nelle altre sessioni, con il motivo giusto; il numero
 *      nel menu dell'Amministratore e il «!» sul riquadro; la richiesta in
 *      Impostazioni › Privacy; lo staff non la vede; riattivare l'account
 *      la annulla; «Cancella l'account» lo toglie.
 *
 * Esecuzione:  node tests/privacy.js   (server di sviluppo acceso)
 *
 * Le versioni e gli studenti di prova si creano e si tolgono. Le versioni
 * che c'erano prima, e la versione comunicata a ogni utente, si mettono da
 * parte in due tabelle di parcheggio e alla fine tornano com'erano, anche
 * se la prova si interrompe (20/11). Fino al 20/11 si mettevano da parte
 * spostandone il numero in avanti (+1000000): ma la versione in vigore e'
 * quella con il numero piu' alto, quindi da quando nel database c'e'
 * un'informativa pubblicata la prova partiva con quella, e alla fine
 * riportava le versioni di prova fra quelle vere.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const STUDENTE = 'privacy-avviso@test.it';
const DA_CANCELLARE = 'privacy-cancella@test.it';
const NUOVO = 'privacy-nuovo@test.it';

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
const esegui = (sql, ...a) => php('$pdo->prepare($argv[1])->execute(array_slice($argv, 2));', sql, ...a);

function creaStudente(email) {
    esegui('DELETE FROM users WHERE email = ?', email);
    php('App\\Models\\UserModel::create($argv[1], $argv[2], "Prova", "Privacy");', email, PASSWORD);
    // Primo accesso e benvenuto gia' fatti: si arriva diritti ai corsi.
    esegui("UPDATE users SET name_display = 'full', welcome_seen_at = NOW() WHERE email = ?", email);
    return Number(valore('SELECT id FROM users WHERE email = ?', email));
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

async function pubblica(admin, html, azione = 'pubblica') {
    await admin.goto(BASE + '/admin/settings/privacy');
    await admin.waitForSelector('.tox-tinymce');
    await admin.evaluate((t) => window.tinymce.activeEditor.setContent(t), html);
    await admin.click('button[name="azione"][value="' + azione + '"]');
    await admin.waitForLoadState('networkidle');
    return admin.locator('.alert').first().innerText();
}

const TESTO = '<h2>1. Chi tratta i tuoi dati</h2><p>Il titolare del trattamento.</p>'
    + Array.from({ length: 12 }, (_, i) => '<h2>' + (i + 2) + '. Sezione</h2><p>' + 'Testo della sezione. '.repeat(30) + '</p>').join('')
    + '<script>window.rotto = 1</script><img src="/x.png" alt="">';

/*
 * IL PARCHEGGIO. Le versioni dell'informativa e la versione comunicata a
 * ciascun utente si copiano in due tabelle a parte, e si tolgono: la prova
 * parte davvero senza informativa. Alla fine si cancella quello che la
 * prova ha creato e si rimette il resto, con gli stessi numeri.
 */
const PARCHEGGIO = 'prova_privacy_versioni';
const PARCHEGGIO_UTENTI = 'prova_privacy_utenti';

// Un'impronta di quello che si parcheggia, per controllare alla fine che
// sia tornato tutto com'era.
const impronta = () => valore(
    "SELECT CONCAT((SELECT COUNT(*) FROM privacy_versions), ':',"
    + " (SELECT COALESCE(SUM(CRC32(CONCAT(id, '|', content_html, '|', published_at, '|', COALESCE(corrected_at, '')))), 0) FROM privacy_versions), ':',"
    + " (SELECT COALESCE(SUM(CRC32(CONCAT(id, '|', privacy_version_id))), 0) FROM users WHERE privacy_version_id IS NOT NULL AND email NOT IN (?, ?, ?)))",
    STUDENTE, DA_CANCELLARE, NUOVO
);

const parcheggioPieno = () => valore(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', PARCHEGGIO
) === '1';

function parcheggia() {
    php(`$pdo->exec("CREATE TABLE ${PARCHEGGIO} LIKE privacy_versions");
         $pdo->exec("INSERT INTO ${PARCHEGGIO} SELECT * FROM privacy_versions");
         $pdo->exec("CREATE TABLE ${PARCHEGGIO_UTENTI} AS SELECT id, privacy_version_id FROM users WHERE privacy_version_id IS NOT NULL");
         $pdo->exec("UPDATE users SET privacy_version_id = NULL");
         $pdo->exec("DELETE FROM privacy_versions");`);
}

function ripristina() {
    php(`$pdo->exec("UPDATE users SET privacy_version_id = NULL");
         $pdo->exec("DELETE FROM privacy_versions");
         $pdo->exec("INSERT INTO privacy_versions SELECT * FROM ${PARCHEGGIO}");
         $pdo->exec("UPDATE users u JOIN ${PARCHEGGIO_UTENTI} p ON p.id = u.id SET u.privacy_version_id = p.privacy_version_id");
         $pdo->exec("ALTER TABLE privacy_versions AUTO_INCREMENT = 1");
         $pdo->exec("DROP TABLE ${PARCHEGGIO_UTENTI}");
         $pdo->exec("DROP TABLE ${PARCHEGGIO}");`);
}

(async () => {
    // Un giro di prima interrotto senza pulizia (il processo fermato a
    // mano): prima di tutto si rimette quello che aveva parcheggiato.
    if (parcheggioPieno()) {
        console.log('Un giro precedente si era interrotto: rimetto le versioni parcheggiate.');
        ripristina();
    }
    let improntaPrima = null;

    const browser = await chromium.launch();
    const errori = [];

    try {
        const idStudente = creaStudente(STUDENTE);
        const idCancella = creaStudente(DA_CANCELLARE);
        esegui('DELETE FROM users WHERE email = ?', NUOVO);

        // Si parte senza informativa: quelle che c'erano si mettono da parte.
        improntaPrima = impronta();
        parcheggia();

        const { page: admin } = await entra(browser, 'admin@test.it');
        admin.on('pageerror', (e) => errori.push('admin: ' + e.message));
        // Le conferme («Pubblicare una nuova versione?», «Cancellare l'account?»): si'.
        admin.on('dialog', (d) => d.accept());
        const anonimo = await (await browser.newContext()).newPage();
        anonimo.on('pageerror', (e) => errori.push('anonimo: ' + e.message));

        console.log('\n1. Prima della pubblicazione');
        await anonimo.goto(BASE + '/register');
        check('la registrazione non nomina l\'informativa', await anonimo.locator('.nota-privacy').count() === 0);
        await anonimo.goto(BASE + '/login');
        check('l\'accesso non ha «Privacy»', await anonimo.locator('.auth-privacy').count() === 0);
        await anonimo.goto(BASE + '/privacy');
        check('/privacy dice che non e\' pubblicata', (await anonimo.locator('main').innerText()).includes('non è ancora stata pubblicata'));
        const st = await entra(browser, STUDENTE);
        await st.page.goto(BASE + '/');
        check('nessun avviso sui corsi', await st.page.locator('.avviso-privacy').count() === 0);
        await st.page.goto(BASE + '/profilo');
        const sezione = st.page.locator('section.card', { has: st.page.locator('h2', { hasText: 'Privacy' }) });
        check('il profilo ha la sezione Privacy, senza link all\'informativa', await sezione.count() === 1
            && await sezione.locator('a[href^="/privacy"]').count() === 0);
        const stili = await st.page.evaluate(() => {
            const s = (e) => { const c = getComputedStyle(e); return [c.fontSize, c.color, c.fontFamily].join(' / '); };
            const nota = [...document.querySelectorAll('section.card')].find((c) => c.querySelector('h2')?.textContent.trim() === 'Password').querySelector('p.form-hint');
            const privacy = [...document.querySelectorAll('section.card')].find((c) => c.querySelector('h2')?.textContent.trim() === 'Privacy').querySelector('p.form-hint');
            return [s(nota), s(privacy)];
        });
        check('il testo del riquadro Privacy come le note degli altri riquadri', stili[0] === stili[1], stili);
        // «Scarica i tuoi dati» (19/11): il pulsante come «Cambia password», con l'icona del vocabolario, e il file.
        const pulsanti = await st.page.evaluate(() => {
            const s = (e) => { const c = getComputedStyle(e); return [c.fontSize, c.color, c.fontFamily, c.borderTopColor].join(' / '); };
            const cambia = [...document.querySelectorAll('a.btn')].find((a) => a.textContent.trim() === 'Cambia password');
            const scarica = [...document.querySelectorAll('a.btn')].find((a) => a.textContent.trim() === 'Scarica i tuoi dati');
            return cambia && scarica ? [s(cambia), s(scarica)] : null;
        });
        check('«Scarica i tuoi dati» c\'e\' ed e\' come «Cambia password»', pulsanti !== null && pulsanti[0] === pulsanti[1], pulsanti || []);
        const [scaricato] = await Promise.all([
            st.page.waitForEvent('download'),
            sezione.locator('a', { hasText: 'Scarica i tuoi dati' }).click(),
        ]);
        const nomeFile = scaricato.suggestedFilename();
        const contenuto = fs.readFileSync(await scaricato.path());
        check('il file si chiama pistacchio-i-miei-dati-<data>.xlsx ed e\' uno zip', /^pistacchio-i-miei-dati-\d{4}-\d{2}-\d{2}\.xlsx$/.test(nomeFile)
            && contenuto.subarray(0, 2).toString() === 'PK', [nomeFile]);
        check('…e con «Chiedi la cancellazione dei tuoi dati»', await sezione.locator('a', { hasText: 'Chiedi la cancellazione dei tuoi dati' }).count() === 1);

        console.log('\n2. La pubblicazione');
        const esito = await pubblica(admin, TESTO);
        check('«Informativa pubblicata.»', esito.includes('Informativa pubblicata'), [esito]);
        const v1 = Number(valore('SELECT MAX(id) FROM privacy_versions'));
        const salvato = valore('SELECT content_html FROM privacy_versions WHERE id = ?', v1);
        check('il testo e\' sanificato: niente script, niente immagini', !salvato.includes('script') && !salvato.includes('<img'), [salvato.slice(-200)]);
        await anonimo.goto(BASE + '/privacy');
        check('la pagina ha il titolo «Informativa sulla privacy»', (await anonimo.locator('h1').innerText()) === 'Informativa sulla privacy');
        const oggi = new Date().toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
        check('e la data della versione', (await anonimo.locator('.privacy-versione').innerText()).includes(oggi));
        check('e il testo', await anonimo.locator('.privacy-contenuto h2').count() === 13);
        await pubblica(admin, '<p>Primo paragrafo.</p>' + TESTO, 'correggi');
        await anonimo.goto(BASE + '/privacy');
        const lettera = await anonimo.locator('.privacy-contenuto > p').first().evaluate((p) => [getComputedStyle(p, '::first-letter').color, getComputedStyle(p).color]);
        check('niente capolettera, come nelle lezioni', lettera[0] === lettera[1], lettera);

        console.log('\n3. La pagina /privacy');
        for (const [da, testo, dove] of [['registrazione', '← Torna alla registrazione', '/register'], ['accesso', '← Torna all’accesso', '/login'],
            ['email', 'Vai all’accesso', '/login']]) {
            await anonimo.goto(BASE + '/privacy?da=' + da);
            const btn = anonimo.locator('.privacy-barra .btn');
            check(`da=${da}: «${testo}» → ${dove}`, (await btn.innerText()).trim() === testo && (await btn.getAttribute('href')) === dove);
        }
        await anonimo.goto(BASE + '/privacy?da=' + encodeURIComponent('https://altrove.example'));
        check('una provenienza confezionata porta all\'accesso, non altrove', (await anonimo.locator('.privacy-barra .btn').getAttribute('href')) === '/login');
        await anonimo.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
        const barra = await anonimo.locator('.privacy-barra').boundingBox();
        check('scorrendo, la barra resta in cima', barra !== null && Math.abs(barra.y) < 1, [JSON.stringify(barra)]);
        await anonimo.locator('.privacy-barra .btn').click();
        await anonimo.waitForLoadState('networkidle');
        check('il pulsante riporta all\'accesso', new URL(anonimo.url()).pathname === '/login');

        const telefono = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
        await telefono.goto(BASE + '/privacy?da=email');
        const sporge = await telefono.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        check('al telefono niente scorrimento di lato', !sporge);
        check('al telefono resta il logo, il nome no', await telefono.locator('.privacy-marchio span').isHidden());

        await st.page.goto(BASE + '/privacy?da=profilo');
        check('da dentro, dal profilo: «← Torna al profilo»', (await st.page.locator('.privacy-barra .btn').innerText()).trim() === '← Torna al profilo');
        await st.page.goto(BASE + '/privacy');
        check('da dentro, senza provenienza: «← Torna a Pistacchio»', (await st.page.locator('.privacy-barra .btn').getAttribute('href')) === '/');
        const vecchia = await anonimo.goto(BASE + '/privacy?versione=' + v1);
        check('una versione per numero: non a chi non e\' Amministratore', vecchia.status() === 404);

        console.log('\n4. La registrazione');
        await anonimo.goto(BASE + '/register');
        check('la frase sotto «Registrati»', (await anonimo.locator('.nota-privacy').innerText()).includes('Registrandoti dichiari di aver preso visione'));
        await anonimo.fill('#first_name', 'Nuova');
        await anonimo.fill('#last_name', 'Privacy');
        await anonimo.fill('#email', NUOVO);
        await anonimo.fill('#password', 'Segreta123');
        await anonimo.click('.nota-privacy a');
        await anonimo.waitForLoadState('networkidle');
        check('apre /privacy nella stessa scheda', new URL(anonimo.url()).pathname === '/privacy');
        const salvatiNelBrowser = await anonimo.evaluate(() => sessionStorage.getItem('pistacchio.registrazione') || '');
        check('nel browser niente password', salvatiNelBrowser.includes(NUOVO) && !salvatiNelBrowser.includes('Segreta123'), [salvatiNelBrowser]);
        await anonimo.locator('.privacy-barra .btn').click();
        await anonimo.waitForLoadState('networkidle');
        check('«← Torna alla registrazione» riporta al modulo', new URL(anonimo.url()).pathname === '/register');
        check('nome, cognome ed email rimessi', await anonimo.inputValue('#first_name') === 'Nuova'
            && await anonimo.inputValue('#last_name') === 'Privacy' && await anonimo.inputValue('#email') === NUOVO);
        check('la password no', await anonimo.inputValue('#password') === '');
        await anonimo.fill('#password', 'Segreta123');
        await anonimo.fill('#password_confirm', 'Segreta123');
        await anonimo.click('form.auth-form button[type="submit"]');
        await anonimo.waitForLoadState('networkidle');
        check('registrazione inviata', anonimo.url().includes('/register/verifica-inviata'), [anonimo.url()]);
        check('i campi salvati nel browser se ne vanno', await anonimo.evaluate(() => sessionStorage.getItem('pistacchio.registrazione')) === null);
        check('la versione comunicata e\' registrata', Number(valore('SELECT privacy_version_id FROM users WHERE email = ?', NUOVO)) === v1);

        console.log('\n5. L\'accesso');
        await anonimo.goto(BASE + '/login');
        const link = anonimo.locator('.auth-privacy a');
        check('«Privacy» in fondo', (await link.innerText()).trim() === 'Privacy' && (await link.getAttribute('href')) === '/privacy?da=accesso');
        const stile = await link.evaluate((a) => [getComputedStyle(a).textDecorationLine, getComputedStyle(a).color,
            getComputedStyle(document.querySelector('.auth-subtitle')).color]);
        check('grigio e senza sottolineatura', stile[0] === 'none' && stile[1] === stile[2], stile);

        console.log('\n6. L\'avviso');
        await st.page.goto(BASE + '/');
        check('chi non l\'ha ricevuta vede l\'avviso sui corsi', await st.page.locator('.avviso-privacy').count() === 1);
        check('con il link alla nuova versione', (await st.page.locator('.avviso-privacy a').getAttribute('href')) === '/privacy?da=avviso');
        await st.page.goto(BASE + '/profilo');
        check('solo sulla pagina dei corsi', await st.page.locator('.avviso-privacy').count() === 0);
        await st.page.goto(BASE + '/');
        await st.page.click('.avviso-chiudi');
        await st.page.waitForLoadState('networkidle');
        check('la x lo chiude', await st.page.locator('.avviso-privacy').count() === 0 && new URL(st.page.url()).pathname === '/');
        check('e la versione e\' segnata', Number(valore('SELECT privacy_version_id FROM users WHERE id = ?', idStudente)) === v1);
        const corretta = await pubblica(admin, TESTO.replace('Il titolare', 'Il titolare (corretto)'), 'correggi');
        check('una correzione: «Nessun avviso agli studenti»', corretta.includes('Nessun avviso'), [corretta]);
        await st.page.goto(BASE + '/');
        check('…e l\'avviso non torna', await st.page.locator('.avviso-privacy').count() === 0);
        await anonimo.goto(BASE + '/privacy');
        check('…ma la pagina ha il testo corretto', (await anonimo.locator('.privacy-contenuto').innerText()).includes('(corretto)'));
        await pubblica(admin, TESTO.replace('Il titolare', 'Il titolare (versione 2)'));
        await st.page.goto(BASE + '/');
        check('una nuova versione: l\'avviso torna', await st.page.locator('.avviso-privacy').count() === 1);
        await admin.goto(BASE + '/admin/settings/privacy');
        check('Impostazioni › Privacy elenca la versione precedente', await admin.locator('a[href="/privacy?versione=' + v1 + '"]').count() === 1);
        await admin.goto(BASE + '/privacy?versione=' + v1);
        check('l\'Amministratore la apre, «non più in vigore»', (await admin.locator('.privacy-versione').innerText()).includes('non più in vigore')
            && (await admin.locator('.privacy-barra .btn').getAttribute('href')) === '/admin/settings/privacy');

        console.log('\n7. La cancellazione');
        const c1 = await entra(browser, DA_CANCELLARE);
        const c2 = await entra(browser, DA_CANCELLARE);
        await admin.goto(BASE + '/admin/settings');
        const contaPrima = Number((await admin.locator('a.nav-link[href="/admin/settings"] .nav-conteggio').allInnerTexts())[0]?.match(/\d+/)?.[0] || 0);
        await c1.page.goto(BASE + '/profilo');
        await c1.page.click('a[href="/profilo/cancellazione"]');
        await c1.page.waitForLoadState('networkidle');
        check('la pagina di conferma spiega e ricorda gli attestati', (await c1.page.locator('main').innerText()).includes('scaricali prima di confermare'));
        await c1.page.click('button.btn-pericolo');
        await c1.page.waitForLoadState('networkidle');
        check('si esce, e l\'accesso dice che la richiesta e\' arrivata', new URL(c1.page.url()).pathname === '/login'
            && (await c1.page.locator('.alert').innerText()).includes('Abbiamo ricevuto la tua richiesta'));
        check('richiesta registrata, account disattivato', valore('SELECT deletion_requested_at IS NOT NULL AND is_active = 0 FROM users WHERE id = ?', idCancella) === '1');
        await c2.page.goto(BASE + '/');
        check('l\'altra sessione esce, con lo stesso motivo', new URL(c2.page.url()).pathname === '/login'
            && (await c2.page.locator('.alert').innerText()).includes('Abbiamo ricevuto la tua richiesta'));
        await c2.page.fill('input[name="email"]', DA_CANCELLARE);
        await c2.page.fill('input[name="password"]', PASSWORD);
        await c2.page.click('form.auth-form button[type="submit"]');
        await c2.page.waitForLoadState('networkidle');
        check('rientrare: «Hai chiesto la cancellazione dei tuoi dati»', (await c2.page.locator('.alert-error').innerText()).includes('Hai chiesto la cancellazione'));

        await admin.goto(BASE + '/admin/settings');
        const contaDopo = Number((await admin.locator('a.nav-link[href="/admin/settings"] .nav-conteggio').allInnerTexts())[0]?.match(/\d+/)?.[0] || 0);
        check('il numero nel menu cresce di uno', contaDopo === contaPrima + 1, [contaPrima + ' → ' + contaDopo]);
        const riquadro = admin.locator('a.settings-card[href="/admin/settings/privacy"]');
        check('il riquadro Privacy ha il «!»', await riquadro.locator('.settings-card-avviso').count() === 1);
        await admin.goto(BASE + '/admin/settings/privacy');
        const riga = admin.locator('tr', { hasText: DA_CANCELLARE });
        check('la richiesta e\' elencata, con la data entro cui cancellare', await riga.count() === 1);

        const tutor = await entra(browser, 'tutor1@test.it');
        const vieta = await tutor.page.goto(BASE + '/profilo/cancellazione');
        check('lo staff non ha la cancellazione dal profilo', vieta.status() === 403);
        const vietaAdmin = await tutor.page.goto(BASE + '/admin/settings/privacy');
        check('e non apre Impostazioni › Privacy', vietaAdmin.status() === 403);

        await admin.goto(BASE + '/admin/users/' + idCancella + '/edit');
        check('la scheda dell\'utente dice della richiesta', (await admin.locator('main').innerText()).includes('Ha chiesto la cancellazione dei suoi dati'));
        await admin.check('input[name="is_active"]');
        await admin.locator('form', { has: admin.locator('input[name="is_active"]') }).locator('button[type="submit"]').first().click();
        await admin.waitForLoadState('networkidle');
        check('riattivato, la richiesta non vale piu\'', valore('SELECT deletion_requested_at IS NULL AND is_active = 1 FROM users WHERE id = ?', idCancella) === '1');

        esegui('UPDATE users SET deletion_requested_at = NOW(), is_active = 0 WHERE id = ?', idCancella);
        await admin.goto(BASE + '/admin/settings/privacy');
        await admin.locator('tr', { hasText: DA_CANCELLARE }).locator('button', { hasText: 'Cancella l’account' }).click();
        await admin.waitForLoadState('networkidle');
        check('«Cancella l\'account» lo toglie', valore('SELECT COUNT(*) FROM users WHERE id = ?', idCancella) === '0'
            && (await admin.locator('.alert').first().innerText()).includes('cancellato'));

        check('nessun errore JavaScript', errori.length === 0, errori);
    } catch (e) {
        check('il giro arriva in fondo', false, [e.stack || String(e)]);
    } finally {
        await browser.close();
        esegui('DELETE FROM users WHERE email IN (?, ?, ?)', STUDENTE, DA_CANCELLARE, NUOVO);
        if (parcheggioPieno()) {
            ripristina();
        }
        if (improntaPrima !== null) {
            const dopo = impronta();
            check('alla fine le versioni e le versioni comunicate sono tornate com\'erano', dopo === improntaPrima, [improntaPrima + ' → ' + dopo]);
        }
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail > 0 ? 1 : 0);
})();
