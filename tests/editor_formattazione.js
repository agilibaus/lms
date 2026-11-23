/**
 * La formattazione libera nell'editor (20/11, Alessandro).
 *
 * Il testo non formattato segue Impostazioni › Aspetto, anche dentro
 * l'editor; il testo formattato a mano dal menu Formato (carattere,
 * dimensione, colore, sfondo, allineamento, altezza della riga) si salva
 * cosi' com'e' e lo studente lo vede. Nel menu Formato › Caratteri ci sono i
 * caratteri scaricati sul server e tre generici, non l'elenco di TinyMCE.
 * Il salvataggio tiene solo quelle proprieta' (`HtmlSanitizer::STILE`).
 *
 * Esecuzione:  node tests/editor_formattazione.js   (server di sviluppo
 * acceso, semina gia' fatta: lavora su una lezione del corso
 * «[prova-permessi] mondo A» e sull'informativa sulla privacy)
 *
 * Mette in `storage/fonts` due caratteri «scaricati» (il file di Albert Sans
 * del progetto, con il nome di due famiglie del catalogo) e sceglie il primo
 * come carattere dell'applicazione. Alla fine rimette impostazione, file,
 * lezione e informativa come li ha trovati.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const FONT = path.join(RADICE, 'storage', 'fonts');
const SCELTO = 'Albert Sans';
const ALTRO = 'Playfair Display';

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

const uno = (sql, ...a) => php('$s = $pdo->prepare($argv[1]); $s->execute(array_slice($argv, 2)); $v = $s->fetchColumn(); echo $v === false || $v === null ? "\\x00" : $v;', sql, ...a);
const esegui = (sql, ...a) => php('$pdo->prepare($argv[1])->execute(array_slice($argv, 2));', sql, ...a);
const nomeFile = (f) => php('echo App\\Core\\FontLibrary::nomeFile($argv[1]);', f).trim();

(async () => {
    // Quello che c'e' prima, per rimetterlo.
    const impostazione = uno("SELECT setting_value FROM settings WHERE setting_key = 'THEME_FONT_APP'");
    const lezione = uno("SELECT l.id FROM lessons l JOIN modules m ON m.id = l.module_id JOIN courses c ON c.id = m.course_id WHERE c.title = '[prova-permessi] mondo A' ORDER BY l.id LIMIT 1");
    const testoLezione = uno('SELECT content_html FROM lessons WHERE id = ?', lezione);
    const fileFont = [SCELTO, ALTRO].map((f) => path.join(FONT, nomeFile(f)));
    const fontCreati = fileFont.filter((f) => !fs.existsSync(f));
    const privacy = php('echo json_encode(App\\Models\\PrivacyModel::inVigore());').trim();

    fs.mkdirSync(FONT, { recursive: true });
    for (const f of fontCreati) fs.copyFileSync(path.join(RADICE, 'public', 'assets', 'fonts', 'albert-sans-latin.woff2'), f);
    esegui("INSERT INTO settings (setting_key, setting_value) VALUES ('THEME_FONT_APP', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", SCELTO);

    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();

    try {
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('form.auth-form button[type="submit"]');
        await page.waitForLoadState('networkidle');

        console.log('--- l\'editor della lezione');
        await page.goto(BASE + '/lessons/' + lezione + '/edit');
        await page.waitForFunction(() => window.tinymce && window.tinymce.get().length > 0 && window.tinymce.get()[0].initialized);
        const base = await page.evaluate(() => getComputedStyle(window.tinymce.get()[0].getBody()).fontFamily);
        check('il testo non formattato, nell\'editor, è nel carattere di Aspetto', base.startsWith('"' + 'Albert Sans' + '"'), [base]);

        await page.click('.tox-mbtn:has-text("Formato")');
        await page.hover('.tox-collection__item:has-text("Caratteri")');
        await page.waitForTimeout(300);
        const elenco = await page.$$eval('.tox-menu', (m) => [...m.at(-1).querySelectorAll('.tox-collection__item-label')].map((x) => x.textContent.trim()));
        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
        check('Formato › Caratteri: i caratteri scaricati e i tre generici, non l\'elenco di TinyMCE',
            JSON.stringify(elenco) === JSON.stringify([SCELTO, ALTRO, 'Senza grazie', 'Con grazie', 'A spaziatura fissa']), [JSON.stringify(elenco)]);

        // Una parola grande, colorata, in un altro carattere; un paragrafo
        // centrato; e due cose che il salvataggio deve togliere.
        await page.evaluate(([altro]) => {
            const ed = window.tinymce.get()[0];
            ed.setContent('<p>Respira <span id="p1">piano</span> adesso.</p><p id="p2">Secondo paragrafo.</p>');
            ed.selection.select(ed.dom.get('p1'));
            ed.execCommand('FontName', false, '"' + altro + '", serif');
            ed.execCommand('FontSize', false, '24pt');
            ed.execCommand('ForeColor', false, '#E03E2D');
            ed.execCommand('HiliteColor', false, '#FBEEB8');
            ed.selection.select(ed.dom.get('p2'));
            ed.execCommand('JustifyCenter');
            ed.execCommand('LineHeight', false, '1.5');
            ed.getBody().insertAdjacentHTML('beforeend', '<p style="position: fixed; top: 0; color: blue">Terzo</p><p style="background-image: url(https://esempio.invalid/x.png)">Quarto</p>');
        }, [ALTRO]);
        await Promise.all([page.waitForLoadState('networkidle'), page.click('form.lesson-form .form-actions button[type="submit"].btn-primary')]);
        const salvato = uno('SELECT content_html FROM lessons WHERE id = ?', lezione);
        check('il salvataggio tiene carattere, dimensione, colore, sfondo, allineamento e altezza della riga',
            /font-family: ["']Playfair Display["'], serif;/.test(salvato) && salvato.includes('font-size: 24pt;')
                && /color: (#E03E2D|rgb\(224, 62, 45\));/i.test(salvato) && /background-color: (#FBEEB8|rgb\(251, 238, 184\));/i.test(salvato)
                && salvato.includes('text-align: center;') && salvato.includes('line-height: 1.5;'), [salvato]);
        check('e toglie il resto: niente posizioni, niente url()',
            !salvato.includes('position') && !salvato.includes('url(') && salvato.includes('<p style="color: blue;">Terzo</p>') && salvato.includes('<p>Quarto</p>'), [salvato]);

        console.log('\n--- la lezione pubblicata');
        await page.goto(BASE + '/lessons/' + lezione);
        const vista = await page.evaluate(() => {
            const c = document.querySelector('.lesson-content');
            const parola = [...c.querySelectorAll('span')].find((s) => s.textContent === 'piano');
            const centrato = [...c.querySelectorAll('p')].find((p) => p.textContent === 'Secondo paragrafo.');
            const g = (el) => (el ? getComputedStyle(el) : null);
            return {
                base: g(c).fontFamily,
                parola: parola ? { font: g(parola).fontFamily, size: g(parola).fontSize, color: g(parola).color, sfondo: g(parola).backgroundColor } : null,
                centrato: centrato ? g(centrato).textAlign : null,
                fontFace: [...document.querySelectorAll('style')].some((s) => s.textContent.includes('font-family:"Playfair Display"')),
                // Il capolettera automatico non c'e' piu' (20/11): la prima
                // lettera ha la misura e il colore del suo paragrafo.
                capolettera: (() => {
                    const p = c.querySelector(':scope > p');
                    const l = getComputedStyle(p, '::first-letter');
                    const ini = l.getPropertyValue('initial-letter') || l.getPropertyValue('-webkit-initial-letter') || 'normal';
                    return { size: l.fontSize, sizeP: g(p).fontSize, color: l.color, colorP: g(p).color, float: l.float, initial: ini };
                })(),
            };
        });
        check('il testo non formattato è nel carattere di Aspetto', vista.base.startsWith('"Albert Sans"'), [vista.base]);
        check('la parola formattata ha carattere, dimensione, colore e sfondo scelti, e il paragrafo è centrato',
            vista.parola !== null && vista.parola.font.startsWith('"Playfair Display"') && vista.parola.size === '32px'
                && vista.parola.color === 'rgb(224, 62, 45)' && vista.parola.sfondo === 'rgb(251, 238, 184)' && vista.centrato === 'center', [JSON.stringify(vista)]);
        check('la pagina dichiara il carattere scaricato che la lezione usa', vista.fontFace, [JSON.stringify(vista)]);
        const cl = vista.capolettera;
        check('il testo della lezione non comincia più con il capolettera automatico',
            cl.size === cl.sizeP && cl.color === cl.colorP && cl.float === 'none' && /^(normal|)$/.test(cl.initial), [JSON.stringify(cl)]);

        console.log('\n--- l\'informativa sulla privacy');
        await page.goto(BASE + '/admin/settings/privacy');
        await page.waitForFunction(() => window.tinymce && window.tinymce.get().length > 0 && window.tinymce.get()[0].initialized);
        const basePrivacy = await page.evaluate(() => getComputedStyle(window.tinymce.get()[0].getBody()).fontFamily);
        check('anche nel suo editor il testo è nel carattere di Aspetto', basePrivacy.startsWith('"Albert Sans"'), [basePrivacy]);
        const tenuto = php('echo App\\Core\\HtmlSanitizer::clean($argv[1], App\\Core\\Privacy::TAG);',
            '<p style="text-align: center;">Titolare: <span style="font-size: 18pt; color: #169179;">Associazione Esempio</span></p>').trim();
        check('e il suo salvataggio tiene la formattazione come quello delle lezioni',
            tenuto === '<p style="text-align: center;">Titolare: <span style="font-size: 18pt; color: #169179;">Associazione Esempio</span></p>', [tenuto]);
    } finally {
        await browser.close();
        esegui('UPDATE lessons SET content_html = ? WHERE id = ?', testoLezione === '\u0000' ? null : testoLezione, lezione);
        if (impostazione === '\u0000') {
            esegui("DELETE FROM settings WHERE setting_key = 'THEME_FONT_APP'");
        } else {
            esegui("UPDATE settings SET setting_value = ? WHERE setting_key = 'THEME_FONT_APP'", impostazione);
        }
        for (const f of fontCreati) fs.rmSync(f, { force: true });
        const dopo = php('echo json_encode(App\\Models\\PrivacyModel::inVigore());').trim();
        if (dopo !== privacy) console.log('  ATTENZIONE: l\'informativa è cambiata durante la prova');
    }

    console.log('\n' + ok + ' OK, ' + fail + ' FAIL');
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
