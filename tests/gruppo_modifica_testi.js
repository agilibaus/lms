/**
 * «Modifica gruppo» (27/11, Alessandro): il testo del riquadro «Corsi
 * assegnati» per l'Amministratore e per il tutor, che i corsi non li assegna,
 * e la frase accanto a «Elimina gruppo»,
 * «Rimuove …», con il verbo che non cambia con il numero delle voci.
 *
 * La frase di «Elimina gruppo» si prova in tutte le combinazioni chiedendola a
 * GroupModel::cosaSparisce(), senza toccare il database; con un gruppo vero,
 * pagina e conferma, la prova tests/elimina_gruppo.js.
 *
 * Esecuzione:  node tests/gruppo_modifica_testi.js   (server di sviluppo acceso;
 * nel database un gruppo e un gruppo di tutor1@test.it, che il test cerca e
 * non crea)
 */
'use strict';

const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

const CORSI_ASSEGNATI = 'Assegnare un corso a un gruppo iscrive tutti i membri del gruppo e chi entra dopo viene '
    + 'iscritto automaticamente. Un corso in bozza li iscrive quando viene pubblicato. Rimuovendo il corso le '
    + 'iscrizioni già create restano attive. Ciascun gruppo può avere le proprie date di apertura dei moduli.';

const CORSI_ASSEGNATI_TUTOR = 'I corsi del gruppo li assegna l’Amministratore. Questo gruppo può avere le sue date di apertura.';

const RESTANO = 'Gli studenti restano iscritti ai corsi.';
const FRASI = [
    [2, 1, 'Rimuove 2 sessioni live concluse, con relative presenze, e 1 domanda del giro del cerchio. ' + RESTANO],
    [1, 1, 'Rimuove 1 sessione live conclusa, con relative presenze, e 1 domanda del giro del cerchio. ' + RESTANO],
    [1, 0, 'Rimuove 1 sessione live conclusa, con relative presenze. ' + RESTANO],
    [0, 3, 'Rimuove 3 domande del giro del cerchio. ' + RESTANO],
    [0, 0, RESTANO],
];

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

function php(codice, ...argomenti) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";' + codice, ...argomenti],
        { cwd: RADICE }).toString();
}

(async () => {
    for (const [conclusi, domande, attesa] of FRASI) {
        const frase = php('echo \\App\\Models\\GroupModel::cosaSparisce(["in_programma" => [], "conclusi" => (int) $argv[1], "domande" => (int) $argv[2]]);',
            String(conclusi), String(domande));
        check(`«Elimina gruppo», ${conclusi} concluse e ${domande} domande: «${attesa}»`, frase === attesa, [frase]);
    }

    const gruppo = php('echo (int) \\App\\Core\\Database::connection()->query("SELECT id FROM `groups` ORDER BY id LIMIT 1")->fetchColumn();');
    const delTutor = php('echo (int) \\App\\Core\\Database::connection()->query("SELECT g.id FROM `groups` g JOIN users u ON u.id = g.tutor_id '
        + 'WHERE u.email = \'tutor1@test.it\' ORDER BY g.id LIMIT 1")->fetchColumn();');
    const corsiAssegnati = (page) => page.evaluate(() => [...document.querySelectorAll('main section.card')]
        .filter((s) => (s.querySelector('h2')?.textContent || '').trim() === 'Corsi assegnati')
        .map((s) => (s.querySelector('.riquadro-intro')?.textContent || '').replace(/\s+/g, ' ').trim()));
    const browser = await chromium.launch();
    const entra = async (email) => {
        const page = await (await browser.newContext()).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
        return page;
    };
    try {
        const admin = await entra('admin@test.it');
        await admin.goto(BASE + '/admin/groups/' + gruppo + '/edit');
        const testi = await corsiAssegnati(admin);
        check('«Corsi assegnati»: il testo del riquadro', testi.length === 1 && testi[0] === CORSI_ASSEGNATI, testi);

        if (Number(delTutor) > 0) {
            const tutor = await entra('tutor1@test.it');
            await tutor.goto(BASE + '/admin/groups/' + delTutor + '/edit');
            const perTutor = await corsiAssegnati(tutor);
            check('«Corsi assegnati», per il tutor: il testo del riquadro', perTutor.length === 1 && perTutor[0] === CORSI_ASSEGNATI_TUTOR, perTutor);
        } else {
            check('«Corsi assegnati», per il tutor: nel database c\'è un gruppo di tutor1@test.it', false);
        }
    } finally {
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
