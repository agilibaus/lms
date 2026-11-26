/**
 * «Pagamenti ricevuti», nelle Impostazioni › Pagamenti: la colonna
 * «Studente» si ordina (26/11, Alessandro). Per nome, con l'email come
 * spareggio; chi non ha piu' un utente (nome «—») sta in fondo in tutti e
 * due i versi; filtrando per corso l'ordine resta. L'email sta in una
 * colonna sua accanto a «Studente», e si ordina anche lei; dopo il clic (o
 * il filtro) la pagina riparte dalla tabella, che sta in fondo.
 *
 * La prova registra quattro pagamenti finti (tre studenti, uno senza
 * utente) e alla fine li toglie.
 *
 * Esecuzione:  node tests/pagamenti_ordine.js   (server di sviluppo acceso)
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
        dettagli.slice(0, 8).forEach((d) => console.log('         · ' + d));
    }
}

/** «semina» registra i pagamenti finti e restituisce il corso; «togli» li cancella. */
function pagamenti(azione) {
    const codice = 'require "vendor/autoload.php"; require "config/config.php";'
        + '$pdo = \\App\\Core\\Database::connection();'
        + '$pdo->exec("DELETE FROM payments WHERE stripe_event_id LIKE \'prova-ordine-%\'");'
        + 'if ($argv[1] === "semina") {'
        + '  $corso = (int) $pdo->query("SELECT id FROM courses ORDER BY id LIMIT 1")->fetchColumn();'
        + '  $u = $pdo->query("SELECT id FROM users WHERE role = \'studente\' AND full_name <> \'\' ORDER BY full_name DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);'
        + '  $u[] = null;'
        + '  $st = $pdo->prepare("INSERT INTO payments (stripe_event_id, stripe_session_id, course_id, user_id, email, amount_total, amount_subtotal, currency, esito, received_at) VALUES (?, ?, ?, ?, ?, 1000, 1000, \'eur\', \'iscritto\', ?)");'
        + '  foreach ($u as $i => $id) { $st->execute(["prova-ordine-$i", "prova-ordine-s$i", $corso, $id, $id === null ? "senza-utente@prova.test" : null, date("Y-m-d H:i:s", time() - $i * 3600)]); }'
        + '  echo $corso;'
        + '}';
    return execFileSync('php', ['-r', codice, azione], { cwd: RADICE }).toString().trim();
}

