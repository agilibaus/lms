/**
 * Le righe selezionate nell'elenco Utenti (20/11, Alessandro, variante B).
 *
 * Al posto della colonna «Modifica / Elimina» ci sono le caselle, e i comandi
 * stanno in una barra che compare in fondo allo schermo quando c'e' almeno
 * una riga scelta: «Modifica» solo con una riga, «Elimina» o «Elimina N
 * utenti», «Annulla». Il nome apre la modifica. Eliminare passa da una
 * pagina di conferma che dice chi verra' eliminato e chi no, e perche'.
 * Senza JavaScript la barra sta ferma in fondo alla tabella con tutti i
 * comandi, e funziona lo stesso.
 *
 * Sul telefono (Alessandro, 20/11) il conto, «Modifica» ed «Elimina» stanno
 * sulla stessa riga, «Annulla» sotto, al centro; anche a 320 px.
 *
 * Esecuzione:  node tests/utenti_selezione.js   (server di sviluppo acceso,
 * semina gia' fatta: servono admin@test.it e un modulo).
 *
 * Crea i suoi utenti, con email «…@utenti-sel.test», e un incontro dal vivo
 * del quarto, che per questo non si puo' eliminare. Alla fine li toglie.
 */
'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';
const RADICE = path.join(__dirname, '..');
const DOMINIO = '@utenti-sel.test';

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

const id = (email) => php('$s = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $s->execute([$argv[1]]); echo (string) $s->fetchColumn();', email).trim();
const esiste = (email) => id(email) !== '';

function pulisci() {
    php('$pdo->prepare("DELETE FROM live_sessions WHERE title = ?")->execute(["[utenti-sel] incontro"]);'
        + ' $pdo->prepare("DELETE FROM users WHERE email LIKE ?")->execute(["%" . $argv[1]]);', DOMINIO);
}

function crea() {
    for (const [nome, ruolo] of [['uno', 'studente'], ['due', 'studente'], ['tre', 'studente'], ['bloccato', 'tutor']]) {
        php('$pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at) VALUES (?, ?, ?, \'Selezione\', ?, 1, NOW(), NOW())")->execute([$argv[1], password_hash("x", PASSWORD_DEFAULT), ucfirst($argv[2]), $argv[3]]);',
            nome + DOMINIO, nome, ruolo);
    }
    // Chi ha creato un incontro non si elimina (29/10).
    php('$m = (int) $pdo->query("SELECT id FROM modules ORDER BY id LIMIT 1")->fetchColumn();'
        + ' $pdo->prepare("INSERT INTO live_sessions (module_id, title, starts_at, ends_at, created_by) VALUES (?, \'[utenti-sel] incontro\', NOW() + INTERVAL 40 DAY, NOW() + INTERVAL 40 DAY + INTERVAL 1 HOUR, ?)")->execute([$m, (int) $argv[1]]);',
        id('bloccato' + DOMINIO));
}

async function entra(browser, opzioni = {}) {
    const ctx = await browser.newContext(opzioni);
    const page = await ctx.newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', 'admin@test.it');
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');
    return { ctx, page };
}

const casella = (page, email) => page.locator('tbody tr', { hasText: email }).locator('.sel-casella');

const barra = (page) => page.evaluate(() => {
    const b = document.querySelector('[data-barra]');
    const vis = (el) => el !== null && el.checkVisibility();
    return {
        visibile: vis(b),
        conto: document.querySelector('[data-conto]').textContent.trim(),
        modifica: vis(document.querySelector('[data-modifica]')),
        elimina: vis(document.querySelector('[data-elimina]')) ? document.querySelector('[data-elimina]').textContent.trim() : null,
        scelte: document.querySelectorAll('tbody input[name="ids[]"]:checked').length,
    };
});

