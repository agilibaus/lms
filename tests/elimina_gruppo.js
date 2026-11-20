/**
 * Eliminare un gruppo (20/11, Alessandro, variante A).
 *
 * Un gruppo con incontri in programma (non ancora conclusi) non si elimina:
 * «Elimina gruppo» resta al suo posto, spento, e accanto la frase dice di
 * annullarli prima, con un collegamento a ciascuno. Il server rifiuta anche
 * un invio fatto a mano. Annullati gli incontri dalla loro pagina, il
 * comando torna rosso e accanto dice che cosa sparisce con il gruppo (gli
 * incontri conclusi con le presenze, le domande del giro del cerchio), la
 * stessa frase della conferma. Gli studenti restano, con le iscrizioni.
 *
 * Esecuzione:  node tests/elimina_gruppo.js   (server di sviluppo acceso,
 * semina gia' fatta: usa un modulo e un corso del mondo A)
 *
 * Crea il gruppo «[elimina-gruppo] prova», uno studente
 * «studente@elimina-gruppo.test», due incontri e una domanda; alla fine
 * toglie quello che resta.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const NOME = '[elimina-gruppo] prova';
const STUDENTE = 'studente@elimina-gruppo.test';

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

function pulisci() {
    esegui('DELETE FROM live_sessions WHERE title LIKE ?', '[elimina-gruppo]%');
    esegui('DELETE FROM `groups` WHERE name = ?', NOME);
    esegui('DELETE FROM users WHERE email = ?', STUDENTE);
}

(async () => {
    pulisci();
    const admin = uno("SELECT id FROM users WHERE email = 'admin@test.it'");
    const modulo = uno("SELECT m.id FROM modules m JOIN courses c ON c.id = m.course_id WHERE c.title = '[prova-permessi] mondo A' ORDER BY m.id LIMIT 1");
    const corso = uno('SELECT course_id FROM modules WHERE id = ?', modulo);
    esegui('INSERT INTO `groups` (name, created_at) VALUES (?, NOW())', NOME);
    const gruppo = uno('SELECT id FROM `groups` WHERE name = ?', NOME);
    php('App\\Models\\UserModel::create($argv[1], $argv[2], "Studente", "Elimina");', STUDENTE, PASSWORD);
    const studente = uno('SELECT id FROM users WHERE email = ?', STUDENTE);
    esegui('INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())', gruppo, studente);
    esegui('INSERT INTO enrollments (user_id, course_id, enrolled_at) VALUES (?, ?, NOW())', studente, corso);
    const incontro = (titolo, inizio) => {
        esegui('INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, created_by, created_at) VALUES (?, ?, ?, ' + inizio + ', ' + inizio + ' + INTERVAL 1 HOUR, ?, NOW())',
            modulo, gruppo, titolo, admin);
        return uno('SELECT id FROM live_sessions WHERE title = ?', titolo);
    };
    const passato = incontro('[elimina-gruppo] concluso', 'NOW() - INTERVAL 10 DAY');
    const futuro1 = incontro('[elimina-gruppo] Tappa 3', 'NOW() + INTERVAL 5 DAY');
    const futuro2 = incontro('[elimina-gruppo] Tappa 4', 'NOW() + INTERVAL 12 DAY');
    esegui('INSERT INTO group_questions (group_id, author_id, question, created_at) VALUES (?, ?, ?, NOW())', gruppo, admin, 'Che cosa portate a casa?');

    const browser = await chromium.launch();
    const page = await (await browser.newContext()).newPage();
    const riquadro = () => page.evaluate(() => {
        const r = document.querySelector('.elimina-gruppo');
        if (!r) return null;
        return {
            spento: !!r.querySelector('.comando-spento[aria-disabled="true"]'),
            comandoRosso: !!r.querySelector('button.link-btn-danger'),
            icona: !!r.querySelector('.comando-spento svg, button svg'),
            testoComando: (r.querySelector('.comando-spento, button')?.textContent || '').trim(),
            frase: (r.querySelector('.form-hint')?.textContent || '').replace(/\s+/g, ' ').trim(),
            link: [...r.querySelectorAll('.form-hint a')].map((a) => [a.textContent.trim(), a.getAttribute('href')]),
            conferma: r.getAttribute('onsubmit') || '',
        };
    });

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        console.log('--- con incontri in programma');
        await page.goto(BASE + '/admin/groups/' + gruppo + '/edit');
        let r = await riquadro();
        check('«Elimina gruppo» c\'è, spento, con il cestino', r !== null && r.spento && !r.comandoRosso && r.icona && r.testoComando === 'Elimina gruppo', [JSON.stringify(r)]);
        check('accanto dice di annullarli prima, con un collegamento a ciascuno, in ordine di data',
            r !== null && r.frase.startsWith('Prima annulla le sessioni live in programma del gruppo:') && r.frase.endsWith('Annullandole, chi era invitato riceve l\'avviso.')
                && JSON.stringify(r.link) === JSON.stringify([['[elimina-gruppo] Tappa 3', '/live/' + futuro1], ['[elimina-gruppo] Tappa 4', '/live/' + futuro2]])
                && !r.frase.includes('concluso'), [JSON.stringify(r)]);

        const token = await page.evaluate(() => document.querySelector('input[name="_token"]').value);
        await page.evaluate(async ([g, t]) => {
            await fetch('/admin/groups/' + g + '/delete', { method: 'POST', body: new URLSearchParams({ _token: t }), credentials: 'same-origin', redirect: 'manual' });
        }, [gruppo, token]);
        await page.goto(BASE + '/admin/groups/' + gruppo + '/edit');
        const rifiuto = await page.evaluate(() => (document.querySelector('.alert-error')?.textContent || '').trim());
        check('un invio fatto a mano viene rifiutato: il gruppo e gli incontri restano',
            uno('SELECT COUNT(*) FROM `groups` WHERE id = ?', gruppo) === '1' && uno('SELECT COUNT(*) FROM live_sessions WHERE group_id = ?', gruppo) === '3'
                && rifiuto.startsWith('Il gruppo ha sessioni live in programma: annullale prima di eliminarlo'), [rifiuto]);

        // Annullato uno solo, la frase dice dell'altro, al singolare.
        await page.goto(BASE + '/live/' + futuro1);
        page.once('dialog', (d) => d.accept());
        await Promise.all([page.waitForLoadState('networkidle'), page.click('form[action="/live/' + futuro1 + '/delete"] button[type="submit"]')]);
        await page.goto(BASE + '/admin/groups/' + gruppo + '/edit');
        r = await riquadro();
        check('annullato uno, resta l\'altro, al singolare',
            r !== null && r.spento && r.frase.startsWith('Prima annulla la sessione live in programma del gruppo:') && r.link.length === 1
                && r.frase.endsWith('Annullandola, chi era invitato riceve l\'avviso.'), [JSON.stringify(r)]);

        console.log('\n--- senza incontri in programma');
        await page.goto(BASE + '/live/' + futuro2);
        page.once('dialog', (d) => d.accept());
        await Promise.all([page.waitForLoadState('networkidle'), page.click('form[action="/live/' + futuro2 + '/delete"] button[type="submit"]')]);
        await page.goto(BASE + '/admin/groups/' + gruppo + '/edit');
        r = await riquadro();
        const attesa = 'Verranno rimosse 1 sessione live conclusa, con relative presenze, e 1 domanda del giro del cerchio. Gli studenti restano iscritti ai corsi.';
        check('il comando torna rosso, con il cestino, e accanto che cosa sparisce', r !== null && r.comandoRosso && !r.spento && r.icona && r.frase === attesa, [JSON.stringify(r)]);
        check('la conferma dice la stessa cosa, con il nome del gruppo', r !== null && r.conferma.includes('Eliminare il gruppo «' + NOME + '»? ' + attesa), [r ? r.conferma : '']);

        page.once('dialog', (d) => d.accept());
        await Promise.all([page.waitForLoadState('networkidle'), page.click('.elimina-gruppo button[type="submit"]')]);
        check('confermato, il gruppo se ne va con l\'incontro concluso e la domanda',
            uno('SELECT COUNT(*) FROM `groups` WHERE id = ?', gruppo) === '0' && uno('SELECT COUNT(*) FROM live_sessions WHERE id = ?', passato) === '0'
                && uno('SELECT COUNT(*) FROM group_questions WHERE group_id = ?', gruppo) === '0');
        check('lo studente resta, con la sua iscrizione', uno('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?', studente, corso) === '1');
    } finally {
        await browser.close();
        pulisci();
    }

    console.log('\n' + ok + ' OK, ' + fail + ' FAIL');
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    pulisci();
    process.exit(1);
});
