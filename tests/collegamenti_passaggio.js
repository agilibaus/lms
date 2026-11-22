/**
 * Il passaggio del mouse sui collegamenti nelle frasi (22/11, Alessandro).
 *
 * Sottolineatura sempre da 1 px; al passaggio, e con il fuoco della
 * tastiera, cambia il colore (il verde scuro della tavolozza) e non lo
 * spessore: a 2 px la linea era piu' spessa dei tratti delle lettere.
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
            const leggi = (v) => { prova.style.color = `var(${v})`; return getComputedStyle(prova).color; };
            const c = { principale: leggi('--color-primary'), scuro: leggi('--color-primary-hover') };
            prova.remove();
            return c;
        });
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
            check(`«${testo}»: al passaggio e con il fuoco cambia il colore, non lo spessore`,
                [sopra, fuoco].every((s) => s.linea === 'underline' && s.spessore === '1px' && s.colore === colori.scuro)
                    && colori.scuro !== colori.principale, [JSON.stringify({ sopra, fuoco, colori })]);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
