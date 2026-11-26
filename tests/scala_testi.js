/**
 * La scala dei testi (18/11, Alessandro: «Pistacchio deve essere
 * visivamente coerente, non un miscuglio di font e gerarchie a caso»).
 *
 * I livelli sono scritti in cima a `public/assets/css/style.css`. Qui si
 * aprono tutte le pagine delle Impostazioni e si misura ogni elemento per il
 * ruolo che ha, non per la pagina in cui sta: una pagina che si fa una
 * gerarchia sua fallisce.
 *
 * COSA VERIFICA, su ogni pagina:
 *
 *   1. un solo carattere, quello della pagina (il codice a parte, e in
 *      Aspetto l'anteprima dei caratteri e le voci di «Sul server», che
 *      mostrano ciascuna il proprio);
 *   2. titolo della pagina, titoli dei gruppi, titoli dei riquadri: ognuno
 *      alla sua misura e a 600;
 *   3. etichette e nomi delle opzioni alla misura delle etichette;
 *   4. note, «Ultima modifica» e descrizioni sotto un titolo: misura e
 *      colore delle note;
 *   5. messaggi «nessun…» alla misura delle note.
 *
 * E POI TUTTO PISTACCHIO: da anonimo, studente, tutor e Amministratore si
 * seguono i collegamenti di tutte le pagine (un indirizzo per forma:
 * /courses/1 e /courses/2, o la stessa tabella ordinata in un altro modo,
 * sono la stessa pagina) e su ognuna:
 *
 *   6. ogni testo e' a una delle sette misure della scala, tranne il testo
 *      dentro un disegno (iniziali, icone, nuvola delle Tre parole) e il
 *      codice;
 *   7. un solo carattere, quello della pagina;
 *   8. i titoli h1, h2, h3 a 600; le etichette a 500.
 *
 * E il piede della barra laterale: «Esci» sotto il nome, allineato, mai
 * attaccato, anche con un nome lunghissimo.
 *
 * Esecuzione:  node tests/scala_testi.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

// [ruolo, selettore, misura in rem, peso o null, «grigio» o null]
const RUOLI = [
    ['titolo della pagina', 'main h1', 1.5, '600', null],
    ['titolo di un gruppo', '.gruppo-titolo', 1.4, '600', null],
    ['titolo di un riquadro', '.card > h2:not(.coda-email-titolo), .aspetto-sommario h3, .email-scelta-testa h2', 1.2, '600', null],
    ['sottotitolo nel riquadro', '.card h3:not(.aspetto-sommario h3), .email-elenco h2, .coda-email-titolo', 1.05, '600', null],
    // Il nome di un elemento nei ritocchi di Aspetto (22/11, Alessandro).
    ['nome di un ritocco', '.ritocco-nome', 1, '600', null],
    ['etichetta', 'main label:not(.checkbox-label), .ruoli-interruttore-nome, .tavolozza-nome', 0.92, '500', null],
    ['nota', 'main .form-hint, .gruppo-nota, .ruoli-interruttore-cosa, .ruoli-sempre li', 0.92, '400', 'grigio'],
    // Il testo che apre un riquadro (25/11, Alessandro).
    ['testo introduttivo di un riquadro', '.riquadro-intro', 0.92, '400', 'grigio'],
    ['messaggio «nessun…»', '.empty-state, .empty-state-small', 0.92, '400', 'grigio'],
];

// Il testo dentro un disegno: fuori scala per natura.
// Le parole della nuvola (`li.tp-n1`…`tp-n6`) sono grandi quanto sono
// state scelte: fuori scala loro, non il «×3» che hanno accanto.
const FUORI = ['icon-btn', 'avatar', 'file-icon', 'group-logo', 'course-card-cover', 'video-start', 'ordina-freccia',
    'result-score', 'persona-foto', 'presentazione-chiudi', 'avviso-chiudi', 'settings-card-avviso',
    'scene-claim', 'gruppo-volto', 'riepilogo-fruizione'];
const PAROLA_DELLA_NUVOLA = /^(li|span)\.tp-n\d/;  // anche i campioni della legenda
const MISURE = [1.5, 1.4, 1.2, 1.05, 1, 0.92, 0.8];
const NON_SEGUIRE = /logout|\/join|\.csv|\.xlsx|\/download|\/scarica|\/file|\/immagine|\/materials\/\d+|\/pdf|\/calendario|\.ics|\/h5p|\/video|\/copertina|\/logo|mailto:|tel:/;
// Una pagina per forma: /courses/1 e /courses/2 sono la stessa, e cosi'
// la stessa pagina ordinata o filtrata in un altro modo.
const forma = (u) => u.split('#')[0].replace(/\d+/g, 'N').replace(/([?&](da_id|torna|da|ordina|verso|giorni|pagina|cerca|q)=)[^&]*/g, '$1x');

