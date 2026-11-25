/**
 * «Modifica lezione» su telefono con un video caricato sul server (25/11,
 * Alessandro): il percorso del file, nel testo di aiuto del riquadro
 * «Video», e' una parola sola («videos/2/b3746806….mp4») e allargava il
 * riquadro a 452 px su uno schermo di 375. Ora va a capo dove serve e la
 * pagina non scorre di lato, a 375 e a 320 px.
 *
 * La prova mette su una lezione un video caricato sul server, con un
 * percorso lungo come quelli veri, e alla fine la rimette com'era.
 *
 * Esecuzione:  node tests/lezione_video_telefono.js   (server di sviluppo acceso)
 */
'use strict';

const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    if (!condizione) {
        dettagli.slice(0, 6).forEach((d) => console.log('         · ' + d));
    }
}

/** Legge (senza argomento) o scrive il video della prima lezione; restituisce quello di prima. */
function video(nuovo) {
    const codice = 'require "vendor/autoload.php"; require "config/config.php";'
        + '$pdo = \\App\\Core\\Database::connection();'
        + '$l = $pdo->query("SELECT id, video_provider, video_ref FROM lessons ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);'
        + 'echo json_encode($l);'
        + '$n = json_decode($argv[1], true);'
        + 'if (is_array($n)) { $pdo->prepare("UPDATE lessons SET video_provider = ?, video_ref = ? WHERE id = ?")->execute([$n["video_provider"], $n["video_ref"], $l["id"]]); }';
    return JSON.parse(execFileSync('php', ['-r', codice, JSON.stringify(nuovo ?? null)], { cwd: RADICE }).toString());
}

(async () => {
    const browser = await chromium.launch();
    const prima = video();
    try {
        video({ video_provider: 'self_hosted', video_ref: 'videos/' + prima.id + '/b3746806187fd7239e6e813d8408b466.mp4' });
        for (const larghezza of [375, 320]) {
            const page = await (await browser.newContext({ viewport: { width: larghezza, height: 800 } })).newPage();
            await page.goto(BASE + '/login');
            await page.fill('input[name="email"]', 'admin@test.it');
            await page.fill('input[name="password"]', PASSWORD);
            await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
            await page.goto(BASE + '/lessons/' + prima.id + '/edit');
            const r = await page.evaluate(() => {
                const W = document.documentElement.clientWidth;
                const campo = document.querySelector('.video-provider-fields[data-provider="self_hosted"]');
                const larghi = [...document.querySelectorAll('fieldset.video-fieldset, fieldset.video-fieldset *')]
                    .filter((e) => e.getBoundingClientRect().right > W + 1)
                    .slice(0, 3).map((e) => e.tagName.toLowerCase() + '.' + e.className + ' ' + Math.round(e.getBoundingClientRect().right));
                return {
                    visibile: !!campo && !campo.hidden && !!campo.querySelector('code'),
                    scorre: document.documentElement.scrollWidth > W + 1,
                    larghi,
                };
            });
            check(larghezza + ' px: il file del video attuale si vede', r.visibile);
            check(larghezza + ' px: il riquadro «Video» sta nello schermo e la pagina non scorre di lato',
                !r.scorre && r.larghi.length === 0, r.larghi);
            await page.close();
        }
    } finally {
        video({ video_provider: prima.video_provider, video_ref: prima.video_ref });
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
