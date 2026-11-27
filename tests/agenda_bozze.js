/**
 * L'agenda, le aperture dell'Amministratore e i corsi in bozza (27/11, Alessandro).
 *
 *  - L'Amministratore vede tutte le aperture dei moduli e per chi: una voce
 *    per data, con i gruppi («Gruppo …», «Gruppi …, …») e gli «iscritti
 *    senza gruppo».
 *  - Incontri e aperture di un corso in bozza compaiono a chi li vede
 *    (l'Amministratore; il tutor per gli incontri dei suoi gruppi) sbiaditi,
 *    con il bordo tratteggiato: nell'elenco con l'etichetta «Bozza» fuori
 *    dal titolo, nel mese con «In bozza» nella legenda, nel calendario
 *    piccolo del telefono con un cerchietto vuoto, nel calendario del
 *    telefono con «(Bozza)» in coda al titolo. Delle bozze solo quello che
 *    deve ancora venire.
 *  - Lo studente non vede niente delle bozze, e le sue aperture non cambiano.
 *  - Pubblicato il corso, tutto torna come le altre voci.
 *  - Il sottotitolo della pagina per l'Amministratore dice che vede tutto,
 *    anche le bozze; per gli altri resta quello di prima.
 *
 * Esecuzione:  node tests/agenda_bozze.js   (server di sviluppo acceso; crea
 * i dati con tests/agenda_bozze_semina.php e li toglie alla fine, e rimette
 * com'era l'indirizzo del calendario di admin@test.it)
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

const semina = (azione) => execFileSync('php', ['tests/agenda_bozze_semina.php', azione], { cwd: RADICE }).toString();
const php = (codice, ...a) => execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php"; $pdo = \\App\\Core\\Database::connection();' + codice, ...a],
    { cwd: RADICE }).toString();

/** Le voci dell'elenco con un titolo che contiene «[agenda-bozze]». */
const voci = (page) => page.evaluate(() => [...document.querySelectorAll('.agenda-voce')]
    .filter((li) => li.textContent.includes('[agenda-bozze]'))
    .map((li) => ({
        titolo: li.querySelector('.agenda-cosa a')?.textContent.trim(),
        giorno: li.querySelector('.agenda-giorno')?.textContent.trim(),
        contesto: li.querySelector('.agenda-contesto')?.textContent.trim(),
        bozza: li.classList.contains('agenda-bozza'),
        etichetta: li.querySelector('.agenda-cosa .badge')?.textContent.trim() || null,
        etichettaNelTitolo: !!li.querySelector('.agenda-cosa a .badge'),
        bordo: getComputedStyle(li).borderTopStyle,
    })));

const sottotitolo = (page) => page.evaluate(() => (document.querySelector('.page-header .page-subtitle')?.textContent || '').replace(/\s+/g, ' ').trim());
const SOTTOTITOLO_ADMIN = 'Tutti gli incontri dal vivo e tutte le aperture dei moduli, anche dei corsi in bozza, con i gruppi a cui si riferiscono.';
const SOTTOTITOLO_ALTRI = 'Gli incontri dal vivo dei tuoi corsi e dei tuoi gruppi, e le date in cui si aprono i moduli a rilascio programmato.';

const ggmmaaaa = (d) => d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4);