async function giroCompleto(browser) {
    for (const [ruolo, email] of [['anonimo', null], ['studente', 'stud@test.it'], ['tutor', 'tutor1@test.it'], ['admin', 'admin@test.it']]) {
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const page = await ctx.newPage();
        page.on('dialog', (d) => d.dismiss());
        if (email) {
            await page.goto(BASE + '/login');
            await page.fill('input[name="email"]', email);
            await page.fill('input[name="password"]', PASSWORD);
            await page.click('form.auth-form button[type="submit"]');
            await page.waitForLoadState('networkidle');
        }
        const coda = email ? ['/'] : ['/login', '/register', '/password/dimenticata', '/register/rinvia', '/privacy'];
        const viste = new Set();
        const sbagli = { misure: [], caratteri: [], pesi: [] };
        let n = 0;
        while (coda.length && n < 600) {
            const u = coda.shift();
            if (viste.has(forma(u))) continue;
            viste.add(forma(u));
            let r;
            try {
                r = await page.goto(BASE + u, { waitUntil: 'domcontentloaded', timeout: 15000 });
                await page.waitForTimeout(200);
            } catch (e) {
                continue;
            }
            if (!r || r.status() >= 400 || !(r.headers()['content-type'] || '').includes('text/html') || !page.url().startsWith(BASE)) continue;
            n++;
            const dove = page.url().replace(BASE, '');
            const esito = await page.evaluate(([FUORI, MISURE, nuvola]) => {
                const PAROLA_DELLA_NUVOLA = new RegExp(nuvola);
                const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
                const pagina = getComputedStyle(document.body).fontFamily;
                const out = { misure: [], caratteri: [], pesi: [] };
                for (const e of document.querySelectorAll('body *')) {
                    if (e.closest('svg, .tox, iframe, .sr-only, code, pre, script, style')) continue;
                    const ctrl = ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(e.tagName);
                    if (!ctrl && ![...e.childNodes].some((x) => x.nodeType === 3 && x.textContent.trim())) continue;
                    if (e.tagName === 'INPUT' && ['hidden', 'checkbox', 'radio', 'color', 'range'].includes(e.type)) continue;
                    if (e.getClientRects().length === 0) continue;
                    const nome = e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).join('.') : '');
                    const contesto = nome + ' ' + (e.parentElement && typeof e.parentElement.className === 'string' ? e.parentElement.className : '');
                    // Fuori scala anche dentro un contenitore con quei nomi (i
                    // numeri in evidenza del riepilogo stanno in un <dd> senza classe).
                    if (FUORI.some((f) => contesto.includes(f) || e.closest('[class*="' + f + '"]')) || PAROLA_DELLA_NUVOLA.test(nome)) continue;
                    const c = getComputedStyle(e);
                    const t = (e.innerText || e.value || '').trim().replace(/\s+/g, ' ').slice(0, 30);
                    if (!MISURE.some((m) => Math.abs(parseFloat(c.fontSize) - m * rem) < 0.05)) out.misure.push(nome + ' «' + t + '» ' + c.fontSize);
                    // L'anteprima dei caratteri e le voci di «Sul server» in
                    // Aspetto sono scritte apposta in un altro carattere (21/11).
                    if (c.fontFamily !== pagina && !e.closest('.carattere-anteprima, .caratteri-voce')) out.caratteri.push(nome + ' «' + t + '» in ' + c.fontFamily.split(',')[0]);
                    if (/^h[123]$/.test(e.tagName.toLowerCase()) && c.fontWeight !== '600') out.pesi.push(nome + ' «' + t + '» ' + c.fontWeight);
                    if (e.tagName === 'LABEL' && !e.closest('.campo-contato-testa, .tp-scelta, .checkbox-label') && !e.classList.contains('checkbox-label') && c.fontWeight !== '500') out.pesi.push(nome + ' «' + t + '» ' + c.fontWeight);
                }
                return out;
            }, [FUORI, MISURE, PAROLA_DELLA_NUVOLA.source]);
            for (const k of Object.keys(sbagli)) esito[k].forEach((x) => sbagli[k].push(dove + ': ' + x));
            const link = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')).filter((h) => h && h.startsWith('/')));
            link.filter((l) => !NON_SEGUIRE.test(l)).forEach((l) => coda.push(l));
        }
        console.log('\nTutto Pistacchio, da ' + ruolo + ' (' + n + ' pagine)');
        const unici = (a) => [...new Set(a)];
        check(ruolo + ': ogni testo a una misura della scala', sbagli.misure.length === 0, unici(sbagli.misure));
        check(ruolo + ': un solo carattere', sbagli.caratteri.length === 0, unici(sbagli.caratteri));
        check(ruolo + ': titoli a 600, etichette a 500', sbagli.pesi.length === 0, unici(sbagli.pesi));
        await ctx.close();
    }
}

