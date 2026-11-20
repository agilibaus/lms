/**
 * «Registrato il» nella scheda dell'utente (20/11, Alessandro).
 *
 * Sotto il ruolo, un campo in sola lettura con data e ora in cui l'account
 * e' nato. Non c'e' quando si crea un utente nuovo, non viaggia con il
 * modulo e non si confonde con un campo da compilare.
 *
 * Esecuzione:  node tests/utente_registrato.js   (server di sviluppo acceso)
 *
 * Crea lo studente «studente@utente-registrato.test» con una data di
 * registrazione nota e alla fine lo cancella.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const EMAIL = 'studente@utente-registrato.test';

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

(async () => {
    esegui('DELETE FROM users WHERE email = ?', EMAIL);
    esegui("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, created_at)"
        + " VALUES (?, 'x', 'Prova', 'Registrato', 'studente', 1, NOW(), '2026-03-07 09:05:00')", EMAIL);
    const id = uno('SELECT id FROM users WHERE email = ?', EMAIL);

    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        await page.goto(BASE + '/admin/users/' + id + '/edit');
        const r = await page.evaluate(() => {
            const campo = document.getElementById('registrato_il');
            if (!campo) {
                return null;
            }
            const etichetta = document.querySelector('label[for="registrato_il"]');
            const ruolo = document.getElementById('role');
            const nome = document.getElementById('first_name');
            // Il primo elemento visibile del modulo dopo il ruolo.
            let dopo = ruolo.nextElementSibling;
            while (dopo && dopo.offsetParent === null) {
                dopo = dopo.nextElementSibling;
            }
            return {
                etichetta: etichetta ? etichetta.textContent.trim() : null,
                valore: campo.value,
                solaLettura: campo.readOnly,
                nome: campo.getAttribute('name'),
                subitoDopoIlRuolo: dopo === etichetta,
                sfondo: getComputedStyle(campo).backgroundColor,
                sfondoNome: getComputedStyle(nome).backgroundColor,
            };
        });
        check('«Registrato il» c\'è, con data e ora della registrazione',
            r !== null && r.etichetta === 'Registrato il' && r.valore === '07/03/2026 09:05', [JSON.stringify(r)]);
        check('è subito sotto il ruolo', r !== null && r.subitoDopoIlRuolo, [JSON.stringify(r)]);
        check('è in sola lettura, senza nome, e non ha lo sfondo di un campo da compilare',
            r !== null && r.solaLettura && r.nome === null && r.sfondo !== r.sfondoNome, [JSON.stringify(r)]);

        await page.click('form[data-user-form] button[type="submit"]');
        await page.waitForLoadState('networkidle');
        const dopoIlSalvataggio = uno('SELECT created_at FROM users WHERE id = ?', id);
        check('salvando la scheda la data non cambia', dopoIlSalvataggio === '2026-03-07 09:05:00', [dopoIlSalvataggio]);

        await page.goto(BASE + '/admin/users/create');
        check('nella creazione di un utente nuovo non c\'è', await page.locator('#registrato_il').count() === 0);
    } finally {
        await browser.close();
        esegui('DELETE FROM users WHERE email = ?', EMAIL);
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
