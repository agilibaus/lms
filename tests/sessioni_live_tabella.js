/**
 * La tabella delle sessioni live dello staff (24/11, Alessandro, ripasso
 * del tutor). La vedono tutor, assistente, supervisore e Amministratore:
 * qui da Amministratore, che le vede tutte.
 *
 *   1. le colonne: Quando, Stato, Sessione, Corso, Gruppo, Link Meet,
 *      Accesso (era «Destinatari», con corso e gruppo nella stessa cella);
 *   2. «Stato» si ordina per significato: «In corso», «In programma»,
 *      «Conclusa», e nello stesso stato per data; al secondo clic al
 *      contrario;
 *   3. «Quando»: la data su una riga e l'orario sotto;
 *   4. «Corso»: il corso su una riga e il modulo sotto; «Gruppo»: il nome
 *      del gruppo, senza «Gruppo:»; dove manca, il trattino grigio;
 *   5. sul telefono le schede hanno le stesse voci, «Corso» e «Gruppo»;
 *   6. «Corso» si ordina per corso e poi per modulo, «Gruppo» per nome; le
 *      sessioni senza quel dato (il trattino) in fondo, in tutti e due i
 *      versi (24/11).
 *
 * Esecuzione:  node tests/sessioni_live_tabella.js   (server di sviluppo
 * acceso, semina dei permessi fatta)
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
        dettagli.slice(0, 10).forEach((d) => console.log('         · ' + d));
    }
}

const RANGO = { 'In corso': 0, 'In programma': 1, 'Conclusa': 2 };

async function righe(page) {
    return page.evaluate(() => {
        const intestazioni = [...document.querySelectorAll('.live-tabella thead th')].map((t) => (t.querySelector('a') ? t.querySelector('a').firstChild.textContent : t.textContent).trim());
        const indice = (n) => intestazioni.indexOf(n);
        const testoRighe = (td) => [...td.querySelectorAll('.cell-line')].map((l) => l.textContent.trim());
        return {
            intestazioni,
            righe: [...document.querySelectorAll('.live-tabella tbody tr')].map((tr) => {
                // Una colonna che non c'e' (il codice di prima) da' celle vuote, non un errore.
                const td = (n) => tr.children[indice(n)] || document.createElement('td');
                return {
                    quando: testoRighe(td('Quando')),
                    stato: td('Stato').textContent.trim(),
                    corso: td('Corso').querySelector('.valore-assente') ? '—' : testoRighe(td('Corso')),
                    gruppo: td('Gruppo').querySelector('.valore-assente') ? '—' : td('Gruppo').textContent.trim(),
                };
            }),
        };
    });
}

// «24/11/2026» e «10:12» → un numero confrontabile.
const istante = (r) => { const [g = '', m = '', a = ''] = (r.quando[0] || '').split('/'); return a + m + g + (r.quando[1] || '').slice(0, 5); };

(async () => {
    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        await page.goto(BASE + '/live');
        let t = await righe(page);
        check('le colonne: Quando, Stato, Sessione, Corso, Gruppo, Link Meet, Accesso',
            JSON.stringify(t.intestazioni.slice(0, 7)) === JSON.stringify(['Quando', 'Stato', 'Sessione', 'Corso', 'Gruppo', 'Link Meet', 'Accesso']), [JSON.stringify(t.intestazioni)]);
        check('ci sono sessioni da guardare, concluse e in programma', t.righe.length >= 3 && new Set(t.righe.map((r) => r.stato)).size >= 2, [JSON.stringify(t.righe.map((r) => r.stato))]);
        check('«Quando»: la data su una riga e l\'orario sotto',
            t.righe.every((r) => r.quando.length === 2 && /^\d{2}\/\d{2}\/\d{4}$/.test(r.quando[0]) && /^\d{2}:\d{2}–\d{2}:\d{2}$/.test(r.quando[1])), t.righe.map((r) => JSON.stringify(r.quando)));
        check('«Corso»: il corso e sotto il modulo, oppure il trattino',
            t.righe.every((r) => r.corso === '—' || (Array.isArray(r.corso) && r.corso.length === 2 && r.corso.every((x) => x !== '' && !x.includes(' · ')))), t.righe.map((r) => JSON.stringify(r.corso)));
        check('«Gruppo»: il nome senza «Gruppo:», oppure il trattino; e almeno un trattino',
            t.righe.every((r) => r.gruppo !== '' && !r.gruppo.startsWith('Gruppo:')) && t.righe.some((r) => r.gruppo === '—' || r.corso === '—'), t.righe.map((r) => r.gruppo));

        for (const verso of ['asc', 'desc']) {
            await page.goto(BASE + '/live?ordina=stato&verso=' + verso);
            t = await righe(page);
            const segno = verso === 'asc' ? 1 : -1;
            const storte = [];
            for (let i = 1; i < t.righe.length; i++) {
                const a = t.righe[i - 1];
                const b = t.righe[i];
                const d = segno * (RANGO[b.stato] - RANGO[a.stato]);
                if (d < 0 || (d === 0 && segno * istante(b).localeCompare(istante(a)) < 0)) {
                    storte.push(a.stato + ' ' + a.quando.join(' ') + ' prima di ' + b.stato + ' ' + b.quando.join(' '));
                }
            }
            const intest = await page.evaluate(() => ([...document.querySelectorAll('.live-tabella thead th')].find((x) => x.textContent.includes('Stato')) || { getAttribute: () => null }).getAttribute('aria-sort'));
            check('«Stato» ' + (verso === 'asc' ? 'crescente: In corso, In programma, Conclusa' : 'decrescente: al contrario') + ', nello stesso stato per data',
                storte.length === 0 && intest === (verso === 'asc' ? 'ascending' : 'descending'), [...storte, 'aria-sort: ' + intest]);
        }

        const confronta = (x, y) => x.localeCompare(y, 'it', { sensitivity: 'base' });
        for (const [colonna, valore] of [['corso', (r) => r.corso === '—' ? null : r.corso.join(' / ')], ['gruppo', (r) => r.gruppo === '—' ? null : r.gruppo]]) {
            for (const verso of ['asc', 'desc']) {
                await page.goto(BASE + '/live?ordina=' + colonna + '&verso=' + verso);
                t = await righe(page);
                const valori = t.righe.map(valore);
                const pieni = valori.filter((v) => v !== null);
                const primoVuoto = valori.indexOf(null);
                const inFondo = primoVuoto === -1 || valori.slice(primoVuoto).every((v) => v === null);
                const inOrdine = pieni.every((v, i) => i === 0 || (verso === 'asc' ? 1 : -1) * confronta(pieni[i - 1], v) <= 0);
                const intest = await page.evaluate((c) => ([...document.querySelectorAll('.live-tabella thead th')].find((x) => x.textContent.trim().startsWith(c)) || { getAttribute: () => null }).getAttribute('aria-sort'), colonna === 'corso' ? 'Corso' : 'Gruppo');
                check('«' + (colonna === 'corso' ? 'Corso' : 'Gruppo') + '» ' + (verso === 'asc' ? 'crescente' : 'decrescente') + ', con il trattino in fondo',
                    inOrdine && inFondo && pieni.length >= 2 && intest === (verso === 'asc' ? 'ascending' : 'descending'),
                    [...valori.map((v) => v ?? '—'), 'aria-sort: ' + intest]);
            }
        }

        const tel = await (await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: await page.context().storageState() })).newPage();
        await tel.goto(BASE + '/live');
        const voci = await tel.evaluate(() => [...new Set([...document.querySelectorAll('.live-schede td[data-label]')].map((td) => td.dataset.label))]);
        check('sul telefono le schede hanno «Corso» e «Gruppo», non «Destinatari»',
            voci.includes('Corso') && voci.includes('Gruppo') && !voci.includes('Destinatari'), [JSON.stringify(voci)]);
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
