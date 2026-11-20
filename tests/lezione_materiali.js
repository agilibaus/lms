/**
 * I materiali scaricabili gia' alla creazione della lezione (20/11,
 * Alessandro, variante C).
 *
 * In «Nuova lezione» un riquadro «Materiali scaricabili», come quello degli
 * esercizi H5P: i file partono con «Crea lezione». Se uno non va (tipo non
 * ammesso, troppo grande) la lezione non si crea, e la pagina torna con i
 * campi come li aveva lasciati chi scrive; i file vanno scelti di nuovo.
 * Nella modifica i materiali restano nella loro sezione, con «Carica».
 *
 * Esecuzione:  node tests/lezione_materiali.js   (server di sviluppo acceso,
 * semina gia' fatta: lavora su un modulo del corso «[prova-permessi] mondo A»)
 *
 * Le lezioni che crea si chiamano «[materiali-nuovi] …»: alla fine le toglie,
 * con i loro file.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const MARCHIO = '[materiali-nuovi]';

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

const json = (sql, ...a) => JSON.parse(php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo json_encode($s->fetchAll());', sql, ...a));

function pulisci() {
    // Le lezioni di prova e i loro materiali, anche i file sul disco.
    php('$s = $pdo->prepare("SELECT id FROM lessons WHERE title LIKE ?"); $s->execute([$argv[1] . "%"]);'
        + ' foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {'
        + '   foreach (glob("storage/materials/" . (int) $id . "/*") ?: [] as $f) { @unlink($f); }'
        + '   @rmdir("storage/materials/" . (int) $id);'
        + '   $pdo->prepare("DELETE FROM lessons WHERE id = ?")->execute([$id]);'
        + ' }', MARCHIO);
}

(async () => {
    pulisci();
    const modulo = json(
        "SELECT m.id FROM modules m JOIN courses c ON c.id = m.course_id WHERE c.title = '[prova-permessi] mondo A' AND COALESCE(m.is_final, 0) = 0 ORDER BY m.id LIMIT 1"
    )[0].id;
    const lezioni = () => json('SELECT id, title, content_html, duration_seconds, video_provider, video_ref FROM lessons WHERE module_id = ? AND title LIKE ?', modulo, MARCHIO + '%');
    const materiali = (id) => json('SELECT file_name FROM lesson_materials WHERE lesson_id = ? ORDER BY position, id', id).map((r) => r.file_name);

    const cartella = fs.mkdtempSync(path.join(os.tmpdir(), 'mat-'));
    const file = (nome, byte = 2048) => { const p = path.join(cartella, nome); fs.writeFileSync(p, Buffer.alloc(byte, 65)); return p; };
    const scheda = file('Scheda pratica.pdf');
    const slide = file('Presentazione tappa 1.pptx');
    const video = file('video-tappa1.mov');

    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    const compila = async (titolo, materialiScelti) => {
        await page.goto(BASE + '/modules/' + modulo + '/lessons/create');
        await page.waitForFunction(() => window.tinymce && window.tinymce.get().length > 0);
        await page.fill('#title', titolo);
        await page.evaluate(() => window.tinymce.get()[0].setContent('<p>Un esercizio di respiro prima di dormire.</p>'));
        await page.fill('#duration_seconds', '420');
        await page.selectOption('#video_provider', 'bunny');
        await page.fill('#video_ref_bunny', 'abc-123');
        if (materialiScelti.length > 0) {
            await page.setInputFiles('#materials', materialiScelti);
        }
        await Promise.all([page.waitForLoadState('networkidle'), page.click('form.lesson-form button[type="submit"].btn-primary')]);
    };

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        console.log('--- la pagina');
        await page.goto(BASE + '/modules/' + modulo + '/lessons/create');
        const riquadro = await page.evaluate(() => {
            const fs = [...document.querySelectorAll('form.lesson-form fieldset')];
            const i = fs.findIndex((f) => f.querySelector('legend')?.textContent.trim() === 'Materiali scaricabili');
            const campo = document.querySelector('form.lesson-form input[type="file"][name="materials[]"]');
            return { dopoH5P: i > 0 && fs[i - 1].id === 'contenuti-h5p', multiplo: !!campo?.multiple, prima: !!campo && campo.compareDocumentPosition(document.querySelector('form.lesson-form .form-actions')) === Node.DOCUMENT_POSITION_FOLLOWING };
        });
        check('in «Nuova lezione» il riquadro «Materiali scaricabili» sta dopo gli esercizi H5P e prima di «Crea lezione»',
            riquadro.dopoH5P && riquadro.multiplo && riquadro.prima, [JSON.stringify(riquadro)]);

        console.log('\n--- creare con i materiali');
        await compila(MARCHIO + ' con due materiali', [scheda, slide]);
        let l = lezioni();
        const successo = await page.evaluate(() => (document.querySelector('.alert-success')?.textContent || '').trim());
        check('la lezione nasce con i due materiali, nell\'ordine in cui sono stati scelti',
            l.length === 1 && JSON.stringify(materiali(l[0].id)) === JSON.stringify(['Scheda pratica.pdf', 'Presentazione tappa 1.pptx']), [JSON.stringify(l), l[0] ? JSON.stringify(materiali(l[0].id)) : '']);
        check('si arriva alla modifica, e l\'avviso lo dice',
            l.length === 1 && page.url() === BASE + '/lessons/' + l[0].id + '/edit' && successo === 'Lezione creata. 2 materiali caricati.', [page.url(), successo]);
        const inModifica = await page.evaluate(() => ({
            elenco: [...document.querySelectorAll('#materiali .material-name')].map((n) => n.textContent.trim()),
            riquadroNuovi: !!document.querySelector('#materiali-nuovi'),
        }));
        check('nella modifica i materiali sono nella loro sezione, e il riquadro della creazione non c\'è',
            inModifica.elenco.length === 2 && !inModifica.riquadroNuovi, [JSON.stringify(inModifica)]);
        pulisci();

        console.log('\n--- un file che non va');
        await compila(MARCHIO + ' con un video fra i materiali', [scheda, video]);
        l = lezioni();
        const tornata = await page.evaluate(() => ({
            errore: (document.querySelector('.alert-error')?.textContent || '').trim(),
            titolo: document.querySelector('#title')?.value,
            testo: window.tinymce?.get()[0]?.getContent() ?? document.querySelector('#content_html')?.value,
            durata: document.querySelector('#duration_seconds')?.value,
            provider: document.querySelector('#video_provider')?.value,
            bunny: document.querySelector('#video_ref_bunny')?.value,
            bunnyVisibile: document.querySelector('#video_ref_bunny')?.checkVisibility(),
        }));
        check('la lezione non si crea, e nessun materiale',
            l.length === 0 && json('SELECT COUNT(*) n FROM lesson_materials WHERE file_name = ?', 'Scheda pratica.pdf').every((r) => Number(r.n) === 0), [JSON.stringify(l)]);
        check('l\'avviso dice quale file e perché, e che i file vanno scelti di nuovo',
            tornata.errore.startsWith('La lezione non è stata creata: Formato file non consentito per "video-tappa1.mov"')
                && tornata.errore.endsWith('i file vanno scelti di nuovo, perché il browser non li ricorda.'), [tornata.errore]);
        check('titolo, testo, durata e video sono come li aveva lasciati',
            tornata.titolo === MARCHIO + ' con un video fra i materiali' && tornata.testo.includes('Un esercizio di respiro prima di dormire.')
                && tornata.durata === '420' && tornata.provider === 'bunny' && tornata.bunny === 'abc-123' && tornata.bunnyVisibile, [JSON.stringify(tornata)]);

        // Ripreso da qui, con i file scelti di nuovo, si crea.
        await page.setInputFiles('#materials', [scheda]);
        await Promise.all([page.waitForLoadState('networkidle'), page.click('form.lesson-form button[type="submit"].btn-primary')]);
        l = lezioni();
        check('dalla pagina tornata, scelto di nuovo un file giusto, la lezione nasce con i campi di prima',
            l.length === 1 && l[0].duration_seconds == 420 && l[0].video_ref === 'abc-123' && JSON.stringify(materiali(l[0].id)) === JSON.stringify(['Scheda pratica.pdf']), [JSON.stringify(l)]);

        console.log('\n--- senza materiali, e nella modifica');
        await compila(MARCHIO + ' senza materiali', []);
        const senza = lezioni().find((x) => x.title === MARCHIO + ' senza materiali');
        const avvisoSenza = await page.evaluate(() => (document.querySelector('.alert-success')?.textContent || '').trim());
        check('senza materiali si crea come prima', senza !== undefined && materiali(senza.id).length === 0 && avvisoSenza === 'Lezione creata.', [avvisoSenza]);

        await page.setInputFiles('#materiali input[name="materials[]"]', [slide]);
        await Promise.all([page.waitForLoadState('networkidle'), page.click('#materiali button[type="submit"]')]);
        check('nella modifica «Carica» funziona come prima', JSON.stringify(materiali(senza.id)) === JSON.stringify(['Presentazione tappa 1.pptx']), [JSON.stringify(materiali(senza.id))]);
    } finally {
        await browser.close();
        pulisci();
        fs.rmSync(cartella, { recursive: true, force: true });
    }

    console.log('\n' + ok + ' OK, ' + fail + ' FAIL');
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    pulisci();
    process.exit(1);
});