(async () => {
    const browser = await chromium.launch();
    try {
        const corso = pagamenti('semina');
        const page = await (await browser.newContext()).newPage();
        await page.goto(BASE + '/login');
        await page.fill('input[name="email"]', 'admin@test.it');
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);

        const leggi = () => page.evaluate(() => [...document.querySelectorAll('.pagamenti-ricevuti tbody tr')]
            .map((r) => r.querySelector('td[data-label="Studente"]').childNodes[0].textContent.trim()));
        const finti = (nomi) => nomi.filter((n) => n !== '');

        await page.goto(BASE + '/admin/settings/pagamenti');
        const testa = await page.evaluate(() => {
            const th = [...document.querySelectorAll('.pagamenti-ricevuti th')];
            const i = th.findIndex((t) => t.textContent.trim().startsWith('Studente'));
            const righe = [...document.querySelectorAll('.pagamenti-ricevuti tbody tr')];
            return {
                link: th[i]?.querySelector('a.ordina')?.getAttribute('href') || null,
                dopo: th[i + 1]?.textContent.trim() || null,
                linkEmail: th[i + 1]?.querySelector('a.ordina')?.getAttribute('href') || null,
                emailSottoIlNome: righe.some((r) => r.querySelector('td[data-label="Studente"] .cell-sub')),
                email: righe.map((r) => r.querySelector('td[data-label="Email"]')?.textContent.trim() || null),
            };
        });
        const link = testa.link;
        check('«Studente» è un\'intestazione che ordina', link !== null, [String(link)]);
        check('accanto a «Studente» c\'è la colonna «Email», e sotto il nome non c\'è più',
            (testa.dopo || '').startsWith('Email') && !testa.emailSottoIlNome && testa.email.includes('senza-utente@prova.test'), [JSON.stringify(testa)]);
        check('il collegamento fa ripartire la pagina dalla tabella', link !== null && link.endsWith('#pagamenti-ricevuti'), [String(link)]);

        if (link) {
            // Su uno schermo basso la pagina scende fino alla tabella: in cima,
            // o tutta in fondo se dopo la tabella non c'e' altro da scorrere.
            for (const larghezza of [1280, 375]) {
                await page.setViewportSize({ width: larghezza, height: 500 });
                await page.goto(BASE + link);
                await page.waitForTimeout(200);
                const r = await page.evaluate(() => ({
                    cima: Math.round(document.getElementById('pagamenti-ricevuti').getBoundingClientRect().top),
                    scorso: Math.round(window.scrollY),
                    inFondo: Math.ceil(window.scrollY + window.innerHeight) >= document.documentElement.scrollHeight - 1,
                }));
                check(larghezza + ' px: dopo il clic la pagina riparte dalla tabella',
                    r.scorso > 0 && r.cima >= 0 && (r.cima < 80 || r.inFondo), [JSON.stringify(r)]);
            }
            await page.setViewportSize({ width: 1280, height: 800 });

            await page.goto(BASE + link);
            const su = finti(await leggi());
            const nomi = su.filter((n) => n !== '—');
            const ordinati = [...nomi].sort((a, b) => a.localeCompare(b, 'it', { sensitivity: 'base' }));
            check('in crescente: i nomi dalla A alla Z, chi non ha un utente in fondo',
                JSON.stringify(nomi) === JSON.stringify(ordinati) && su[su.length - 1] === '—' && su.length === 4, su);

            await page.goto(BASE + '/admin/settings/pagamenti?ordina=studente&verso=desc');
            const giu = finti(await leggi());
            check('in decrescente: dalla Z alla A, chi non ha un utente sempre in fondo',
                JSON.stringify(giu.filter((n) => n !== '—')) === JSON.stringify([...ordinati].reverse()) && giu[giu.length - 1] === '—', giu);

            // «Email» si ordina anche lei, nei due versi.
            check('«Email» è un\'intestazione che ordina', testa.linkEmail !== null && testa.linkEmail.endsWith('#pagamenti-ricevuti'), [String(testa.linkEmail)]);
            const leggiEmail = () => page.evaluate(() => [...document.querySelectorAll('.pagamenti-ricevuti tbody tr')]
                .map((r) => r.querySelector('td[data-label="Email"]').textContent.trim()));
            for (const verso of ['asc', 'desc']) {
                await page.goto(BASE + '/admin/settings/pagamenti?ordina=email&verso=' + verso);
                const email = (await leggiEmail()).filter((e) => e !== '—');
                const attese = [...email].sort((a, b) => a.localeCompare(b, 'it', { sensitivity: 'base' }));
                if (verso === 'desc') attese.reverse();
                check('per email, in senso ' + (verso === 'asc' ? 'crescente' : 'decrescente'),
                    email.length >= 4 && JSON.stringify(email) === JSON.stringify(attese), email);
            }
            await page.goto(BASE + '/admin/settings/pagamenti?ordina=studente&verso=desc');

            // Il filtro per corso tiene l'ordine.
            await page.selectOption('.pagamenti-ricevuti select[name="corso"]', corso);
            await Promise.all([page.waitForNavigation(), page.click('.pagamenti-ricevuti form button[type="submit"]')]);
            const dopo = new URL(page.url());
            check('filtrando per corso l\'ordine resta, e la pagina riparte dalla tabella', dopo.searchParams.get('ordina') === 'studente'
                && dopo.searchParams.get('verso') === 'desc' && dopo.searchParams.get('corso') === corso && dopo.hash === '#pagamenti-ricevuti', [page.url()]);
        }
    } finally {
        pagamenti('togli');
        await browser.close();
    }

    console.log(`\n${ok} OK, ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
})();
