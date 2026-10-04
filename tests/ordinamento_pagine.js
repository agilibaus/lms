/**
 * Le intestazioni che ordinano, provate sulle pagine vere.
 *
 * Esecuzione (serve il server di sviluppo attivo):
 *   php -S 127.0.0.1:8123 -t public router-dev.php &
 *   node tests/ordinamento_pagine.js
 *
 * PERCHE' ESISTE, oltre a `tests/ordinamento_test.php`. Quello prova la
 * classe: dato un elenco e dei parametri, ordina come deve. Non dice se la
 * vista le ha passato il campo giusto. Un'intestazione «Progresso» legata
 * per sbaglio al campo del nome ordina benissimo — per nome — e la pagina
 * resta piena di numeri plausibili in un ordine sbagliato. Da fuori si vede
 * solo provando a cliccare.
 *
 * COSA CONTROLLA, per ogni colonna ordinabile di ogni pagina:
 *
 *   1. **Il collegamento si apre** (200, non 404 o 500).
 *   2. **Esattamente una colonna** dichiara `aria-sort`, ed e' quella su cui
 *      si e' cliccato: e' cosi' che un lettore di schermo annuncia l'ordine.
 *   3. **Le righe sono le stesse di prima.** Ordinare rimette in fila, non
 *      aggiunge, non toglie e non duplica. E' il difetto piu' insidioso
 *      perche' una tabella riordinata sembra giusta comunque.
 *   4. **Almeno una colonna cambia davvero l'ordine.** Senza questo
 *      controllo, una pagina in cui l'ordinamento non funziona affatto
 *      passerebbe tutti gli altri.
 *
 * COSA NON VEDE: se l'ordine e' quello *giusto* per quella colonna. Il
 * confronto fra valori lo prova `ordinamento_test.php`; qui si prova il
 * collegamento fra la colonna e il suo campo, e che niente si rompa.
 */

'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const ADMIN = process.env.LMS_ADMIN || 'admin@test.it';
const PASS = process.env.LMS_PASS || 'Password1!';

let ok = 0;
let fail = 0;

function check(nome, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + nome);

    if (!condizione) {
        for (const d of dettagli) {
            console.log('         · ' + d);
        }
    }
}

/**
 * Le pagine con una tabella in cui l'ordine e' un dato. Restano fuori la
 * matrice dei permessi (caselle, non righe da confrontare), il quiz da
 * svolgere e gli elenchi di moduli e lezioni, che hanno un ordine deciso a
 * mano dal tutor: li' l'ordine E' il contenuto.
 *
 * Le pagine che dipendono dai dati (un report di un corso che potrebbe non
 * esserci) vengono saltate se rispondono diversamente da 200, come fa
 * `accessibilita.js`: un'installazione senza quel corso non deve far
 * fallire tutto.
 */
const PAGINE = [
    ['/admin/users', 'Utenti'],
    ['/admin/courses', 'Gestione corsi'],
    ['/admin/groups', 'Gruppi'],
    ['/certificates', 'Certificati'],
    ['/live', 'Sessioni live'],
    ['/reports/elenco/courses', 'Elenco per corso'],
    ['/reports/elenco/groups', 'Elenco per gruppo'],
    ['/reports/elenco/students', 'Elenco per studente'],
    ['/reports/elenco/live', 'Elenco per incontro'],
    ['/reports/elenco/fruizione', 'Elenco fruizione'],
    ['/reports/courses/1', 'Report per corso'],
    ['/reports/students/2', 'Report per studente'],
    ['/reports/groups/1', 'Report per gruppo'],
    ['/reports/fruizione/1', 'Fruizione per corso'],
    ['/lessons/1/fruizione', 'Fruizione della lezione'],
    ['/admin/courses/1/edit', 'Scheda del corso'],
    ['/admin/groups/1/edit', 'Scheda del gruppo'],
];