/**
 * Il piede della barra laterale (18-19/11, Alessandro). Un nome corto sta
 * su una riga con «Esci» a destra, almeno 16 px fra i due; un nome lungo
 * manda «Esci» sotto, allineato al nome e senza coprirlo; in fondo, nei due
 * casi, la stessa distanza dal bordo della finestra. Con utenti veri, che la
 * prova crea e toglie: la scelta la fa la pagina quando si apre.
 */
async function piedeBarra(browser) {
    console.log('\nIl piede della barra laterale');
    const NOMI = [['Mario', 'Rossi', 1], ['Alessandro', 'Gilibini', 2],
        ['Compagna con un nome davvero', 'molto lungo Bartolomeo-Castiglioni', 2]];
    const email = (i) => 'piede-barra-' + i + '@test.it';
    const php = (codice, ...a) => execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php"; ' + codice, '--', ...a],
        { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const togli = () => NOMI.forEach((_, i) => php('App\\Core\\Database::connection()->prepare("DELETE FROM users WHERE email = ?")->execute([$argv[1]]);', email(i)));
    togli();
    NOMI.forEach(([n, c], i) => {
        php('App\\Models\\UserModel::create($argv[1], $argv[2], $argv[3], $argv[4]);', email(i), PASSWORD, n, c);
        php('App\\Core\\Database::connection()->prepare("UPDATE users SET name_display = \'full\', welcome_seen_at = NOW() WHERE email = ?")->execute([$argv[1]]);', email(i));
    });
    try {
        for (const [w, h] of [[1280, 800], [390, 844]]) {
            const fondi = [];
            for (const [i, [n, c, righe]] of NOMI.entries()) {
                const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
                await page.goto(BASE + '/login');
                await page.fill('input[name="email"]', email(i));
                await page.fill('input[name="password"]', PASSWORD);
                await page.click('form.auth-form button[type="submit"]');
                await page.waitForLoadState('networkidle');
                await page.evaluate(() => document.fonts.ready);
                const m = await page.evaluate(() => {
                    const piede = document.querySelector('.sidebar-footer');
                    const r = (s) => piede.querySelector(s).getBoundingClientRect();
                    const nome = piede.querySelector('.user-name');
                    return { nome: r('.user-name'), cerchio: r('.avatar'), esci: r('.link-btn'), piede: piede.getBoundingClientRect(),
                        intero: nome.scrollWidth <= nome.clientWidth, una: piede.classList.contains('una-riga'),
                        basso: piede.closest('.sidebar').getBoundingClientRect().bottom };
                });
                const chi = w + ' px, «' + (n + ' ' + c).slice(0, 20) + '»: ';
                const centro = (x) => (x.top + x.bottom) / 2;
                check(chi + 'il nome centrato sul cerchio', Math.abs(centro(m.nome) - centro(m.cerchio)) < 1.5);
                check(chi + 'il nome resta dentro la barra', m.nome.right <= m.piede.right + 0.5);
                check(chi + '«Esci» alto almeno 24 px', m.esci.height >= 24);
                if (righe === 1) {
                    check(chi + 'una riga sola', m.una && Math.abs(centro(m.esci) - centro(m.nome)) < 1.5);
                    check(chi + 'il nome intero, e almeno 16 px prima di «Esci»', m.intero && m.esci.left - m.nome.right >= 16, [JSON.stringify(m)]);
                } else {
                    check(chi + '«Esci» sotto il nome, senza coprirlo', !m.una && m.esci.top >= m.nome.bottom - 0.5);
                    check(chi + '«Esci» allineato al nome', Math.abs(m.esci.left - m.nome.left) < 0.5);
                }
                fondi.push(m.basso - Math.max(m.esci.bottom, m.cerchio.bottom));
                await page.context().close();
            }
            check(w + ' px: le due varianti alla stessa distanza dal fondo (' + fondi.map((x) => x.toFixed(1)).join(', ') + ' px)',
                Math.max(...fondi) - Math.min(...fondi) < 1);
        }
    } finally {
        togli();
    }
}

// La descrizione dei corsi alla stessa misura in «I miei corsi» e in
// «Esplora corsi» (23/11, Alessandro): nel catalogo era a quella delle note.
// Si misurano la descrizione corta, l'estratto di quella lunga e la
// descrizione intera aperta con «Leggi tutto».
async function descrizioneCorsi(browser) {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', 'stud@test.it');
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');
    const misure = (sel) => page.evaluate((s) => [...document.querySelectorAll(s)]
        .filter((e) => e.getClientRects().length > 0)
        .map((e) => getComputedStyle(e).fontSize), sel);

    await page.goto(BASE + '/');
    const miei = await misure('.course-card-excerpt');
    await page.goto(BASE + '/catalogo');
    await page.evaluate(() => document.querySelectorAll('.catalogo-leggi').forEach((d) => { d.open = true; }));
    const catalogo = await misure('.catalog-card > .card-meta.testo-breve, .catalogo-leggi[open] > .card-meta');
    await page.evaluate(() => document.querySelectorAll('.catalogo-leggi').forEach((d) => { d.open = false; }));
    catalogo.push(...await misure('.catalogo-estratto'));
    await page.close();

    const attesa = (0.92 * 16) + 'px';
    check('descrizione dei corsi: misurata in tutte e due le pagine', miei.length > 0 && catalogo.length >= 3, ['I miei corsi: ' + miei.length, 'Esplora corsi: ' + catalogo.length]);
    check('descrizione dei corsi: stessa misura in «I miei corsi» e in «Esplora corsi» (' + attesa + ')',
        [...miei, ...catalogo].every((m) => m === attesa), ['I miei corsi: ' + miei.join(', '), 'Esplora corsi: ' + catalogo.join(', ')]);
}

(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', 'admin@test.it');
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await page.goto(BASE + '/admin/settings');
    const pagine = ['/admin/settings', ...await page.$$eval('a.settings-card', (as) => as.map((a) => a.getAttribute('href'))),
        '/admin/settings/posta/coda', '/admin/settings/email/voce/segnaposto'];

    for (const url of pagine) {
        await page.goto(BASE + url);
        await page.waitForLoadState('networkidle');
        const nome = (await page.locator('main h1').first().innerText()).trim();
        console.log('\n' + nome + ' (' + url + ')');

        const r = await page.evaluate((RUOLI) => {
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
            const grigio = getComputedStyle(document.querySelector('.back-link-testo') || document.body).color;
            const visibile = (e) => e.getClientRects().length > 0 && !e.closest('.sr-only, .tox');
            const testo = (e) => (e.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40);
            const pagina = getComputedStyle(document.body).fontFamily;

            const caratteri = [...document.querySelectorAll('main *')]
                .filter((e) => visibile(e) && !e.closest('code, pre, .tox, .carattere-anteprima, .caratteri-voce') && [...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()))
                .filter((e) => getComputedStyle(e).fontFamily !== pagina)
                .map((e) => '«' + testo(e) + '» in ' + getComputedStyle(e).fontFamily.split(',')[0]);

            const ruoli = RUOLI.map(([ruolo, sel, misura, peso, colore]) => {
                const sbagliati = [...document.querySelectorAll(sel)].filter(visibile).filter((e) => {
                    const c = getComputedStyle(e);
                    return Math.abs(parseFloat(c.fontSize) - misura * rem) > 0.05
                        || (peso !== null && c.fontWeight !== peso)
                        || (colore === 'grigio' && c.color !== grigio);
                }).map((e) => { const c = getComputedStyle(e); return '«' + testo(e) + '» ' + c.fontSize + ' ' + c.fontWeight + ' ' + c.color; });
                return [ruolo, sbagliati];
            });

            // «Ultima modifica» e' una nota, scritta allo stesso modo.
            const ultime = [...document.querySelectorAll('main p')].filter((p) => /^Ultima modifica/.test(p.textContent.trim()))
                .filter((p) => !p.classList.contains('form-hint') || !/^Ultima modifica: /.test(p.textContent.trim().replace(/\s+/g, ' ')))
                .map((p) => '«' + testo(p) + '» ' + p.className);

            return { caratteri, ruoli, ultime };
        }, RUOLI);

        check(nome + ': un solo carattere', r.caratteri.length === 0, r.caratteri);
        for (const [ruolo, sbagliati] of r.ruoli) {
            check(nome + ': ' + ruolo + ' alla sua misura', sbagliati.length === 0, sbagliati);
        }
        check(nome + ': «Ultima modifica:» e\' una nota', r.ultime.length === 0, r.ultime);
    }

    await giroCompleto(browser);
    await piedeBarra(browser);
    await descrizioneCorsi(browser);

    await browser.close();
    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail > 0 ? 1 : 0);
})();