(async () => {
    const dati = JSON.parse(semina('crea'));
    const calendario = php('echo json_encode($pdo->query("SELECT calendar_token, calendar_token_created_at, calendar_token_used_at FROM users WHERE email = \'admin@test.it\'")->fetch(PDO::FETCH_ASSOC));');
    const browser = await chromium.launch();
    const entra = async (email, opzioni = {}) => {
        const page = await (await browser.newContext(opzioni)).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
        return page;
    };

    try {
        const admin = await entra('admin@test.it', { viewport: { width: 1280, height: 900 } });

        console.log('--- l\'Amministratore, nell\'elenco');
        await admin.goto(BASE + '/agenda');
        check('il sottotitolo dell\'Amministratore', await sottotitolo(admin) === SOTTOTITOLO_ADMIN, [await sottotitolo(admin)]);
        let v = await voci(admin);
        const pubblicate = v.filter((x) => x.titolo === '[agenda-bozze] modulo pubblicato');
        check('il modulo pubblicato: una voce per data, con per chi',
            pubblicate.length === 2
                && pubblicate.some((x) => x.giorno === ggmmaaaa(dati.data_alfa) && x.contesto === '[agenda-bozze] pubblicato · Gruppo [agenda-bozze] Alfa')
                && pubblicate.some((x) => x.giorno === ggmmaaaa(dati.data_modulo)
                    && x.contesto === '[agenda-bozze] pubblicato · Gruppi [agenda-bozze] Beta, [agenda-bozze] Gamma, iscritti senza gruppo'),
            [JSON.stringify(pubblicate)]);
        check('le voci di un corso pubblicato non sono segnate come bozza', pubblicate.length === 2 && pubblicate.every((x) => !x.bozza && x.etichetta === null),
            [JSON.stringify(pubblicate)]);

        const apBozza = v.filter((x) => x.titolo === '[agenda-bozze] modulo in bozza');
        check('l\'apertura in bozza: sbiadita e tratteggiata, «Bozza» accanto al titolo e non dentro, per chi',
            apBozza.length === 1 && apBozza[0].bozza && apBozza[0].bordo === 'dashed' && apBozza[0].etichetta === 'Bozza' && !apBozza[0].etichettaNelTitolo
                && apBozza[0].contesto === '[agenda-bozze] bozza · Gruppo [agenda-bozze] Alfa', [JSON.stringify(apBozza)]);
        const inBozza = v.filter((x) => x.titolo === '[agenda-bozze] incontro in bozza');
        check('l\'incontro in bozza: segnato con «Bozza»', inBozza.length === 1 && inBozza[0].bozza && inBozza[0].etichetta === 'Bozza', [JSON.stringify(inBozza)]);
        check('delle bozze niente di passato: né l\'apertura né l\'incontro',
            !v.some((x) => x.titolo === '[agenda-bozze] modulo in bozza passato' || x.titolo === '[agenda-bozze] incontro in bozza passato'),
            v.map((x) => x.titolo));

        console.log('--- l\'Amministratore, nel mese');
        await admin.goto(BASE + '/agenda?vista=mese&mese=' + dati.data_bozza.slice(0, 7));
        const mese = await admin.evaluate(() => {
            const pill = [...document.querySelectorAll('.agenda-griglia .agenda-pillola')].find((a) => a.textContent.includes('[agenda-bozze] modulo in bozza'));
            // `color-mix()` torna come «color(srgb r g b)», con i valori fra 0 e 1.
            const lum = (c) => { const srgb = c.startsWith('color(srgb'); const m = c.match(/[\d.]+/g).map(Number).map((x) => (srgb ? x * 255 : x)); const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(m[0]) + 0.7152 * f(m[1]) + 0.0722 * f(m[2]); };
            const cr = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
            const contrasti = [...document.querySelectorAll('.agenda-griglia .agenda-pillola.agenda-bozza')].map((a) => {
                const s = getComputedStyle(a); return cr(s.color, s.backgroundColor);
            });
            return {
                bozza: pill?.classList.contains('agenda-bozza') ?? null,
                bordo: pill ? getComputedStyle(pill).borderTopStyle : null,
                per: pill?.querySelector('.agenda-pillola-per')?.textContent.trim() ?? null,
                lettore: pill?.querySelector('.sr-only')?.textContent.trim() ?? null,
                legenda: [...document.querySelectorAll('.agenda-legenda li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim()),
                segniBozza: document.querySelectorAll('.agenda-legenda .agenda-segno.agenda-bozza').length,
                puntiBozza: document.querySelectorAll('.agenda-mini .agenda-punto.agenda-bozza').length,
                contrastoMinimo: contrasti.length ? Math.min(...contrasti) : null,
            };
        });
        check('nel mese la pillola in bozza è tratteggiata, con per chi e «in bozza» per i lettori di schermo',
            mese.bozza === true && mese.bordo === 'dashed' && mese.per === 'Gruppo [agenda-bozze] Alfa' && /in bozza$/.test(mese.lettore || ''), [JSON.stringify(mese)]);
        check('la legenda dice «In bozza», con i due segni', mese.legenda.includes('In bozza') && mese.segniBozza === 2, [JSON.stringify(mese.legenda), String(mese.segniBozza)]);
        check('il calendario piccolo del telefono ha i cerchietti delle bozze', mese.puntiBozza === 2, [String(mese.puntiBozza)]);
        check('il testo delle pillole in bozza resta sopra 4,5:1', mese.contrastoMinimo !== null && mese.contrastoMinimo >= 4.5, [String(mese.contrastoMinimo)]);

        await admin.goto(BASE + '/agenda?vista=mese&mese=2000-01');
        check('un mese senza bozze non ha «In bozza» nella legenda',
            !(await admin.evaluate(() => [...document.querySelectorAll('.agenda-legenda li')].some((li) => li.textContent.includes('In bozza')))));

        console.log('--- il calendario del telefono');
        const ics = (await (await admin.context().request.get(BASE + '/agenda/evento/' + dati.incontro_bozza + '.ics')).text()).replace(/\r\n[ \t]/g, '');
        check('«Aggiungi al calendario» di un incontro in bozza: «(Bozza)» in coda al titolo', /SUMMARY:\[agenda-bozze\] incontro in bozza \(Bozza\)\r?\n/.test(ics),
            [(ics.match(/SUMMARY:.*/) || [''])[0]]);
        const token = 'agendabozze' + Date.now().toString(16).padStart(37, '0');
        php('$pdo->prepare("UPDATE users SET calendar_token = ? WHERE email = \'admin@test.it\'")->execute([$argv[1]]);', token);
        const feed = (await (await admin.context().request.get(BASE + '/calendario/' + token + '.ics')).text()).replace(/\r\n[ \t]/g, '');
        const eventi = feed.split('BEGIN:VEVENT').slice(1).filter((e) => e.includes('[agenda-bozze]'));
        const titoli = eventi.map((e) => (e.match(/SUMMARY:(.*)/) || ['', ''])[1].trim());
        const uid = eventi.map((e) => (e.match(/UID:(.*)/) || ['', ''])[1].trim());
        check('il calendario sottoscritto: le bozze con «(Bozza)» in coda, le altre no',
            titoli.includes('Si apre: [agenda-bozze] modulo in bozza (Bozza)') && titoli.includes('[agenda-bozze] incontro in bozza (Bozza)')
                && titoli.filter((t) => t === 'Si apre: [agenda-bozze] modulo pubblicato').length === 2, titoli);
        check('una voce per data, ciascuna con il suo identificativo', uid.length === eventi.length && new Set(uid).size === uid.length, uid);

        console.log('--- il tutor');
        const tutor = await entra('tutor1@test.it');
        await tutor.goto(BASE + '/agenda');
        v = await voci(tutor);
        check('il tutor: l\'incontro in bozza del suo gruppo, segnato; niente aperture, niente passato',
            v.length === 1 && v[0].titolo === '[agenda-bozze] incontro in bozza' && v[0].bozza && v[0].etichetta === 'Bozza', [JSON.stringify(v)]);

        console.log('--- lo studente');
        const studente = await entra('agenda-bozze-studente@test.it');
        await studente.goto(BASE + '/agenda');
        check('il sottotitolo dello studente resta quello di prima', await sottotitolo(studente) === SOTTOTITOLO_ALTRI, [await sottotitolo(studente)]);
        v = await voci(studente);
        check('lo studente: la sua apertura, come prima, e niente delle bozze',
            v.length === 1 && v[0].titolo === '[agenda-bozze] modulo pubblicato' && v[0].giorno === ggmmaaaa(dati.data_modulo)
                && v[0].contesto === '[agenda-bozze] pubblicato' && !v[0].bozza && v[0].etichetta === null, [JSON.stringify(v)]);
        await studente.goto(BASE + '/agenda?vista=mese&mese=' + dati.data_modulo.slice(0, 7));
        check('lo studente: nella pillola niente «per chi»', await studente.locator('.agenda-pillola-per').count() === 0);

        console.log('--- pubblicato il corso');
        semina('pubblica');
        await admin.goto(BASE + '/agenda');
        v = await voci(admin);
        check('tutto torna come le altre voci: nessuna bozza, nessuna etichetta', v.length > 0 && v.every((x) => !x.bozza && x.etichetta === null), [JSON.stringify(v)]);
        check('e le cose passate compaiono, nello storico', v.some((x) => x.titolo === '[agenda-bozze] modulo in bozza passato')
            && v.some((x) => x.titolo === '[agenda-bozze] incontro in bozza passato'), v.map((x) => x.titolo));
    } finally {
        await browser.close();
        const c = JSON.parse(calendario);
        php('$pdo->prepare("UPDATE users SET calendar_token = ?, calendar_token_created_at = ?, calendar_token_used_at = ? WHERE email = \'admin@test.it\'")->execute([$argv[1] ?: null, $argv[2] ?: null, $argv[3] ?: null]);',
            c.calendar_token || '', c.calendar_token_created_at || '', c.calendar_token_used_at || '');
        semina('togli');
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
