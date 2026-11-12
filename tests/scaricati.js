/**
 * Le date nei file scaricati (06/11, Alessandro): pensate per lavorare in
 * Excel. Nell'XLSX ogni colonna di date ha date vere (un numero con il
 * formato «16/09/2026 18:00»); nel CSV escono come «2026-09-16 18:00». Mai il
 * formato del database con i secondi, mai «16/09/2026» come testo.
 *
 * Scarica, da Amministratore, tutti i file dei report e degli utenti nei due
 * formati, e guarda ogni colonna il cui titolo e' una data.
 *
 * Esecuzione:  node tests/scaricati.js   (server di sviluppo acceso)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const RADICE = path.join(__dirname, '..');

/** I titoli delle colonne che contengono date. */
const DATE = ['Iscritto il', 'Completato il', 'Ingresso', 'Quando', 'Ultima attività il', 'Ultima visita al corso',
    'Prima visita', 'Ultima visita', 'Completata il', 'Registrato il', 'Email verificata il', 'Creato il',
    'Ultimo accesso', 'Aggiornato il', 'Data', 'Email verificata', 'Ultima modifica'];

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.forEach((d) => console.log('         · ' + d));
    }
}

function csv(testo) {
    const righe = [];
    let riga = [], campo = '', virgolette = false;
    const t = testo.replace(/^\uFEFF/, '');
    for (let i = 0; i < t.length; i++) {
        const c = t[i];
        if (virgolette) {
            if (c === '"' && t[i + 1] === '"') { campo += '"'; i++; } else if (c === '"') { virgolette = false; } else { campo += c; }
        } else if (c === '"') { virgolette = true; } else if (c === ';') { riga.push(campo); campo = ''; } else if (c === '\n') {
            riga.push(campo.replace(/\r$/, '')); righe.push(riga); riga = []; campo = '';
        } else { campo += c; }
    }
    if (campo !== '' || riga.length) { riga.push(campo); righe.push(riga); }
    return righe;
}

/** Le celle di un XLSX: [riga][colonna] = {tipo, stile, valore}. */
function xlsx(file) {
    const json = execFileSync('php', ['-r', `
        $z = new ZipArchive(); $z->open($argv[1]); $x = $z->getFromName('xl/worksheets/sheet1.xml');
        preg_match_all('/<c r="([A-Z]+)(\\d+)"([^>]*)>(.*?)<\\/c>/s', $x, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $c) {
            preg_match('/ s="(\\d+)"/', $c[3], $s); preg_match('/ t="(\\w+)"/', $c[3], $t);
            $v = preg_match('/<v>(.*?)<\\/v>/s', $c[4], $vv) ? $vv[1] : html_entity_decode(strip_tags($c[4]));
            $col = 0; foreach (str_split($c[1]) as $l) { $col = $col * 26 + ord($l) - 64; }
            $out[(int) $c[2]][$col - 1] = ['t' => $t[1] ?? 'n', 's' => $s[1] ?? '0', 'v' => $v];
        }
        echo json_encode($out);`, '--', file], { encoding: 'utf8' });
    return JSON.parse(json);
}

const COME_DATA = /^\d{1,4}[-/]\d{1,2}[-/]\d{2,4}/;

(async () => {
    const semina = JSON.parse(execFileSync('php', [path.join(RADICE, 'tests', 'semina_permessi.php')], { encoding: 'utf8', cwd: RADICE }));
    const A = semina.A;
    const lezione = execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' echo (int) App\\Core\\Database::connection()->query("SELECT l.id FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = ' + Number(A.corso) + ' ORDER BY l.id LIMIT 1")->fetchColumn();'],
    { cwd: RADICE, encoding: 'utf8' }).trim();

    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', 'admin@test.it');
    await page.fill('input[name="password"]', 'Password1!');
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');

    const live = execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php";'
        + ' echo (int) App\\Core\\Database::connection()->query("SELECT id FROM live_sessions ORDER BY starts_at LIMIT 1")->fetchColumn();'],
    { cwd: RADICE, encoding: 'utf8' }).trim();

    const file = [
        ['Studenti fermi', '/reports/fermi/{f}?giorni=0'],
        ['Report del corso', '/reports/courses/' + A.corso + '/{f}'],
        ['Griglia del corso', '/reports/courses/' + A.corso + '/griglia/{f}'],
        ['Report dello studente', '/reports/students/' + A.studente + '/{f}'],
        ['Report del gruppo', '/reports/groups/' + A.gruppo + '/{f}'],
        ['Report dell\'incontro', '/reports/live/' + live + '/{f}'],
        ['Fruizione del corso', '/reports/fruizione/' + A.corso + '/{f}'],
        ['Fruizione della lezione', '/lessons/' + lezione + '/fruizione/{f}'],
        ['Utenti', '/admin/users/{f}'],
    ];
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'scaricati-'));

    try {
        for (const [nome, url] of file) {
            // --- CSV
            const r = await ctx.request.get(BASE + url.replace('{f}', 'csv'));
            const righe = csv(await r.text());
            const testa = righe[0] || [];
            const colonne = testa.map((h, i) => [h, i]).filter(([h]) => DATE.includes(h)).map(([, i]) => i);
            // Nel file dello studente gli incontri hanno una seconda intestazione.
            const sbagliate = [];
            let dateViste = 0;
            for (const riga of righe.slice(1)) {
                for (const i of colonne) {
                    const v = (riga[i] || '').trim();
                    if (v === '' || !COME_DATA.test(v)) continue;
                    dateViste++;
                    if (!/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/.test(v)) sbagliate.push(testa[i] + ': ' + v);
                }
            }
            check(nome + ', CSV: ' + dateViste + ' date, tutte «AAAA-MM-GG HH:MM»', r.status() === 200 && sbagliate.length === 0,
                [String(r.status())].concat(sbagliate.slice(0, 4)));

            // --- XLSX
            const rx = await ctx.request.get(BASE + url.replace('{f}', 'xlsx'));
            const percorso = path.join(tmp, 'f.xlsx');
            fs.writeFileSync(percorso, await rx.body());
            const celle = xlsx(percorso);
            const testaX = Object.values(celle[1] || {}).map((c) => c.v);
            const colX = testaX.map((h, i) => [h, i]).filter(([h]) => DATE.includes(h)).map(([, i]) => i);
            const testo = [];
            let vere = 0;
            for (const [n, riga] of Object.entries(celle)) {
                if (Number(n) === 1) continue;
                for (const i of colX) {
                    const c = riga[i];
                    if (!c) continue;
                    if ((c.s === '2' || c.s === '3') && c.t === 'n') { vere++; continue; }
                    if (COME_DATA.test(c.v)) testo.push(testaX[i] + ': ' + c.v);
                }
            }
            check(nome + ', XLSX: ' + vere + ' date vere, nessuna data scritta come testo', rx.status() === 200 && testo.length === 0,
                [String(rx.status())].concat(testo.slice(0, 4)));
        }
    } catch (e) {
        check('la prova e\' arrivata in fondo', false, [String(e && e.stack || e)]);
    } finally {
        await browser.close();
        fs.rmSync(tmp, { recursive: true, force: true });
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
