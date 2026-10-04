/**
 * I moduli da compilare si somigliano? Misura, non guarda.
 *
 * Esecuzione (serve il server di sviluppo attivo):
 *   php -S 127.0.0.1:8123 -t public router-dev.php &
 *   node tests/coerenza_moduli.js
 *
 * PERCHÉ ESISTE. Nel progetto convivono tre famiglie di moduli, nate in
 * momenti diversi: `.form` nel pannello, `.stacked-form` nei moduli brevi,
 * `.auth-form` nelle pagine pubbliche. Al 04/10 avevano derive silenziose —
 * etichette a 15,2 px in due sistemi e 13,1 nel terzo, campi alti 40 px in
 * due e 34 nel terzo, lo stesso pulsante alto 35 px da solo e 37 accanto a
 * un altro comando. Nessuna di queste era una decisione: erano copie
 * invecchiate in modo diverso.
 *
 * Il controllo di accessibilità non le vede, ed è giusto così: una pagina
 * con le etichette piccole è accessibile lo stesso. Questo file guarda
 * un'altra cosa — che la **stessa** cosa sia disegnata allo **stesso** modo
 * in tutte le pagine — e fallisce quando una misura si discosta, cioè prima
 * che la differenza arrivi sotto gli occhi di qualcuno.
 *
 * COSA NON VEDE. Se le misure sono giuste: tre moduli sbagliati allo stesso
 * modo passano. Dice che sono coerenti, non che sono belli.
 *
 * UNA DIFFERENZA VOLUTA NON E' UNA DERIVA. Dalla 0085 l'admin può ritoccare
 * di proposito singoli elementi delle pagine pubbliche — per esempio dare al
 * pulsante un peso diverso. Quando succede, confrontare quel pezzo con le
 * pagine interne vorrebbe dire far fallire il controllo per una scelta di
 * chi amministra. Il confronto su quel pezzo viene quindi **saltato
 * dicendolo**, e si riconosce leggendo le regole che Pistacchio stampa nella
 * pagina: se c'è una regola per quel selettore, qualcuno l'ha voluta.
 */

'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const ADMIN = process.env.LMS_ADMIN || 'admin@test.it';
const PASS = process.env.LMS_PASS || 'Password1!';

/**
 * Tolleranza in pixel. Non è indulgenza: le pagine pubbliche usano Albert
 * Sans e quelle interne il carattere di sistema, e due caratteri diversi
 * alla stessa dimensione danno righe alte 17 e 18 px. È una differenza del
 * carattere, non del foglio di stile, e pretendere lo zero vorrebbe dire un
 * controllo che fallisce per un motivo che non è un difetto.
 */
const TOLLERANZA = 1.5;

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    detta(dettagli);
}

function detta(dettagli) {
    dettagli.forEach((d) => console.log('         · ' + d));
}

/** Le misure di un modulo, lette dal browser dopo che il CSS è stato applicato. */
function misura(radice) {
    const f = document.querySelector(radice);

    if (!f) {
        return null;
    }

    const leggiElemento = (e) => {
        if (!e) {
            return null;
        }

        const st = getComputedStyle(e);

        return {
            px: +parseFloat(st.fontSize).toFixed(2),
            peso: st.fontWeight,
            altezza: +e.getBoundingClientRect().height.toFixed(2),
            riempimento: st.padding,
            raggio: st.borderTopLeftRadius,
        };
    };

    const leggi = (selettore) => {
        const e = f.querySelector(selettore);

        if (!e) {
            return null;
        }

        const st = getComputedStyle(e);

        return {
            px: +parseFloat(st.fontSize).toFixed(2),
            peso: st.fontWeight,
            altezza: +e.getBoundingClientRect().height.toFixed(2),
            riempimento: st.padding,
            raggio: st.borderTopLeftRadius,
        };
    };

    // L'etichetta di un CAMPO, non una qualunque: nella pagina Aspetto le
    // tavolozze sono etichette che avvolgono un radio e misurano 86 px,
    // legittimamente. Si cerca quindi un `label[for]` il cui bersaglio sia
    // un campo di testo — che e' la definizione di quello che si vuole
    // confrontare. Senza questo filtro il controllo falliva su una
    // differenza che non e' una deriva.
    const etichettaDiCampo = () => {
        for (const l of f.querySelectorAll('label[for]')) {
            const c = f.querySelector('#' + CSS.escape(l.getAttribute('for')));

            if (c === null) {
                continue;
            }

            const tipo = (c.getAttribute('type') || '').toLowerCase();
            const buono = c.tagName === 'TEXTAREA' || c.tagName === 'SELECT'
                || (c.tagName === 'INPUT' && !['radio', 'checkbox', 'file', 'hidden'].includes(tipo));

            if (buono) {
                return l;
            }
        }

        return null;
    };

    return {
        etichetta: leggiElemento(etichettaDiCampo()),
        campo: leggi('input[type="email"], input[type="text"], input[type="password"]'),
        pulsante: leggi('button[type="submit"]'),
    };
}

