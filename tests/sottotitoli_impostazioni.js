/**
 * I sottotitoli delle pagine delle Impostazioni, i testi dei loro riquadri
 * nell'indice e le etichette e gli aiuti dei campi, decisi da Alessandro nel ripasso dell'Amministratore (dal
 * 25/11). Una riga per testo: chi cambia una frase cambia anche la riga.
 *
 * Esecuzione:  node tests/sottotitoli_impostazioni.js   (server di sviluppo acceso)
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

const ATTESI = [
    ['/admin/settings/posta', 'Quello che salvi qui ha la precedenza sul file .env; se lasci un campo vuoto torna a valere il valore scritto nel file.'],
    ['/admin/settings/email', 'Un campo lasciato vuoto usa il testo predefinito in grigio. Come partono le email si imposta in Server di posta elettronica.'],
    ['/admin/settings/meet', 'Serve a creare il link Meet e il corrispondente evento di calendario quando programmi una sessione live. Le sessioni restano comunque gestibili incollando a mano un link. Ha la precedenza sul file .env.'],
    ['/admin/settings/bunny', 'Dove stanno i video dei corsi. La firma impedisce che un indirizzo copiato dagli strumenti per sviluppatori funzioni anche per chi non è iscritto.'],
];

// Le etichette e gli aiuti dei campi: [indirizzo, id del campo, etichetta, aiuto sotto il campo].
const CAMPI = [
    ['/admin/settings/bunny', 'BUNNY_TOKEN_KEY', null,
        'Si copia dalle impostazioni della libreria su Bunny. Non viene mai rimandata al browser: se lasci il campo vuoto resta quella di prima.'],
    ['/admin/settings/bunny', 'BUNNY_TOKEN_TTL_HOURS', 'Durata del token (ore)',
        'Da 1 a 168 ore; il valore predefinito è 12. Non limita lo studente: il token nasce nuovo a ogni apertura della lezione, quindi si può riprendere un video anche giorni dopo.'],
    ['/admin/settings/meet', 'key', null,
        'Il file JSON scaricato da Google Cloud quando crei l\'account di servizio. Viene salvato in storage/google. Contiene una chiave privata: trattalo come una password.'],
];

// Il testo che apre un riquadro di una pagina: [indirizzo, titolo del riquadro, testo].
const INTRO = [
    ['/admin/settings/meet', 'Prova di connessione', 'Verifica le credenziali, la delega e il fatto che quel calendario sia visibile all\'utente impersonato.'],
];

// Le pagine in cui i testi non hanno grassetti (26/11, Alessandro): quelle ripassate.
const SENZA_GRASSETTI = ['mail.php', 'coda.php', 'email.php', 'benvenuto.php', 'bunny.php', 'meet.php', 'aspetto.php'];

// Il testo dei riquadri nella pagina delle Impostazioni: [indirizzo del riquadro, testo].
const RIQUADRI = [
    ['/admin/settings/email', 'Nome dell’organizzazione, mittente e testo di ogni email inviata dalla piattaforma.'],
    ['/admin/settings/benvenuto', 'Il video che uno studente vede al primo accesso alla piattaforma.'],
    ['/admin/permissions', 'Che cosa fa ogni ruolo, e i cinque interruttori che decide l’Amministratore.'],
    ['/admin/settings/pagamenti', 'I corsi a pagamento con Stripe: come crearli, e i pagamenti ricevuti.'],
];

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 6).forEach((d) => console.log('         · ' + d));
    }
}

(async () => {
    const browser = await chromium.launch();
    try {
        const page = await (await browser.newContext()).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        for (const [indirizzo, attesa] of ATTESI) {
            await page.goto(BASE + indirizzo);
            const frase = await page.evaluate(() => (document.querySelector('.page-header .page-subtitle')?.textContent || '').replace(/\s+/g, ' ').trim());
            check(indirizzo + ': il sottotitolo', frase === attesa, [frase]);
        }

        for (const [indirizzo, id, etichetta, aiuto] of CAMPI) {
            await page.goto(BASE + indirizzo);
            const r = await page.evaluate((id) => {
                const testo = (e) => (e?.textContent || '').replace(/\s+/g, ' ').trim();
                const campo = document.getElementById(id);
                let p = campo?.nextElementSibling;
                while (p && !p.matches('.form-hint')) p = p.nextElementSibling;
                return { etichetta: testo(document.querySelector('label[for="' + id + '"]')), aiuto: testo(p) };
            }, id);
            if (etichetta !== null) {
                check(indirizzo + ': l\'etichetta di ' + id, r.etichetta === etichetta, [r.etichetta]);
            }
            check(indirizzo + ': l\'aiuto sotto ' + id, r.aiuto === aiuto, [r.aiuto]);
        }

        for (const [indirizzo, titolo, attesa] of INTRO) {
            await page.goto(BASE + indirizzo);
            const testo = await page.evaluate((t) => {
                const c = [...document.querySelectorAll('section.card')].find((s) => s.querySelector('h2')?.textContent.trim() === t);
                return (c?.querySelector(':scope > h2 + p')?.textContent || '').replace(/\s+/g, ' ').trim();
            }, titolo);
            check(indirizzo + ': il testo che apre «' + titolo + '»', testo === attesa, [testo]);
        }

        // Nessun grassetto nei testi che spiegano le impostazioni (26/11): i titoli
        // sono in grassetto per il foglio di stile, non con <strong>. Si guarda il
        // sorgente, perche' alcune frasi compaiono solo in certe condizioni
        // («Adesso arriva dal file.» in Bunny Stream).
        const grassettiNelleViste = SENZA_GRASSETTI.flatMap((f) => {
            const testo = fs.readFileSync(path.join(__dirname, '..', 'app', 'Views', 'admin', 'settings', f), 'utf8');
            return (testo.match(/<(strong|b)[\s>][\s\S]*?<\/\1>/g) || []).map((m) => f + ': ' + m.replace(/\s+/g, ' ').slice(0, 80));
        });
        check('nessun grassetto nei testi di ' + SENZA_GRASSETTI.join(', '), grassettiNelleViste.length === 0, grassettiNelleViste);

        // Aspetto (26/11): nessun grassetto nei testi che descrivono.
        await page.goto(BASE + '/admin/settings/aspetto');
        const grassetti = await page.evaluate(() => [...document.querySelectorAll('main p, main li, main .form-hint, main .riquadro-intro, main .page-subtitle')]
            .flatMap((e) => [...e.querySelectorAll('strong, b')].map((b) => b.textContent.replace(/\s+/g, ' ').trim())));
        check('/admin/settings/aspetto: nessun grassetto nei testi', grassetti.length === 0, grassetti);

        // Ruoli (26/11, variante C): l'elenco di che cosa fa sempre un ruolo come i
        // testi che spiegano, e «Amministratore» invece di «Admin».
        await page.goto(BASE + '/admin/permissions');
        const ruoli = await page.evaluate(() => {
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
            const nota = getComputedStyle(document.querySelector('.ruoli-interruttore-cosa'));
            const voci = [...document.querySelectorAll('.ruoli-sempre li')].map((li) => {
                const s = getComputedStyle(li);
                return [Math.round(parseFloat(s.fontSize) / rem * 100) / 100, s.color === nota.color, Math.round(parseFloat(s.lineHeight) / parseFloat(s.fontSize) * 100) / 100].join(' ');
            });
            return { primo: document.querySelector('.ruoli > .card > h2')?.textContent.trim(), voci: [...new Set(voci)] };
        });
        check('/admin/permissions: il primo riquadro si chiama «Amministratore»', ruoli.primo === 'Amministratore', [String(ruoli.primo)]);
        check('/admin/permissions: ogni voce a 0,92 rem, grigia come la spiegazione degli interruttori, interlinea 1,45',
            ruoli.voci.length === 1 && ruoli.voci[0] === '0.92 true 1.45', ruoli.voci);

        // Google Meet, riquadro «Stato» (26/11): tutte le frasi alla misura del testo che apre un riquadro.
        await page.goto(BASE + '/admin/settings/meet');
        const stato = await page.evaluate(() => {
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
            const c = [...document.querySelectorAll('section.card')].find((s) => s.querySelector('h2')?.textContent.trim() === 'Stato');
            return [...(c?.querySelectorAll('p') || [])].map((p) => {
                const s = getComputedStyle(p);
                return { misura: Math.round(parseFloat(s.fontSize) / rem * 100) / 100, allineamento: s.textAlign };
            });
        });
        check('/admin/settings/meet: nel riquadro «Stato» ogni frase a 0,92 rem, allineata a sinistra',
            stato.length > 0 && stato.every((x) => x.misura === 0.92 && ['start', 'left'].includes(x.allineamento)), [JSON.stringify(stato)]);

        await page.goto(BASE + '/admin/settings');
        for (const [indirizzo, attesa] of RIQUADRI) {
            const testo = await page.evaluate((h) => (document.querySelector('a.settings-card[href="' + h + '"] .settings-card-text')?.textContent || '')
                .replace(/\s+/g, ' ').trim(), indirizzo);
            check('Impostazioni: il testo del riquadro ' + indirizzo, testo === attesa, [testo]);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