(async () => {
    pulisci();
    crea();
    const browser = await chromium.launch();

    try {
        console.log('--- la pagina');
        let { ctx, page } = await entra(browser);
        await page.goto(BASE + '/admin/users');

        const forma = await page.evaluate(() => {
            const righe = [...document.querySelectorAll('tbody tr')];
            return {
                righe: righe.length,
                caselle: righe.filter((r) => r.querySelector('input[name="ids[]"]')?.getAttribute('aria-label')?.startsWith('Seleziona ')).length,
                nomiCollegati: righe.filter((r) => /^\/admin\/users\/\d+\/edit$/.test(r.querySelector('td[data-label="Nome"] a')?.getAttribute('href') || '')).length,
                comandiNelleRighe: document.querySelectorAll('tbody .row-actions, tbody form').length,
                tutti: document.querySelector('[data-seleziona-tutti]')?.checkVisibility() ?? false,
            };
        });
        check('ogni riga ha la sua casella con il nome, e il nome apre la modifica',
            forma.righe > 0 && forma.caselle === forma.righe && forma.nomiCollegati === forma.righe, [JSON.stringify(forma)]);
        check('nessun «Modifica» o «Elimina» dentro le righe', forma.comandiNelleRighe === 0, [JSON.stringify(forma)]);
        check('«Seleziona tutti» c\'è, con lo script', forma.tutti, [JSON.stringify(forma)]);

        let b = await barra(page);
        check('senza righe scelte la barra non c\'è', !b.visibile, [JSON.stringify(b)]);

        await casella(page, 'uno' + DOMINIO).click();
        b = await barra(page);
        check('una riga: «1 utente selezionato», «Modifica» ed «Elimina»',
            b.visibile && b.conto === '1 utente selezionato' && b.modifica && b.elimina === 'Elimina', [JSON.stringify(b)]);

        await casella(page, 'due' + DOMINIO).click();
        b = await barra(page);
        check('due righe: «2 utenti selezionati», niente «Modifica», «Elimina 2 utenti»',
            b.visibile && b.conto === '2 utenti selezionati' && !b.modifica && b.elimina === 'Elimina 2 utenti', [JSON.stringify(b)]);

        await page.click('[data-barra] button[type="reset"]');
        await page.waitForTimeout(50);
        b = await barra(page);
        check('«Annulla» toglie la selezione e la barra', !b.visibile && b.scelte === 0, [JSON.stringify(b)]);

        await page.click('.sel-casella:has([data-seleziona-tutti])');
        b = await barra(page);
        const totale = await page.locator('tbody input[name="ids[]"]').count();
        check('«Seleziona tutti» sceglie tutte le righe', b.scelte === totale && b.conto === totale + ' utenti selezionati', [JSON.stringify(b), 'righe: ' + totale]);
        await page.click('.sel-casella:has([data-seleziona-tutti])');
        b = await barra(page);
        check('e un secondo clic le toglie', b.scelte === 0 && !b.visibile, [JSON.stringify(b)]);

        console.log('\n--- «Modifica»');
        await casella(page, 'uno' + DOMINIO).click();
        await Promise.all([page.waitForLoadState('networkidle'), page.click('[data-modifica]')]);
        check('con una riga porta alla sua scheda', page.url() === BASE + '/admin/users/' + id('uno' + DOMINIO) + '/edit', [page.url()]);

        await page.goto(BASE + '/admin/users/selezione?azione=modifica&ids%5B%5D=' + id('uno' + DOMINIO) + '&ids%5B%5D=' + id('due' + DOMINIO));
        const avviso = await page.evaluate(() => (document.querySelector('.alert-error')?.textContent || '').trim());
        check('con due righe (a mano, senza script) torna all\'elenco e lo dice',
            page.url().startsWith(BASE + '/admin/users') && avviso === 'Seleziona un solo utente per modificarlo.', [page.url(), avviso]);

        console.log('\n--- «Elimina»');
        await page.goto(BASE + '/admin/users');
        for (const n of ['uno', 'due', 'bloccato']) await casella(page, n + DOMINIO).click();
        await Promise.all([page.waitForLoadState('networkidle'), page.click('[data-elimina]')]);
        const conferma = await page.evaluate(() => ({
            titolo: document.querySelector('h1').textContent.trim(),
            ids: [...document.querySelectorAll('form[action="/admin/users/elimina"] input[name="ids[]"]')].map((i) => i.value).sort(),
            bloccati: [...document.querySelectorAll('.card')].filter((c) => c.querySelector('h2')?.textContent.trim() === 'Non si può eliminare')
                .map((c) => c.textContent.replace(/\s+/g, ' ').trim()).join(' '),
            pulsante: document.querySelector('form[action="/admin/users/elimina"] button[type="submit"]')?.textContent.trim(),
            annulla: !!document.querySelector('form[action="/admin/users/elimina"] a[href="/admin/users"]'),
        }));
        const attesi = [id('uno' + DOMINIO), id('due' + DOMINIO)].sort();
        check('la conferma elimina solo chi si può eliminare, e lo dice nel titolo e nel pulsante',
            conferma.titolo === 'Eliminare 2 utenti?' && JSON.stringify(conferma.ids) === JSON.stringify(attesi)
                && conferma.pulsante === 'Elimina 2 utenti' && conferma.annulla, [JSON.stringify(conferma)]);
        check('e dice chi non si può eliminare, e perché',
            conferma.bloccati.includes('Bloccato Selezione') && conferma.bloccati.includes('Ha creato uno o più incontri dal vivo'), [conferma.bloccati]);
        check('fino al clic non si elimina niente', esiste('uno' + DOMINIO) && esiste('due' + DOMINIO));

        await Promise.all([page.waitForURL(BASE + '/admin/users'), page.click('form[action="/admin/users/elimina"] button[type="submit"]')]);
        const esito = await page.evaluate(() => (document.querySelector('.alert-success')?.textContent || '').trim());
        check('confermato, i due se ne vanno e l\'elenco lo dice',
            !esiste('uno' + DOMINIO) && !esiste('due' + DOMINIO) && esiste('bloccato' + DOMINIO)
                // nell'ordine dell'elenco, cioe' per nome
                && esito === '2 utenti eliminati: Due Selezione, Uno Selezione.', [esito]);

        // Le regole si rileggono al momento di eliminare: un invio fatto a
        // mano con chi non si puo' eliminare lo lascia, e dice perche'.
        const token = await page.evaluate(() => document.querySelector('input[name="_token"]')?.value);
        await page.evaluate(async ([token, ids]) => {
            const dati = new URLSearchParams([['_token', token], ...ids.map((i) => ['ids[]', i])]);
            await fetch('/admin/users/elimina', { method: 'POST', body: dati, credentials: 'same-origin', redirect: 'manual' });
        }, [token, [id('tre' + DOMINIO), id('bloccato' + DOMINIO)]]);
        await page.goto(BASE + '/admin/users');
        const misto = await page.evaluate(() => ({
            ok: (document.querySelector('.alert-success')?.textContent || '').trim(),
            no: (document.querySelector('.alert-error')?.textContent || '').trim(),
        }));
        check('mandati insieme, chi si può eliminare se ne va e chi no resta, con il motivo',
            !esiste('tre' + DOMINIO) && esiste('bloccato' + DOMINIO)
                && misto.ok === 'Utente eliminato: Tre Selezione.'
                && misto.no === 'Bloccato Selezione ha creato uno o più incontri dal vivo e non può essere eliminato: disattivalo.', [JSON.stringify(misto)]);
        await ctx.close();

        console.log('\n--- senza JavaScript');
        ({ ctx, page } = await entra(browser, { javaScriptEnabled: false }));
        await page.goto(BASE + '/admin/users');
        b = await barra(page);
        const tuttiSenza = await page.evaluate(() => document.querySelector('[data-seleziona-tutti]')?.checkVisibility() ?? false);
        check('la barra c\'è, ferma in fondo, con «Modifica» ed «Elimina»', b.visibile && b.modifica && b.elimina === 'Elimina', [JSON.stringify(b)]);
        check('«Seleziona tutti» no: senza script non funzionerebbe', !tuttiSenza);
        await casella(page, 'bloccato' + DOMINIO).click();
        await Promise.all([page.waitForLoadState('networkidle'), page.click('[data-modifica]')]);
        check('«Modifica» funziona lo stesso', page.url() === BASE + '/admin/users/' + id('bloccato' + DOMINIO) + '/edit', [page.url()]);
        await ctx.close();

        console.log('\n--- sul telefono');
        for (const larghezza of [390, 320]) {
            ({ ctx, page } = await entra(browser, { viewport: { width: larghezza, height: 800 }, isMobile: true, hasTouch: true }));
            await page.goto(BASE + '/admin/users');
            const posizioni = () => page.evaluate(() => {
                const r = (s) => { const el = document.querySelector(s); return el && el.checkVisibility() ? el.getBoundingClientRect() : null; };
                const conto = r('[data-conto]'); const mod = r('[data-modifica]'); const eli = r('[data-elimina]'); const ann = r('[data-barra] button[type="reset"]');
                const centro = (x) => x ? (x.top + x.bottom) / 2 : null;
                return {
                    largo: document.documentElement.scrollWidth,
                    stessaRiga: [conto, mod, eli].filter(Boolean).every((x) => Math.abs(centro(x) - centro(eli)) < 12),
                    inOrdine: (!mod || (conto.right <= mod.left && mod.right <= eli.left)) && conto.right <= eli.left,
                    // Distribuiti: il conto contro il bordo sinistro della
                    // barra, «Elimina» contro quello destro.
                    aiBordi: !!(conto && eli) && conto.left < 30 && innerWidth - eli.right < 30,
                    annullaSotto: ann && eli ? ann.top >= eli.bottom : false,
                    annullaAlCentro: ann ? Math.abs((ann.left + ann.right) / 2 - innerWidth / 2) < 4 : false,
                };
            });
            await casella(page, 'bloccato' + DOMINIO).click();
            let p = await posizioni();
            check(larghezza + ' px, una riga: conto, «Modifica» ed «Elimina» sulla stessa riga, «Annulla» sotto al centro',
                p.stessaRiga && p.inOrdine && p.aiBordi && p.annullaSotto && p.annullaAlCentro && p.largo <= larghezza, [JSON.stringify(p)]);
            await page.locator('tbody .sel-casella').first().click();
            p = await posizioni();
            check(larghezza + ' px, due righe: conto ed «Elimina 2 utenti» sulla stessa riga, «Annulla» sotto al centro',
                p.stessaRiga && p.inOrdine && p.aiBordi && p.annullaSotto && p.annullaAlCentro && p.largo <= larghezza, [JSON.stringify(p)]);
            await ctx.close();
        }
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