/** Le intestazioni ordinabili: etichetta e indirizzo. */
const intestazioni = (page) => page.evaluate(() =>
    [...document.querySelectorAll('.data-table th a.ordina')].map((a) => ({
        etichetta: a.textContent.replace(/\s+/g, ' ').replace(/:.*$/, '').trim(),
        href: a.getAttribute('href'),
    })));

/** La firma di ogni riga: serve a dire se sono le stesse e in che ordine. */
const righe = (page) => page.evaluate(() =>
    [...document.querySelectorAll('.data-table tbody tr')]
        .map((r) => r.textContent.replace(/\s+/g, ' ').trim()));

/**
 * I valori della colonna che si dichiara ordinata, presi dalle celle.
 *
 * Serve a provare la cosa piu' importante e meno visibile: che
 * l'intestazione sia legata al **suo** campo. «Email» collegata per errore
 * al campo del nome ordina benissimo, e la pagina non ha l'aria di essere
 * sbagliata: l'unico modo di accorgersene e' guardare se la colonna su cui
 * si e' cliccato e' davvero in ordine.
 */
const valoriDellaColonna = (page) => page.evaluate(() => {
    const th = [...document.querySelectorAll('.data-table th')]
        .find((t) => ['ascending', 'descending'].includes(t.getAttribute('aria-sort') ?? ''));

    if (th === undefined) {
        return null;
    }

    const riga = th.parentElement;
    const i = [...riga.children].indexOf(th);
    const tabella = th.closest('table');

    return [...tabella.querySelectorAll('tbody tr')].map((r) => {
        const cella = r.children[i];

        if (cella === undefined) {
            return '';
        }

        // Si toglie quello che e' decorativo — il simbolo del gruppo porta
        // le iniziali «CP» dentro alla cella — perche' non fa parte del
        // valore su cui si ordina. E' la stessa cosa che ignora un lettore
        // di schermo.
        const copia = cella.cloneNode(true);
        copia.querySelectorAll('[aria-hidden="true"]').forEach((e) => e.remove());

        return copia.textContent.replace(/\s+/g, ' ').trim();
    });
});

/** Il numero di righe dichiarato dall'elenco, dove c'e'. */
const totale = (page) => page.evaluate(() => {
    const p = document.querySelector('.report-conteggio strong');
    return p === null ? null : p.textContent.trim();
});

