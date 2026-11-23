/**
 * La pagina d'errore (23/11, Alessandro): un indirizzo sbagliato, un accesso
 * negato o una sessione scaduta mostravano una riga di testo su una pagina
 * bianca. Ora c'e' Pistacchio intorno, un titolo che dice che cosa e'
 * successo, la frase del caso e il pulsante per tornare. Vedi
 * `App\Core\Errore`.
 *
 * COSA VERIFICA:
 *
 *   1. senza accesso, un indirizzo che non esiste: codice 404, la cornice
 *      delle pagine pubbliche, «Pagina non trovata» come titolo della
 *      pagina e della scheda, la frase, «Vai all'accesso»;
 *   2. dopo l'accesso, lo stesso indirizzo: la barra laterale, il titolo,
 *      la frase e «Torna alla pagina iniziale», che porta a «I miei corsi»;
 *   3. uno studente su una pagina dell'Amministratore: 403, «Accesso
 *      negato» e la frase senza ripeterlo («Permessi insufficienti…»);
 *   4. un corso che non esiste: 404 con la frase del caso, «Corso non
 *      trovato.»;
 *   5. un modulo inviato senza il token: 419, «Sessione scaduta»;
 *   6. sul telefono la pagina sta nello schermo;
 *   7. cio' che non e' una pagina resta com'era: un'immagine che non c'e' e
 *      una chiamata da JavaScript ricevono la frase sola, in testo semplice.
 *
 * Esecuzione:  node tests/pagine_errore.js   (server di sviluppo acceso,
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
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

async function accedi(page, email) {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('load');
}

async function leggi(page) {
    return page.evaluate(() => ({
        scheda: document.title,
        h1: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()).filter((t) => t !== 'Pistacchio LMS'),
        frase: (document.querySelector('main .card p, .alert-error') || {}).textContent?.trim() || '',
        pulsante: [...document.querySelectorAll('a.btn')].map((a) => [a.textContent.trim(), a.getAttribute('href')]),
        barra: !!document.querySelector('.sidebar, nav[aria-label]'),
        cornice: !!document.querySelector('.auth-body-split, .auth-body, .auth-card, .auth-split'),
    }));
}

const NON_TROVATA = 'Questo indirizzo non corrisponde a nessuna pagina di Pistacchio: forse è scritto male, oppure la pagina è stata spostata o tolta.';

(async () => {
    const browser = await chromium.launch();
    try {
        console.log('\nSenza accesso');
        let ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        let page = await ctx.newPage();
        let r = await page.goto(BASE + '/indirizzo-che-non-esiste');
        let p = await leggi(page);
        check('404', r.status() === 404, [r.status()]);
        check('la cornice delle pagine pubbliche', p.cornice && !p.barra, [JSON.stringify(p)]);
        check('«Pagina non trovata» nella scheda e nel titolo', p.scheda === 'Pagina non trovata · Pistacchio LMS' && p.h1.includes('Pagina non trovata'), [p.scheda, p.h1.join(' | ')]);
        check('la frase', p.frase === NON_TROVATA, [p.frase]);
        check('«Vai all\'accesso»', p.pulsante.some(([t, h]) => t === 'Vai all\'accesso' && h === '/login'), [JSON.stringify(p.pulsante)]);

        // 7. Una chiamata da JavaScript: la frase sola.
        const js = await page.evaluate(async (u) => { const x = await fetch(u); return [x.status, x.headers.get('content-type'), await x.text()]; }, BASE + '/indirizzo-che-non-esiste');
        check('una chiamata da JavaScript: la frase sola, in testo semplice', js[0] === 404 && !js[2].includes('<html') && js[2] === 'Pagina non trovata.', [JSON.stringify(js).slice(0, 160)]);
        await ctx.close();

        console.log('\nDopo l\'accesso (studente)');
        ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        page = await ctx.newPage();
        await accedi(page, 'stud@test.it');
        r = await page.goto(BASE + '/indirizzo-che-non-esiste');
        p = await leggi(page);
        check('404', r.status() === 404, [r.status()]);
        check('dentro l\'applicazione, con la barra laterale', p.barra && !p.cornice, [JSON.stringify(p)]);
        check('«Pagina non trovata» nella scheda e nel titolo', p.scheda === 'Pagina non trovata · Pistacchio LMS' && p.h1.length === 1 && p.h1[0] === 'Pagina non trovata', [p.scheda, p.h1.join(' | ')]);
        check('la frase', p.frase === NON_TROVATA, [p.frase]);
        check('«Torna alla pagina iniziale»', p.pulsante.length === 1 && p.pulsante[0][0] === 'Torna alla pagina iniziale' && p.pulsante[0][1] === '/', [JSON.stringify(p.pulsante)]);
        const torna = page.locator('a.btn', { hasText: 'Torna alla pagina iniziale' });
        if (await torna.count() > 0) {
            await Promise.all([page.waitForNavigation(), torna.first().click()]);
        }
        check('che porta a «I miei corsi»', await page.title() === 'I miei corsi · Pistacchio LMS', [await page.title()]);

        // 7. Un'immagine che non c'e' (la foto di un utente che non esiste): la frase sola.
        const img = await page.request.get(BASE + '/utenti/999999999/immagine', { headers: { Accept: 'image/avif,image/webp,*/*' } });
        const testoImg = await img.text();
        check('un\'immagine che non c\'e\': la frase sola, in testo semplice', img.status() === 404 && !testoImg.includes('<html') && (img.headers()['content-type'] || '').startsWith('text/plain'),
            [img.status() + ' ' + img.headers()['content-type'] + ' ' + testoImg.slice(0, 80)]);
        r = await page.goto(BASE + '/admin/users');
        p = await leggi(page);
        check('una pagina dell\'Amministratore: 403, «Accesso negato»', r.status() === 403 && p.h1[0] === 'Accesso negato' && p.scheda === 'Accesso negato · Pistacchio LMS', [r.status(), p.h1.join(' | ')]);
        check('e la frase senza ripetere il titolo', /^Permessi insufficienti per questa (pagina|azione)\.$/.test(p.frase), [p.frase]);

        r = await page.goto(BASE + '/courses/999999999');
        p = await leggi(page);
        check('un corso che non esiste: 404 con la frase del caso', r.status() === 404 && p.h1[0] === 'Pagina non trovata' && p.frase === 'Corso non trovato.', [r.status(), p.h1.join(' | '), p.frase]);

        // 5. Un modulo senza il token: lo si toglie e lo si invia.
        await page.goto(BASE + '/profilo');
        const [, inviato] = await Promise.all([page.waitForNavigation(), page.evaluate(() => {
            const f = [...document.querySelectorAll('form[method="post"]')].find((x) => x.querySelector('input[name="_token"]'));
            if (!f) return false;
            f.querySelector('input[name="_token"]').value = 'scaduto';
            f.querySelectorAll('[required]').forEach((x) => x.removeAttribute('required'));
            f.submit();
            return true;
        })]);
        p = await leggi(page);
        check('un modulo inviato senza il token: «Sessione scaduta»', inviato && p.h1[0] === 'Sessione scaduta' && /Ricarica la pagina e riprova/.test(p.frase), [inviato, p.h1.join(' | '), p.frase]);
        await ctx.close();

        console.log('\nSul telefono (320 px)');
        for (const email of [null, 'stud@test.it']) {
            ctx = await browser.newContext({ viewport: { width: 320, height: 640 } });
            page = await ctx.newPage();
            if (email) {
                await accedi(page, email);
            }
            await page.goto(BASE + '/indirizzo-che-non-esiste');
            const sfora = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            check((email ? 'dopo l\'accesso' : 'senza accesso') + ': la pagina sta nello schermo', sfora <= 0, ['sfora di ' + sfora + ' px']);
            await ctx.close();
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
