/**
 * La verifica pubblica di un certificato (24/11, Alessandro, variante B del
 * mockup): nella cornice delle pagine pubbliche, sempre il riquadro
 * centrato anche quando l'aspetto scelto e' «affiancato», con l'esito negli
 * avvisi verde e rosso. La apre quasi sempre un estraneo.
 *
 * Con l'aspetto messo ad «affiancato» per la prova (e rimesso com'era), su
 * un certificato valido, lo stesso revocato (e poi rimesso), e un codice
 * che non esiste:
 *
 *   1. il riquadro centrato, «Pistacchio LMS» come titolo, il sottotitolo
 *      «Controllo di un attestato di frequenza», la scheda «Verifica
 *      certificato · Pistacchio LMS»;
 *   2. l'esito: «Certificato valido.» in verde, «Certificato revocato: non
 *      è più valido.» e «Nessun certificato corrisponde al codice
 *      indicato.» in rosso;
 *   3. i dati: intestatario, corso, data, codice (e «Revocato il»); per il
 *      codice che non esiste, «Codice cercato»;
 *   4. a 320 px la pagina sta nello schermo.
 *
 * Esecuzione:  node tests/verifica_certificato.js   (server di sviluppo
 * acceso, semina dei permessi fatta)
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
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

function php(codice, ...arg) {
    return execFileSync('php', ['-r', 'require "vendor/autoload.php"; require "config/config.php"; $pdo = App\\Core\\Database::connection(); ' + codice, '--', ...arg],
        { cwd: RADICE, encoding: 'utf8' }).trim();
}

(async () => {
    const aspettoPrima = php('echo (string) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = \'AUTH_LAYOUT\'")->fetchColumn() ?: "");');
    const codice = php('echo (string) $pdo->query("SELECT certificate_code FROM certificates WHERE certificate_code LIKE \'PROVA-%\' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();');
    const aspetto = (v) => php('if ($argv[1] === "") { $pdo->exec("DELETE FROM settings WHERE setting_key = \'AUTH_LAYOUT\'"); } else { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (\'AUTH_LAYOUT\', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$argv[1]]); }', v);
    const revoca = (si) => php('$pdo->prepare("UPDATE certificates SET revoked_at = " . ($argv[2] === "1" ? "NOW()" : "NULL") . " WHERE certificate_code = ?")->execute([$argv[1]]);', codice, si ? '1' : '0');

    const browser = await chromium.launch();
    try {
        check('c\'e\' un certificato della semina da verificare', codice !== '', ['semina dei permessi da fare']);
        aspetto('affiancato');
        const page = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
        const leggi = async (c) => {
            await page.goto(BASE + '/verify/' + encodeURIComponent(c));
            return page.evaluate(() => ({
                scheda: document.title,
                riquadro: !!document.querySelector('body.auth-body main.auth-card') && !document.querySelector('.auth-split'),
                h1: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
                sottotitolo: (document.querySelector('.auth-subtitle') || {}).textContent?.trim(),
                esito: [...document.querySelectorAll('.alert')].map((a) => a.className.replace('alert ', '') + ': ' + a.textContent.trim()),
                dati: [...document.querySelectorAll('.verify-details dt')].map((d) => d.textContent.trim()),
            }));
        };

        const comune = (r, nome) => check(nome + ': riquadro centrato anche con l\'aspetto «affiancato», titolo, sottotitolo e scheda',
            r.riquadro && JSON.stringify(r.h1) === '["Pistacchio LMS"]' && r.sottotitolo === 'Controllo di un attestato di frequenza'
            && r.scheda === 'Verifica certificato · Pistacchio LMS', [JSON.stringify(r)]);

        let r = await leggi(codice);
        comune(r, 'valido');
        check('valido: «Certificato valido.» in verde, con intestatario, corso, data e codice',
            JSON.stringify(r.esito) === '["alert-success: Certificato valido."]'
            && JSON.stringify(r.dati) === '["Intestatario","Corso","Data di rilascio","Codice"]', [JSON.stringify(r)]);

        revoca(true);
        r = await leggi(codice);
        comune(r, 'revocato');
        check('revocato: «Certificato revocato: non è più valido.» in rosso, con «Revocato il»',
            JSON.stringify(r.esito) === '["alert-error: Certificato revocato: non è più valido."]'
            && JSON.stringify(r.dati) === '["Intestatario","Corso","Data di rilascio","Revocato il","Codice"]', [JSON.stringify(r)]);
        revoca(false);

        r = await leggi('NON-ESISTE-123');
        comune(r, 'codice che non esiste');
        check('codice che non esiste: la frase in rosso e «Codice cercato»',
            JSON.stringify(r.esito) === '["alert-error: Nessun certificato corrisponde al codice indicato."]'
            && JSON.stringify(r.dati) === '["Codice cercato"]', [JSON.stringify(r)]);

        const tel = await (await browser.newContext({ viewport: { width: 320, height: 640 } })).newPage();
        for (const c of [codice, 'NON-ESISTE-123']) {
            await tel.goto(BASE + '/verify/' + encodeURIComponent(c));
            const sfora = await tel.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            check('a 320 px la pagina sta nello schermo (' + c + ')', sfora <= 0, ['sfora di ' + sfora + ' px']);
        }
    } finally {
        await browser.close();
        if (codice !== '') {
            revoca(false);
        }
        aspetto(aspettoPrima);
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail > 0 ? 1 : 0);
})();
