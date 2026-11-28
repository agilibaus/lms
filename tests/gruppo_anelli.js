/**
 * La pagina del gruppo con molti partecipanti: gli anelli (27/11, Alessandro,
 * variante A del mockup).
 *
 * Oltre 20 partecipanti, fino a 50, due o tre anelli concentrici attorno al
 * tutor (`GroupCircle::anelli`), con foto di 58 o 48 px e nomi a 0,92 rem
 * sotto la foto. Per 21, 30, 40 e 50 partecipanti, visti dall'Amministratore e
 * dallo studente del gruppo (che in cima al riquadro ha «Gli altri ti vedono
 * come …»), su un computer di 1280 px con la barra laterale aperta:
 *  - gli anelli sono accesi, nel numero atteso;
 *  - nessun nome tocca un altro nome o una foto, e nessuna foto un'altra:
 *    del nome contano le righe di testo, non la scatola;
 *  - tutto sta nel riquadro bianco.
 * Dove lo spazio non basta (1024 px; con tre anelli anche 1200 px) resta la
 * griglia; con 51 la griglia anche a 1280; con 20 il cerchio di sempre.
 *
 * Esecuzione:  node tests/gruppo_anelli.js   (server di sviluppo acceso; crea
 * il gruppo con tests/gruppo_anelli_semina.php e lo toglie alla fine)
 */
'use strict';

const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

const semina = (...a) => execFileSync('php', ['tests/gruppo_anelli_semina.php', ...a], { cwd: RADICE }).toString().trim();

/** Come appare la pagina: griglia, cerchio o anelli, e che cosa si tocca. */
const misura = (page) => page.evaluate(() => {
    const lista = document.querySelector('.gruppo-cerchio');
    const riquadro = document.querySelector('.gruppo-persone').getBoundingClientRect();
    const voci = [...lista.querySelectorAll('li.persona')];
    // Del nome contano le righe di testo visibili, non la scatola larga ai
    // lati ne' il testo per i lettori di schermo.
    const righe = (e) => {
        const out = [];
        const w = document.createTreeWalker(e, NodeFilter.SHOW_TEXT);
        for (let t = w.nextNode(); t; t = w.nextNode()) {
            if (t.parentElement.closest('.sr-only')) continue;
            const r = document.createRange();
            r.selectNodeContents(t);
            out.push(...[...r.getClientRects()].filter((q) => q.width > 2 && q.height > 2));
        }
        return out;
    };
    const pezzi = voci.map((li) => ({
        chi: li.querySelector('.persona-nome').textContent.replace(/\s+/g, ' ').trim().slice(0, 30),
        rett: [li.querySelector('.persona-foto').getBoundingClientRect(), ...righe(li.querySelector('.persona-nome'))],
    }));
    const tocca = (A, B) => A.some((a) => B.some((b) => a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1));
    const tocchi = [];
    for (let i = 0; i < pezzi.length; i++) {
        for (let j = i + 1; j < pezzi.length; j++) {
            if (tocca(pezzi[i].rett, pezzi[j].rett)) tocchi.push(pezzi[i].chi + ' × ' + pezzi[j].chi);
        }
    }
    const fuori = pezzi.filter((p) => p.rett.some((r) => r.left < riquadro.left || r.right > riquadro.right || r.top < riquadro.top || r.bottom > riquadro.bottom))
        .map((p) => p.chi);
    const studente = voci.find((li) => !li.classList.contains('persona-tutor'));
    return {
        disegno: getComputedStyle(lista).display === 'grid' ? 'griglia'
            : lista.classList.contains('usa-anelli') ? 'anelli-' + (lista.classList.contains('anelli-3') ? 3 : 2) : 'cerchio',
        persone: voci.length,
        foto: Math.round(studente.querySelector('.persona-foto').getBoundingClientRect().width),
        nome: parseFloat(getComputedStyle(studente.querySelector('.persona-nome')).fontSize) / parseFloat(getComputedStyle(document.documentElement).fontSize),
        tocchi,
        fuori,
        comeTiVedono: document.querySelector('.gruppo-persone > .gruppo-come-ti-vedono:first-child')?.textContent.trim() ?? null,
    };
});

(async () => {
    const browser = await chromium.launch();
    const entra = async (email) => {
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const page = await ctx.newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
        return page;
    };

    try {
        const admin = await entra('admin@test.it');
        const studente = await entra('stud@test.it');

        for (const [n, anelli, foto] of [[21, 2, 58], [30, 2, 58], [40, 3, 48], [50, 3, 48]]) {
            console.log(`--- ${n} partecipanti`);
            const gruppo = semina('crea', String(n));
            for (const [chi, page] of [['Amministratore', admin], ['studente del gruppo', studente]]) {
                await page.setViewportSize({ width: 1280, height: 900 });
                await page.goto(BASE + '/gruppi/' + gruppo);
                const m = await misura(page);
                check(`${n}, ${chi}: ${anelli} anelli, foto di ${foto} px, nomi a 0,92 rem`,
                    m.disegno === 'anelli-' + anelli && m.persone === n + 1 && m.foto === foto && Math.abs(m.nome - 0.92) < 0.001,
                    [JSON.stringify({ disegno: m.disegno, persone: m.persone, foto: m.foto, nome: m.nome })]);
                check(`${n}, ${chi}: nessun nome tocca un altro nome o una foto`, m.tocchi.length === 0, m.tocchi);
                check(`${n}, ${chi}: tutto dentro il riquadro`, m.fuori.length === 0, m.fuori);
                if (chi !== 'Amministratore') {
                    check(`${n}, ${chi}: «Gli altri ti vedono come …» in cima al riquadro`,
                        m.comeTiVedono === 'Gli altri ti vedono come «Studente Prova».', [String(m.comeTiVedono)]);
                }
            }
            for (const larghezza of anelli === 3 ? [1024, 1200] : [1024]) {
                await admin.setViewportSize({ width: larghezza, height: 900 });
                await admin.goto(BASE + '/gruppi/' + gruppo);
                const m = await misura(admin);
                check(`${n}, a ${larghezza} px lo spazio non basta: la griglia`, m.disegno === 'griglia', [m.disegno]);
            }
            await admin.setViewportSize({ width: 1280, height: 900 });
        }

        console.log('--- oltre e prima');
        let gruppo = semina('crea', '51');
        await admin.goto(BASE + '/gruppi/' + gruppo);
        let m = await misura(admin);
        check('51 partecipanti: la griglia, anche a 1280 px', m.disegno === 'griglia', [m.disegno]);
        gruppo = semina('crea', '20');
        await admin.goto(BASE + '/gruppi/' + gruppo);
        m = await misura(admin);
        check('20 partecipanti: il cerchio di sempre, con le foto di 70 px', m.disegno === 'cerchio' && m.foto === 70, [m.disegno, String(m.foto)]);
    } finally {
        await browser.close();
        semina('togli');
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