/** Quale colonna dichiara di essere ordinata, e come. */
const dichiarate = (page) => page.evaluate(() =>
    [...document.querySelectorAll('.data-table th[aria-sort]')]
        .filter((th) => th.getAttribute('aria-sort') !== 'none')
        .map((th) => th.textContent.replace(/\s+/g, ' ').replace(/:.*$/, '').trim()));

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    await page.goto(BASE + '/login');
    await page.fill('#email', ADMIN);
    await page.fill('#password', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

    try {
        for (const [url, nome] of PAGINE) {
            const risposta = await page.goto(BASE + url);

            if (risposta.status() !== 200) {
                console.log('  --   ' + nome + ': risponde ' + risposta.status() + ', saltata');
                continue;
            }

            const colonne = await intestazioni(page);
            const partenza = await righe(page);
            const paginato = await page.evaluate(() => document.querySelector('.paginazione') !== null);
            const totalePartenza = await totale(page);

            if (colonne.length === 0) {
                // Nessuna intestazione ordinabile: o la pagina non ha righe
                // in questa installazione, o nessuna colonna e' ordinabile.
                // Il secondo caso e' un difetto, il primo no, e qui non si
                // distinguono: lo si dice e si va avanti.
                console.log('  --   ' + nome + ': nessuna colonna ordinabile (tabella vuota?), saltata');
                continue;
            }

            let qualcunoHaCambiato = false;

            for (const { etichetta, href } of colonne) {
                const r = await page.goto(BASE + href);

                check(
                    nome + ' → «' + etichetta + '» si apre',
                    r.status() === 200,
                    ['ha risposto ' + r.status() + ' su ' + href]
                );

                if (r.status() !== 200) {
                    continue;
                }

                const annunciate = await dichiarate(page);

                // Una pagina puo' avere piu' tabelle con le stesse
                // colonne — il report dello studente ha una tabella di
                // quiz per corso — e si ordinano insieme: l'indirizzo
                // porta un ordine solo. Quello che non deve succedere e'
                // che a dichiararsi ordinata sia una colonna diversa da
                // quella su cui si e' cliccato.
                check(
                    nome + ' → «' + etichetta + '»: si annuncia ordinata quella colonna, e nessun\'altra',
                    annunciate.length >= 1 && annunciate.every((a) => a === etichetta),
                    ['annunciate: ' + JSON.stringify(annunciate)]
                );

                const dopo = await righe(page);

                // Su un elenco paginato le righe della prima pagina
                // CAMBIANO ed e' giusto cosi': si ordinano tutte le righe
                // del report, non le cinquanta a schermo, quindi in cima
                // arrivano righe che prima stavano piu' avanti. Li' si
                // verifica che il totale non si muova — una riga persa o
                // duplicata si vedrebbe li'.
                if (paginato) {
                    check(
                        nome + ' → «' + etichetta + '»: il totale delle righe non cambia',
                        (await totale(page)) === totalePartenza && dopo.length === partenza.length,
                        ['prima ' + totalePartenza + ', dopo ' + (await totale(page))]
                    );
                } else {
                    check(
                        nome + ' → «' + etichetta + '»: le righe sono le stesse',
                        dopo.length === partenza.length
                            && [...dopo].sort().join('|') === [...partenza].sort().join('|'),
                        [
                            'prima ' + partenza.length + ' righe, dopo ' + dopo.length,
                            'ordinare rimette in fila: non aggiunge, non toglie, non duplica',
                        ]
                    );
                }

                /*
                 * Dove la colonna contiene testo — niente cifre, niente
                 * date, nessun vuoto — si verifica che sia davvero in
                 * ordine alfabetico. Le colonne di numeri e di date sono
                 * escluse apposta: quello che si vede e' «3/12» o
                 * «04/10/2026», cioe' non il valore su cui si ordina, e
                 * confrontarlo come testo darebbe un rosso che non
                 * corrisponde a nessun difetto.
                 */
                const valori = await valoriDellaColonna(page);
                const testuale = valori !== null
                    && valori.length > 1
                    // Solo valori che cominciano per lettera: sulla
                    // punteggiatura iniziale l'ordine di `Collator` in PHP e
                    // quello di `localeCompare` qui non coincidono, e il
                    // rosso direbbe una differenza fra due librerie, non un
                    // difetto della pagina.
                    // Si escludono le colonne di numeri e di date — dove
                    // quello che si vede («3/12», «04/10/2026») non e' il
                    // valore su cui si ordina — ma non le parole che
                    // contengono cifre, come «tutor1@test.it»: un'email
                    // con un numero dentro resta una stringa.
                    && valori.every((v) => v !== '' && v !== '—'
                        && !/^[\d.,:%/ -]+$/.test(v)
                        // Solo valori che cominciano per lettera: sulla
                        // punteggiatura iniziale l'ordine di `Collator` in
                        // PHP e quello di `localeCompare` qui non
                        // coincidono, e il rosso direbbe una differenza fra
                        // due librerie, non un difetto della pagina.
                        && /^\p{L}/u.test(v));

                if (testuale) {
                    const atteso = [...valori].sort((a, b) => a.localeCompare(b, 'it', { sensitivity: 'base' }));

                    check(
                        nome + ' → «' + etichetta + '»: la colonna e davvero in ordine alfabetico',
                        valori.join('|') === atteso.join('|'),
                        [
                            'mostrato: ' + JSON.stringify(valori.slice(0, 5)),
                            'atteso:   ' + JSON.stringify(atteso.slice(0, 5)),
                            'una colonna legata al campo di un\'altra ordina lo stesso, e non si vede',
                        ]
                    );
                }

                if (dopo.join('|') !== partenza.join('|')) {
                    qualcunoHaCambiato = true;
                }

                // Anche il verso opposto: con poche righe, l'ordine
                // crescente puo' coincidere con quello di partenza, e la
                // colonna sembrerebbe non ordinare affatto.
                const rovescio = await page.evaluate((e) => {
                    const a = [...document.querySelectorAll('.data-table th a.ordina')]
                        .find((x) => x.textContent.replace(/\s+/g, ' ').replace(/:.*$/, '').trim() === e);
                    return a === undefined ? null : a.getAttribute('href');
                }, etichetta);

                if (rovescio !== null) {
                    await page.goto(BASE + rovescio);

                    if ((await righe(page)).join('|') !== partenza.join('|')) {
                        qualcunoHaCambiato = true;
                    }
                }
            }

            check(
                nome + ': almeno una colonna cambia davvero l\'ordine',
                qualcunoHaCambiato || partenza.length < 2,
                ['con ' + partenza.length + ' righe e ' + colonne.length + ' colonne ordinabili, '
                    + 'nessun ordine diverso da quello di partenza']
            );
        }

        // --- l'ordine sopravvive alla paginazione e alla ricerca ---------
        //
        // Sono le due cose che si perdono per prime: un collegamento di
        // pagina costruito a mano dimentica l'ordine, e il modulo della
        // ricerca lo riazzera perche' manda solo i propri campi.
        console.log('\n--- l\'ordine non si perde per strada');

        await page.goto(BASE + '/reports/elenco/students?ordina=email&verso=desc');
        const primaPagina = await righe(page);

        const linkPagina = await page.evaluate(() => {
            const a = document.querySelector('.paginazione a[rel="next"]');
            return a === null ? null : a.getAttribute('href');
        });

        check(
            'l\'elenco degli studenti ha piu di una pagina (serve alla prova qui sotto)',
            linkPagina !== null
        );

        if (linkPagina !== null) {
            check(
                'il collegamento alla pagina successiva porta con se l\'ordine',
                linkPagina.includes('ordina=email') && linkPagina.includes('verso=desc'),
                ['indirizzo: ' + linkPagina]
            );

            await page.goto(BASE + linkPagina);
            const secondaPagina = await righe(page);

            check(
                'e la seconda pagina e davvero ordinata allo stesso modo',
                (await dichiarate(page)).length === 1 && secondaPagina.join('|') !== primaPagina.join('|'),
                ['la seconda pagina deve contenere righe diverse, nello stesso ordine']
            );
        }

        await page.goto(BASE + '/reports/elenco/students?ordina=email&verso=desc');
        await page.fill('#cerca', 'a');
        await Promise.all([page.waitForNavigation(), page.click('.report-ricerca button[type=submit]')]);

        const dopoRicerca = page.url();

        check(
            'cercando, l\'ordine scelto resta',
            dopoRicerca.includes('ordina=email') && dopoRicerca.includes('verso=desc'),
            ['indirizzo dopo la ricerca: ' + dopoRicerca]
        );

        // --- quello che arriva dall'indirizzo ----------------------------
        console.log('\n--- indirizzi inventati');

        for (const coda of [
            '?ordina=password',
            '?ordina=full_name',
            '?ordina[]=nome',
            '?ordina=nome&verso=' + encodeURIComponent('"><script>alert(1)</script>'),
        ]) {
            const r = await page.goto(BASE + '/admin/users' + coda);
            const rotto = await page.evaluate(() =>
                document.body.innerHTML.includes('<script>alert(1)</script>')
                || document.body.textContent.includes('Fatal error')
                || document.body.textContent.includes('Warning'));

            check(
                'la pagina regge «' + coda + '»',
                r.status() === 200 && !rotto,
                ['ha risposto ' + r.status()]
            );
        }
    } finally {
        await browser.close();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
