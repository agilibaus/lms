/**
 * «Domande › Pubblicate»: «Non pubblicare più» (26/11, Alessandro; era
 * «Togli dall'archivio», parola che fuori da qui non c'e'). La domanda
 * sparisce da «L'esperto risponde» e torna «Non pubblicata», senza
 * cancellarsi: la frase sopra l'elenco, il comando (con l'icona di
 * «Non pubblicare»), la conferma e il messaggio lo dicono con le stesse
 * parole.
 *
 * La prova pubblica una domanda finta, la toglie dal comando e alla fine la
 * cancella.
 *
 * Esecuzione:  node tests/domande_non_pubblicare.js   (server di sviluppo acceso)
 */
'use strict';

const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const TESTO = 'Domanda di prova per «Non pubblicare più»';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 6).forEach((d) => console.log('         · ' + d));
    }
}

/** «crea» pubblica la domanda finta e ne da' l'id; «stato» ne da' lo stato; «togli» la cancella. */
function domanda(azione) {
    const codice = 'require "vendor/autoload.php"; require "config/config.php";'
        + '$pdo = \\App\\Core\\Database::connection(); $t = $argv[2];'
        + 'if ($argv[1] === "crea") {'
        + '  $pdo->prepare("DELETE FROM course_questions WHERE question = ?")->execute([$t]);'
        + '  $c = (int) $pdo->query("SELECT id FROM courses ORDER BY id LIMIT 1")->fetchColumn();'
        + '  $pdo->prepare("INSERT INTO course_questions (course_id, question, answer, status) VALUES (?, ?, \'Risposta di prova.\', \'published\')")->execute([$c, $t]);'
        + '  echo $pdo->lastInsertId();'
        + '} elseif ($argv[1] === "stato") {'
        + '  $s = $pdo->prepare("SELECT status FROM course_questions WHERE question = ?"); $s->execute([$t]); echo (string) $s->fetchColumn();'
        + '} else { $pdo->prepare("DELETE FROM course_questions WHERE question = ?")->execute([$t]); }';
    return execFileSync('php', ['-r', codice, azione, TESTO], { cwd: RADICE }).toString().trim();
}

(async () => {
    const browser = await chromium.launch();
    try {
        const id = domanda('crea');
        const page = await (await browser.newContext()).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        await page.goto(BASE + '/domande?apri=' + id);
        const r = await page.evaluate((id) => {
            const testo = (e) => (e?.textContent || '').replace(/\s+/g, ' ').trim();
            const bottone = document.querySelector('button[form="pubblicata-' + id + '-togli"]');
            return {
                intro: testo(document.querySelector('.qa-pubblicate .qa-intro')),
                comando: testo(bottone),
                icona: !!bottone?.querySelector('svg'),
                conferma: document.getElementById('pubblicata-' + id + '-togli')?.getAttribute('onsubmit') || '',
                archivio: /archivio/i.test(document.querySelector('.qa-pubblicate')?.innerText || ''),
            };
        }, id);
        check('la frase sopra l\'elenco', r.intro === 'Apri una domanda per correggerla, o per nasconderla: non comparirà più in «L’esperto risponde».', [r.intro]);
        check('il comando si chiama «Non pubblicare più», con l\'icona', r.comando === 'Non pubblicare più' && r.icona, [JSON.stringify(r)]);
        check('la conferma dice che non si cancella', r.conferma.includes('Non pubblicare più questa domanda?') && r.conferma.includes('Non si cancella'), [r.conferma]);
        check('nelle pubblicate la parola «archivio» non c\'è più', !r.archivio);

        let conferma = '';
        page.once('dialog', (d) => { conferma = d.message(); d.accept(); });
        await Promise.all([page.waitForNavigation(), page.click('button[form="pubblicata-' + id + '-togli"]')]);
        const messaggio = await page.evaluate(() => (document.querySelector('.alert-success, .flash-success, [role="status"]')?.textContent || '').replace(/\s+/g, ' ').trim());
        check('la conferma si apre con le parole nuove', conferma.startsWith('Non pubblicare più questa domanda?'), [conferma]);
        check('il messaggio dopo il clic', messaggio.includes('La domanda non è più pubblicata: non compare più in «L’esperto risponde».'), [messaggio]);
        check('la domanda è «Non pubblicata», non cancellata', domanda('stato') === 'discarded');
    } finally {
        domanda('togli');
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
