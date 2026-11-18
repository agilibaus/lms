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
 *   1. un solo carattere, quello della pagina (il codice a parte);
 *   2. titolo della pagina, titoli dei gruppi, titoli dei riquadri: ognuno
 *      alla sua misura e a 600;
 *   3. etichette e nomi delle opzioni alla misura delle etichette;
 *   4. note, «Ultima modifica» e descrizioni sotto un titolo: misura e
 *      colore delle note;
 *   5. messaggi «nessun…» alla misura delle note.
 *
 * E POI TUTTO PISTACCHIO: da anonimo, studente, tutor e Amministratore si
 * seguono i collegamenti delle pagine (fino a 140 per ruolo, un indirizzo
 * per forma: /courses/1 e /courses/2 sono la stessa pagina) e su ognuna:
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
    ['sottotitolo nel riquadro', '.card h3:not(.aspetto-sommario h3), .email-elenco h2, .coda-email-titolo, .ritocco-nome', 1.05, '600', null],
    ['etichetta', 'main label:not(.checkbox-label), .ruoli-interruttore-nome, .tavolozza-nome', 0.92, '500', null],
    ['nota', 'main .form-hint, .gruppo-nota, .ruoli-interruttore-cosa', 0.92, '400', 'grigio'],
    ['messaggio «nessun…»', '.empty-state, .empty-state-small', 0.92, '400', 'grigio'],
];

// Il testo dentro un disegno: fuori scala per natura.
const FUORI = ['icon-btn', 'avatar', 'file-icon', 'group-logo', 'course-card-cover', 'video-start', 'ordina-freccia',
    'result-score', 'tp-n', 'tp-volte', 'persona-foto', 'presentazione-chiudi', 'avviso-chiudi', 'settings-card-avviso',
    'scene-claim', 'griglia-numero', 'gruppo-volto', 'riepilogo-fruizione'];
const MISURE = [1.5, 1.4, 1.2, 1.05, 1, 0.92, 0.8];
const NON_SEGUIRE = /logout|\/join|\.csv|\.xlsx|\/download|\/scarica|\/file|\/immagine|\/materials\/\d+|\/pdf|\/calendario|\.ics|\/h5p|\/video|\/copertina|\/logo|mailto:|tel:/;
const forma = (u) => u.split('#')[0].replace(/\d+/g, 'N').replace(/([?&](da_id|torna|da)=)[^&]*/g, '$1x');

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
        while (coda.length && n < 140) {
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
            const esito = await page.evaluate(([FUORI, MISURE]) => {
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
                    if (FUORI.some((f) => contesto.includes(f))) continue;
                    const c = getComputedStyle(e);
                    const t = (e.innerText || e.value || '').trim().replace(/\s+/g, ' ').slice(0, 30);
                    if (!MISURE.some((m) => Math.abs(parseFloat(c.fontSize) - m * rem) < 0.05)) out.misure.push(nome + ' «' + t + '» ' + c.fontSize);
                    if (c.fontFamily !== pagina) out.caratteri.push(nome + ' «' + t + '» in ' + c.fontFamily.split(',')[0]);
                    if (/^h[123]$/.test(e.tagName.toLowerCase()) && c.fontWeight !== '600' && !contesto.includes('tp-sottotitolo')) out.pesi.push(nome + ' «' + t + '» ' + c.fontWeight);
                    if (e.tagName === 'LABEL' && !e.closest('.campo-contato-testa, .tp-scelta, .checkbox-label') && !e.classList.contains('checkbox-label') && c.fontWeight !== '500') out.pesi.push(nome + ' «' + t + '» ' + c.fontWeight);
                }
                return out;
            }, [FUORI, MISURE]);
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
 * Il piede della barra laterale (18/11, Alessandro): nome accanto al
 * cerchio, «Esci» sotto, allineato al nome e mai attaccato. Con il nome
 * corto e con uno lunghissimo, al computer e al telefono.
 */
async function piedeBarra(browser) {
    console.log('\nIl piede della barra laterale');
    for (const [w, h] of [[1280, 800], [390, 844]]) {
        const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'stud@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');
        for (const nome of ['Alessandro Gilibini', 'Compagna con un nome davvero molto lungo Bartolomeo-Castiglioni']) {
            const m = await page.evaluate((nome) => {
                const piede = document.querySelector('.sidebar-footer');
                piede.closest('.sidebar').style.transform = 'none';
                piede.querySelector('.user-name').textContent = nome;
                const r = (s) => piede.querySelector(s).getBoundingClientRect();
                return { nome: r('.user-name'), cerchio: r('.avatar'), esci: r('.link-btn'), piede: piede.getBoundingClientRect() };
            }, nome);
            const centro = (x) => (x.top + x.bottom) / 2;
            check(w + ' px, «' + nome.slice(0, 20) + '»: il nome centrato sul cerchio', Math.abs(centro(m.nome) - centro(m.cerchio)) < 1.5);
            check(w + ' px, «' + nome.slice(0, 20) + '»: «Esci» sotto il nome, senza coprirlo', m.esci.top >= m.nome.bottom - 0.5);
            check(w + ' px, «' + nome.slice(0, 20) + '»: «Esci» allineato al nome', Math.abs(m.esci.left - m.nome.left) < 0.5);
            check(w + ' px, «' + nome.slice(0, 20) + '»: «Esci» alto almeno 24 px', m.esci.height >= 24);
            check(w + ' px, «' + nome.slice(0, 20) + '»: il nome resta dentro la barra', m.nome.right <= m.piede.right + 0.5);
        }
    }
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
                .filter((e) => visibile(e) && !e.closest('code, pre, .tox') && [...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()))
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

    await browser.close();
    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail > 0 ? 1 : 0);
})();
