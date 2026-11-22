/**
 * Le sessioni concluse viste da chi partecipa (22/11, Alessandro).
 *
 * La sezione «Concluse» resta chiusa e, aperta, mostra le ultime 5, dalla
 * piu' recente, con «Mostra tutte» in fondo; il collegamento ricarica la
 * pagina con tutte le concluse e la sezione aperta. Con 5 o meno il
 * collegamento non c'e'.
 *
 * Esecuzione:  node tests/live_concluse.js   (server di sviluppo acceso,
 * semina dei permessi fatta: entra come stud@test.it)
 *
 * Crea sette sessioni concluse «[concluse] …» per il gruppo dello studente
 * e alla fine le cancella.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.forEach((d) => console.log('         · ' + d));
    }
}

function php(codice, ...argomenti) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();' + codice, '--', ...argomenti.map(String)], { cwd: RADICE, encoding: 'utf8' });
}

const uno = (sql, ...a) => php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo (string) $s->fetchColumn();', sql, ...a).trim();
const esegui = (sql, ...a) => php('$pdo->prepare($argv[1])->execute(array_slice($argv, 2));', sql, ...a);

const leggi = (page) => page.evaluate(() => {
    const d = document.querySelector('details.agenda-passati');
    return d === null ? null : {
        aperta: d.open,
        titolo: d.querySelector('summary').textContent.trim(),
        voci: [...d.querySelectorAll('.agenda-elenco > li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim()),
        comando: d.querySelector('.agenda-mostra-tutte a')?.textContent.trim() ?? null,
    };
});

(async () => {
    esegui("DELETE FROM live_sessions WHERE title LIKE '[concluse] %'");
    const studente = uno("SELECT id FROM users WHERE email = 'stud@test.it'");
    const gruppo = uno('SELECT group_id FROM group_members WHERE user_id = ? LIMIT 1', studente);
    const modulo = uno("SELECT m.id FROM modules m JOIN courses c ON c.id = m.course_id WHERE c.title = '[prova-permessi] mondo A' LIMIT 1");
    const admin = uno("SELECT id FROM users WHERE email = 'admin@test.it'");
    for (let i = 1; i <= 7; i++) {
        // La «[concluse] 1» e' la piu' recente: ieri; la 7 di sette giorni fa.
        esegui('INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, created_by, created_at)'
            + ' VALUES (?, ?, ?, NOW() - INTERVAL ? DAY, NOW() - INTERVAL ? DAY + INTERVAL 1 HOUR, ?, NOW())',
            modulo, gruppo, '[concluse] ' + i, i, i, admin);
    }

    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'stud@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');
        await page.goto(BASE + '/live');

        let r = await leggi(page);
        const totale = r === null ? 0 : Number((r.titolo.match(/\((\d+)\)/) || [])[1]);
        check('«Concluse» è chiusa, e dice quante sono', r !== null && !r.aperta && totale >= 7, [JSON.stringify(r)]);
        // La data di ogni riquadro, «gg/mm/aaaa hh:mm», come numero ordinabile.
        const quando = (v) => { const m = v.match(/(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2})/); return m ? m[3] + m[2] + m[1] + m[4] + m[5] : ''; };
        const decrescente = (voci) => voci.every((v, i) => i === 0 || quando(voci[i - 1]) >= quando(v));
        check('aperta, mostra le ultime 5, dalla più recente',
            r !== null && r.voci.length === 5 && r.voci[0].includes('[concluse] 1') && decrescente(r.voci), [JSON.stringify(r)]);
        check('in fondo c\'è «Mostra tutte»', r !== null && r.comando === 'Mostra tutte', [JSON.stringify(r)]);

        await page.locator('details.agenda-passati > summary').click();
        await Promise.all([page.waitForNavigation(), page.click('.agenda-mostra-tutte a')]);
        r = await leggi(page);
        check('«Mostra tutte»: tutte le concluse, con la sezione già aperta e senza il comando',
            r !== null && r.aperta && r.voci.length === totale && decrescente(r.voci) && r.comando === null
                && page.url().endsWith('?concluse=tutte#concluse'),
            [JSON.stringify(r), page.url()]);

        // Esattamente 5: si tolgono le sessioni di prova piu' vecchie.
        const altre = totale - 7;
        if (altre <= 5) {
            for (let i = 7; i > 5 - altre; i--) {
                esegui('DELETE FROM live_sessions WHERE title = ?', '[concluse] ' + i);
            }
            await page.goto(BASE + '/live');
            r = await leggi(page);
            check('con 5 esatte, tutte e niente comando', r.titolo === 'Concluse (5)' && r.voci.length === 5 && r.comando === null, [JSON.stringify(r)]);
        } else {
            console.log('  --   lo studente ha piu\' di 5 altre sessioni concluse: la prova «5 esatte» qui non si fa');
        }
    } finally {
        await browser.close();
        esegui("DELETE FROM live_sessions WHERE title LIKE '[concluse] %'");
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
