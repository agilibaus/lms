/**
 * Gli allineamenti delle tabelle dei report (23/11, Alessandro: «se passo
 * da un report a un altro non voglio trovare allineamenti casuali»).
 *
 * Da Amministratore si aprono tutte le pagine dei report, seguendo i loro
 * collegamenti (anche la fruizione di una lezione), e in ogni tabella si
 * misura ogni colonna:
 *
 *   1. una colonna di numeri (conteggi, frazioni come 0/4, «0 su 3»,
 *      percentuali, durate, minuti, giorni) e' a destra, intestazione
 *      compresa;
 *   2. una colonna a destra contiene numeri: niente testo a destra per caso;
 *   3. l'intestazione e' allineata come le sue celle;
 *   4. la colonna dei comandi e' l'ultima ed e' a destra.
 *
 * La griglia del corso fa eccezione: e' una mappa di stati, centrata.
 * Le tabelle si misurano a 1600 px, dove sono tabelle e non schede.
 *
 * Esecuzione:  node tests/report_colonne.js   (server di sviluppo acceso,
 * semina dei permessi fatta)
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
        dettagli.slice(0, 12).forEach((d) => console.log('         · ' + d));
    }
}

// Le pagine per forma: /reports/courses/1 e /reports/courses/2 sono la
// stessa, e cosi' la stessa pagina ordinata o filtrata in un altro modo. Se
// ne aprono fino a quattro per forma (e mai lo stesso indirizzo due volte):
// con una sola, uno studente senza questionari nasconderebbe le colonne
// dei questionari.
const PER_FORMA = 4;
const forma = (u) => u.split('#')[0].split('?')[0].replace(/\d+/g, 'N');
const DA_SEGUIRE = /^\/(reports|lessons\/\d+\/fruizione)/;
const NON_SEGUIRE = /csv|xlsx|pdf|scarica|download/;

(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1600, height: 1000 } })).newPage();
    const sbagli = { numeri: [], testo: [], intestazioni: [], comandi: [] };
    const viste = new Set();
    const aperte = new Set();
    const conteggio = {};
    let tabelle = 0;

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        const coda = ['/reports'];
        while (coda.length > 0) {
            const indirizzo = coda.shift();
            const esatto = indirizzo.split('#')[0].split('?')[0];
            const f = forma(indirizzo);
            if (aperte.has(esatto) || (conteggio[f] || 0) >= PER_FORMA) {
                continue;
            }
            aperte.add(esatto);
            conteggio[f] = (conteggio[f] || 0) + 1;
            viste.add(f);
            await page.goto(BASE + indirizzo);

            const esito = await page.evaluate(() => {
                const NUMERO = /^(completato\s+)?(\d+([.,]\d+)?\s*(%|min|giorn[oi])?|\d+\s*\/\s*\d+|\d+ su \d+|\d+:\d{2}|in orario|non iscritto)$/;
                const VUOTO = /^(—|-|)$/;
                const out = { colonne: [], link: [] };
                document.querySelectorAll('table').forEach((t, ti) => {
                    if (t.closest('.griglia') || t.classList.contains('griglia')) {
                        return;
                    }
                    const intestazioni = [...t.querySelectorAll('thead tr:last-child th')];
                    const righe = [...t.querySelectorAll('tbody tr')];
                    intestazioni.forEach((th, i) => {
                        const celle = righe.map((r) => r.children[i]).filter(Boolean);
                        const testi = celle.map((c) => c.innerText.trim().replace(/\s+/g, ' ')).filter((x) => !VUOTO.test(x));
                        const comandi = celle.some((c) => c.classList.contains('row-actions'));
                        out.colonne.push({
                            tabella: ti,
                            nome: (th.innerText.trim().split('\n')[0] || (comandi ? 'Azioni' : '?')).replace(/\s*:\s*ordina.*$/i, ''),
                            ultima: i === intestazioni.length - 1,
                            // null: solo «—», niente da classificare
                            numeri: testi.length === 0 ? null : testi.every((x) => NUMERO.test(x)),
                            comandi,
                            th: getComputedStyle(th).textAlign,
                            td: celle.length ? getComputedStyle(celle[0]).textAlign : null,
                            esempio: testi[0] || '',
                        });
                    });
                });
                out.link = [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href'));
                return out;
            });

            const pagina = forma(indirizzo);
            tabelle += new Set(esito.colonne.map((c) => c.tabella)).size;
            for (const c of esito.colonne) {
                const dove = `${pagina} · ${c.nome}`;
                if (c.td === null) {
                    continue; // tabella vuota: nessuna cella da misurare
                }
                if (c.comandi) {
                    if (!c.ultima || c.td !== 'right' || c.th !== 'right') sbagli.comandi.push(`${dove}: ${c.ultima ? '' : 'non ultima, '}celle ${c.td}, intestazione ${c.th}`);
                    continue;
                }
                if (c.numeri === true && c.td !== 'right') sbagli.numeri.push(`${dove}: «${c.esempio}» a ${c.td}`);
                if (c.numeri === false && c.td === 'right') sbagli.testo.push(`${dove}: «${c.esempio}» a destra`);
                if (c.th !== c.td) sbagli.intestazioni.push(`${dove}: intestazione ${c.th}, celle ${c.td}`);
            }
            esito.link.filter((l) => DA_SEGUIRE.test(l) && !NON_SEGUIRE.test(l)).forEach((l) => coda.push(l));
        }

        console.log(`  --   ${aperte.size} pagine di ${viste.size} tipi, ${tabelle} tabelle`);
        // Un errore ripetuto su piu' pagine della stessa forma si dice una volta.
        for (const k of Object.keys(sbagli)) sbagli[k] = [...new Set(sbagli[k])];
        check('le colonne di numeri sono a destra', sbagli.numeri.length === 0, sbagli.numeri);
        check('a destra ci sono solo numeri', sbagli.testo.length === 0, sbagli.testo);
        check('ogni intestazione è allineata come le sue celle', sbagli.intestazioni.length === 0, sbagli.intestazioni);
        check('i comandi stanno nell\'ultima colonna, a destra', sbagli.comandi.length === 0, sbagli.comandi);
        check('le pagine di dettaglio ci sono tutte', ['/reports/courses/N', '/reports/groups/N', '/reports/students/N', '/reports/live/N', '/reports/fruizione/N', '/reports/fermi', '/lessons/N/fruizione'].every((p) => viste.has(p)), [...viste]);
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
