/**
 * Il passaggio del mouse sui collegamenti nelle frasi (22/11, Alessandro).
 *
 * Sottolineatura sempre da 1 px; al passaggio, e con il fuoco della
 * tastiera, il colore si scurisce in modo che si veda (il colore principale
 * con il 35% di nero) e non cambia lo spessore: a 2 px la linea era piu'
 * spessa dei tratti delle lettere. Il primo tentativo, il verde scuro dei
 * pulsanti, non si notava: il controllo chiede fra i due colori almeno
 * 1,5:1 di contrasto.
 * Prova «Password dimenticata?» e «Registrati» della pagina di accesso.
 *
 * Esecuzione:  node tests/collegamenti_passaggio.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.forEach((d) => console.log('         · ' + d));
    }
}

(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();

    try {
        await page.goto(BASE + '/login');
        const colori = await page.evaluate(() => {
            const prova = document.createElement('span');
            document.body.appendChild(prova);
            prova.style.color = 'var(--color-primary)';
            const c = { principale: getComputedStyle(prova).color };
            prova.remove();
            return c;
        });
        // La luminanza relativa (WCAG) da «rgb(r, g, b)» o «color(srgb r g b)».
        const luminanza = (css) => {
            const n = css.match(/[\d.]+/g).slice(0, 3).map(Number);
            const v = css.startsWith('color(') ? n : n.map((x) => x / 255);
            const l = v.map((x) => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4));
            return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
        };
        const distacco = (a, b) => {
            const [x, y] = [luminanza(a), luminanza(b)].sort((p, q) => q - p);
            return (x + 0.05) / (y + 0.05);
        };
        const stile = (testo) => page.locator(`a:has-text("${testo}")`).evaluate((e) => {
            const s = getComputedStyle(e);
            return { linea: s.textDecorationLine, spessore: s.textDecorationThickness, colore: s.color };
        });

        for (const testo of ['Password dimenticata', 'Registrati']) {
            const prima = await stile(testo);
            await page.locator(`a:has-text("${testo}")`).hover();
            await page.waitForTimeout(100);
            const sopra = await stile(testo);
            await page.mouse.move(1, 1);
            await page.locator(`a:has-text("${testo}")`).focus();
            await page.keyboard.press('Shift+Tab');
            await page.keyboard.press('Tab');
            const fuoco = await stile(testo);
            check(`«${testo}»: a riposo verde, sottolineato da 1 px`,
                prima.linea === 'underline' && prima.spessore === '1px' && prima.colore === colori.principale, [JSON.stringify(prima)]);
            check(`«${testo}»: al passaggio e con il fuoco si scurisce in modo che si veda (almeno 1,5:1), lo spessore no`,
                [sopra, fuoco].every((s) => s.linea === 'underline' && s.spessore === '1px'
                    && luminanza(s.colore) < luminanza(colori.principale) && distacco(s.colore, colori.principale) >= 1.5),
                [JSON.stringify({ sopra, fuoco, colori, distacco: distacco(sopra.colore, colori.principale).toFixed(2) })]);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
