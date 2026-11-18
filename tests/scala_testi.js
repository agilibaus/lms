/**
 * La scala dei testi nelle Impostazioni (18/11, Alessandro: «Pistacchio deve
 * essere visivamente coerente, non un miscuglio di font e gerarchie a caso»).
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
 *   5. messaggi «nessun…» a una misura sola.
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
    ['messaggio «nessun…»', '.empty-state, .empty-state-small', 0.9, '400', 'grigio'],
];

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

    await browser.close();
    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail > 0 ? 1 : 0);
})();