async function entra(page) {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', ADMIN);
    await page.fill('input[name="password"]', PASS);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('load');
}

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();

    // Le pagine pubbliche prima dell'accesso, le altre dopo.
    const pubbliche = [
        ['/login', 'Accesso', 'form.auth-form'],
        ['/register', 'Registrazione', 'form.auth-form'],
        ['/password/dimenticata', 'Recupero password', 'form.auth-form'],
    ];

    const interne = [
        ['/modules/1/edit', 'Modifica modulo', 'form.stacked-form'],
        ['/admin/users/2/edit', 'Modifica utente', 'form.form'],
        ['/admin/settings/aspetto', 'Aspetto', 'form.form'],
        ['/admin/courses/1/edit', 'Modifica corso', 'form.form'],
    ];

    const raccolte = [];

    // Le regole che il pannello Aspetto stampa nella pagina pubblica: è da
    // qui che si capisce quali differenze sono state volute.
    await page.goto(BASE + '/login');
    const ritoccati = await page.evaluate(() => {
        const css = Array.from(document.querySelectorAll('style')).map((s) => s.textContent).join('');

        return {
            etichetta: css.includes('.auth-form label{'),
            campo: css.includes('.auth-form input{'),
            pulsante: css.includes('.auth-form .btn{'),
        };
    });

    for (const [url, nome, radice] of pubbliche) {
        const risposta = await page.goto(BASE + url);

        if (!risposta || risposta.status() >= 400) {
            console.log('  --   ' + nome + ': non raggiungibile, saltata');
            continue;
        }

        // Senza aspettare il carattere, le altezze si misurano mentre è
        // ancora in uso quello di sistema: numeri veri di un istante che
        // l'utente non vede.
        await page.evaluate(() => document.fonts.ready);
        raccolte.push([nome, await page.evaluate(misura, radice)]);
    }

    await entra(page);

    for (const [url, nome, radice] of interne) {
        const risposta = await page.goto(BASE + url);

        if (!risposta || risposta.status() >= 400) {
            console.log('  --   ' + nome + ': non presente in questa installazione, saltata');
            continue;
        }

        await page.evaluate(() => document.fonts.ready);
        raccolte.push([nome, await page.evaluate(misura, radice)]);
    }

    console.log('\n--- che cosa si è misurato');
    for (const [nome, d] of raccolte) {
        if (d === null) {
            console.log('  ' + nome.padEnd(20) + 'modulo non trovato');
            continue;
        }
        for (const parte of ['etichetta', 'campo', 'pulsante']) {
            const v = d[parte];
            console.log(
                '  ' + nome.padEnd(20) + parte.padEnd(10)
                + (v ? `${String(v.px).padStart(6)} px · alto ${String(v.altezza).padStart(6)} · peso ${v.peso}` : 'assente')
            );
        }
    }

    console.log('\n--- la stessa cosa è disegnata allo stesso modo?');

    for (const parte of ['etichetta', 'campo', 'pulsante']) {
        const presenti = raccolte.filter(([, d]) => d && d[parte]);

        if (presenti.length < 2) {
            console.log('  --   ' + parte + ': meno di due pagine da confrontare, saltata');
            continue;
        }

        if (ritoccati[parte]) {
            console.log(
                '  --   ' + parte + ': in Aspetto è stato scelto apposta uno stile diverso '
                + 'per le pagine pubbliche, confronto saltato'
            );
            continue;
        }

        const [nomeRif, rif] = presenti[0];

        for (const misuraDa of ['px', 'altezza']) {
            const fuori = presenti
                .slice(1)
                .filter(([, d]) => Math.abs(d[parte][misuraDa] - rif[parte][misuraDa]) > TOLLERANZA)
                .map(([n, d]) => `${n}: ${d[parte][misuraDa]} invece di ${rif[parte][misuraDa]}`);

            check(
                `${parte}: ${misuraDa === 'px' ? 'stessa dimensione' : 'stessa altezza'} ovunque (${rif[parte][misuraDa]})`,
                fuori.length === 0,
                fuori.concat([`riferimento: ${nomeRif}`])
            );
        }

        const pesiDiversi = presenti
            .slice(1)
            .filter(([, d]) => d[parte].peso !== rif[parte].peso)
            .map(([n, d]) => `${n}: ${d[parte].peso} invece di ${rif[parte].peso}`);

        check(`${parte}: stesso peso ovunque (${rif[parte].peso})`, pesiDiversi.length === 0, pesiDiversi);
    }

    // Un campo e il pulsante che gli sta sotto devono essere alti uguale:
    // e' la differenza che si nota di piu', perche' i due riquadri sono
    // incolonnati e vicini.
    for (const [nome, d] of raccolte) {
        if (!d || !d.campo || !d.pulsante) {
            continue;
        }

        check(
            `${nome}: il pulsante è alto quanto i campi`,
            Math.abs(d.campo.altezza - d.pulsante.altezza) <= TOLLERANZA,
            [`campo ${d.campo.altezza}, pulsante ${d.pulsante.altezza}`]
        );
    }

    console.log(`\nTotale: ${ok} superati, ${fail} falliti`);

    await browser.close();
    process.exit(fail > 0 ? 1 : 0);
})();
