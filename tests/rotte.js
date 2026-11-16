/**
 * La tabella delle rotte (16/11, Alessandro): ogni rotta di
 * `app/routes.php`, per chi non e' entrato e per ogni ruolo, confrontata con
 * quello che dice `tests/rotte_tabella.js`.
 *
 * Due controlli:
 *
 *   1. LA TABELLA E' COMPLETA. Ogni rotta del codice ha la sua riga, e ogni
 *      riga una rotta del codice. Una pagina nuova senza riga fa diventare
 *      rossa la prova: chi la aggiunge deve decidere chi la apre.
 *   2. IL SITO FA QUELLO CHE DICE LA TABELLA. Per ogni riga e ogni profilo
 *      si chiede l'indirizzo d'esempio e si confronta la risposta con il
 *      codice atteso (S, L, R/…, 403, 404…; vedi la tabella).
 *
 * Le POST partono solo dove la tabella si aspetta un rifiuto, con un token
 * valido. Se un rifiuto cedesse, la POST scriverebbe davvero: per questo gli
 * esempi puntano ai dati della semina (che il giro dopo ricrea) e a utenti
 * creati qui, e le impostazioni si fotografano all'inizio e si rimettono
 * alla fine. Prima le POST che non tolgono niente, poi quelle che tolgono.
 *
 * Che cosa NON vede: che cosa c'e' dentro una pagina che si apre, e le
 * regole sul proprietario (il tutor di A sulle cose di A). Quelle restano in
 * `tests/permessi.js` e nelle suite dei singoli temi.
 *
 * Esecuzione:  node tests/rotte.js   (server di sviluppo acceso)
 *
 * Semina con `--lezioni-fatte` e alla fine risemina, come le altre suite;
 * crea «supervisore@rotte.test» e «studente@rotte.test» e alla fine li toglie.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { PROFILI, RIGHE } = require('./rotte_tabella.js');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const DOMINIO = '@rotte.test';

const ACCOUNT = {
    anonimo: null,
    studente: 'stud@test.it',
    assistente: 'assist@test.it',
    tutor: 'tutor1@test.it',
    supervisore: 'supervisore' + DOMINIO,
    admin: 'admin@test.it',
};

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    if (!condizione) {
        console.log('  FAIL ' + etichetta);
        dettagli.forEach((d) => console.log('         · ' + d));
    }
}

function php(codice, ...argomenti) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' $pdo = App\\Core\\Database::connection();' + codice, '--', ...argomenti.map(String)], { cwd: RADICE, encoding: 'utf8' });
}

function semina() {
    return JSON.parse(execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php'), '--lezioni-fatte'], { cwd: RADICE, encoding: 'utf8' }));
}

/** Le rotte del codice, come «METODO /rotta». */
function rotteDelCodice() {
    const testo = fs.readFileSync(path.join(RADICE, 'app', 'routes.php'), 'utf8');
    return [...testo.matchAll(/\$router->(get|post|postEsterna)\('([^']+)'/g)]
        .map((m) => (m[1] === 'get' ? 'GET' : 'POST') + ' ' + m[2]);
}

/** Sostituisce `{B.lezione}`, `{x.studente}`… con gli identificativi veri. */
function riempi(testo, valori) {
    return testo.replace(/\{(\w+)\.(\w+)\}/g, (tutto, gruppo, chiave) => {
        const v = valori[gruppo] && valori[gruppo][chiave];
        if (v === undefined) {
            throw new Error('segnaposto sconosciuto: ' + tutto);
        }
        return String(v);
    });
}

/** La risposta, nella lingua della tabella. */
function codice(risposta) {
    const stato = risposta.status();
    const dove = (risposta.headers().location || '').replace(BASE, '');

    if (stato === 200) {
        return 'S';
    }

    if (stato >= 300 && stato < 400) {
        return dove.startsWith('/login') ? 'L' : 'R' + dove;
    }

    return String(stato);
}

const toglieQualcosa = (rotta) => /delete|elimina|rimuovi|togli|scarta|revoke/.test(rotta);

(async () => {
    // --- 1. la tabella e' completa -------------------------------------------
    const nelCodice = rotteDelCodice();
    const inTabella = RIGHE.map((r) => r[0] + ' ' + r[1]);
    const doppie = inTabella.filter((r, i) => inTabella.indexOf(r) !== i);
    const mancano = nelCodice.filter((r) => !inTabella.includes(r));
    const avanzano = inTabella.filter((r) => !nelCodice.includes(r));
    check('ogni rotta del codice ha la sua riga (' + nelCodice.length + ' rotte)', mancano.length === 0, mancano.map((r) => 'manca: ' + r));
    check('ogni riga della tabella e\' una rotta del codice', avanzano.length === 0, avanzano.map((r) => 'non c\'e\' nel codice: ' + r));
    check('nessuna rotta compare due volte', doppie.length === 0, doppie);
    check('ogni riga ha un codice per ognuno dei ' + PROFILI.length + ' profili', RIGHE.every((r) => r.length === 3 + PROFILI.length),
        RIGHE.filter((r) => r.length !== 3 + PROFILI.length).map((r) => r[0] + ' ' + r[1]));

    // --- 2. il sito fa quello che dice la tabella ----------------------------
    const d = semina();
    php('$pdo->prepare("DELETE FROM users WHERE email LIKE ?")->execute(["%" . $argv[1]]);'
        + ' foreach ([["supervisore", "supervisore"], ["studente", "studente"]] as [$n, $r]) {'
        + ' $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, \'Prova\', \'Rotte\', ?, 1, NOW(), NOW())")'
        + '->execute([$n . $argv[1], password_hash($argv[2], PASSWORD_DEFAULT), $r]); }', DOMINIO, PASSWORD);
    const valori = {
        A: d.A,
        B: d.B,
        u: d.utenti,
        d,
        x: { studente: Number(php('$s = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $s->execute([$argv[1]]); echo (int) $s->fetchColumn();', 'studente' + DOMINIO)) },
    };
    // Le impostazioni, per rimetterle se una POST rifiutata scrivesse.
    const fotoImpostazioni = () => php('echo json_encode($pdo->query("SELECT setting_key, setting_value, updated_at, updated_by FROM settings ORDER BY setting_key")->fetchAll(PDO::FETCH_NUM));');
    const impostazioni = fotoImpostazioni();

    const ordinate = [...RIGHE].sort((a, b) => toglieQualcosa(a[1]) - toglieQualcosa(b[1]));
    const browser = await chromium.launch();
    let provate = 0;
    let saltate = 0;

    try {
        for (const [i, profilo] of PROFILI.entries()) {
            const ctx = await browser.newContext();
            const page = await ctx.newPage();
            const email = ACCOUNT[profilo];

            if (email !== null) {
                await page.goto(BASE + '/login');
                await page.fill('input[name="email"]', email);
                await page.fill('input[name="password"]', PASSWORD);
                await page.click('form.auth-form button[type="submit"]');
                await page.waitForLoadState('networkidle');
                if (page.url().includes('/login')) {
                    throw new Error('accesso non riuscito per ' + email);
                }
            }

            await page.goto(BASE + (email === null ? '/login' : '/profilo'));
            const token = await page.evaluate(() => document.querySelector('input[name="_token"]').value);

            for (const riga of ordinate) {
                const [metodo, rotta, esempio] = riga;
                const atteso = riga[3 + i];

                if (atteso === '-') {
                    saltate++;
                    continue;
                }

                const url = BASE + riempi(esempio, valori);
                const risposta = metodo === 'GET'
                    ? await ctx.request.get(url, { maxRedirects: 0 })
                    : await ctx.request.post(url, { form: { _token: token }, maxRedirects: 0 });
                const avuto = codice(risposta);
                provate++;
                check(profilo + ' → ' + metodo + ' ' + rotta, avuto === riempi(atteso, valori),
                    ['atteso ' + riempi(atteso, valori) + ', avuto ' + avuto + '  (' + riempi(esempio, valori) + ')']);
            }

            await ctx.close();
        }
    } finally {
        await browser.close();
        // Se una POST rifiutata ha scritto nelle impostazioni, e' gia' un
        // FAIL qui sopra; qui si rimettono com'erano, per le altre suite.
        const cambiate = fotoImpostazioni() !== impostazioni;
        check('le impostazioni sono come prima del giro', !cambiate, ['rimesse come erano']);
        if (cambiate) {
            php('$righe = json_decode($argv[1], true); $pdo->exec("DELETE FROM settings");'
                + ' $s = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_at, updated_by) VALUES (?, ?, ?, ?)"); foreach ($righe as $r) { $s->execute($r); }', impostazioni);
        }
        php('$pdo->prepare("DELETE FROM users WHERE email LIKE ?")->execute(["%" . $argv[1]]);', DOMINIO);
        semina();
    }

    console.log('\n  ' + RIGHE.length + ' rotte, ' + PROFILI.length + ' profili: ' + provate + ' risposte confrontate con la tabella, '
        + saltate + ' non provate («-»).');
    console.log('Totale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
