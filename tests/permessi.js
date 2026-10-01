/**
 * Verifica dei permessi: chi può fare cosa, provando gli indirizzi degli
 * altri.
 *
 * Esecuzione (serve il server di sviluppo attivo):
 *   php -S 127.0.0.1:8123 -t public router-dev.php &
 *   node tests/permessi.js
 *
 * PERCHÉ ESISTE, e che cosa aggiunge a quello che c'era. Il controllo del
 * 28/09 diceva che ogni rotta interna chiede *un* permesso. Non diceva che
 * chiedesse quello *giusto*, e soprattutto non vedeva il caso più insidioso:
 * un utente autorizzato che cambia un numero nell'indirizzo. Uno studente
 * iscritto al corso A che scrive l'indirizzo della lezione del corso B; un
 * tutor che apre il gruppo di un collega; un assistente che guarda il report
 * di uno studente che non segue. Sono tutte richieste di gente che ha fatto
 * l'accesso regolarmente, e un controllo "serve il login" le lascia passare
 * tutte.
 *
 * COME FUNZIONA. `tests/semina_permessi.php` crea due mondi paralleli — A e
 * B — con proprietari diversi, e stampa gli identificativi. Qui si entra con
 * ciascun ruolo e si prova a raggiungere le cose dell'altro mondo, dicendo
 * **in anticipo** che cosa ci si aspetta: consentito o negato. Una pagina
 * che risponde 200 dove ci si aspettava un rifiuto è un difetto; una che
 * rifiuta dove doveva aprirsi è un difetto uguale e opposto, e vale la pena
 * accorgersene prima che se ne accorga un tutor.
 *
 * SI PROVANO ANCHE LE AZIONI, non solo le pagine. Metà del danno possibile
 * sta nelle POST: cancellare la lezione di un altro corso, togliere una
 * domanda dal quiz di un collega, cambiare il ruolo di un utente. Le prove
 * distruttive girano per ultime e sui dati seminati, che il giro successivo
 * ricrea: se una riuscisse — cioè se trovasse un difetto vero — non
 * resterebbe niente di rotto dietro.
 *
 * COSA NON VEDE. Che il contenuto mostrato sia quello giusto: una pagina può
 * rispondere 200 legittimamente e dentro mostrare righe che non dovrebbe.
 * Questo file guarda la porta, non che cosa c'è nella stanza.
 */

'use strict';

const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.LMS_URL || 'http://127.0.0.1:8123';
const PASSWORD = process.env.LMS_PASS || 'Password1!';

let ok = 0;
let fail = 0;

function check(etichetta, condizione, dettagli = []) {
    condizione ? ok++ : fail++;
    console.log((condizione ? '  OK   ' : '  FAIL ') + etichetta);
    dettagli.forEach((d) => console.log('         · ' + d));
}

/** Semina i due mondi e restituisce gli identificativi. */
function semina() {
    const radice = path.join(__dirname, '..');
    const uscita = execFileSync('php', [path.join(radice, 'tests', 'semina_permessi.php')], {
        encoding: 'utf8',
        cwd: radice,
    });

    return JSON.parse(uscita);
}

/**
 * Una sessione autenticata. Si usa il contesto di richiesta di Playwright e
 * non un browser: qui interessano i codici di risposta, non il disegno
 * delle pagine, e senza browser il giro dura secondi invece di minuti.
 */
async function entra(browser, email) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form.auth-form button[type="submit"]');
    await page.waitForLoadState('networkidle');

    if (page.url().includes('/login')) {
        throw new Error('accesso non riuscito per ' + email);
    }

    return { ctx, page };
}

/**
 * Esito di una GET: `consentito` quando la pagina si apre davvero,
 * `negato` quando il server la rifiuta o rimanda all'accesso.
 */
async function esitoGet(page, url) {
    const risposta = await page.goto(BASE + url);
    const stato = risposta ? risposta.status() : 0;

    // Il rimando alla pagina di accesso è un rifiuto a tutti gli effetti,
    // anche se il codice finale è 200.
    if (page.url().includes('/login')) {
        return { esito: 'negato', come: 'rimando all\'accesso' };
    }

    if (stato === 403 || stato === 404) {
        return { esito: 'negato', come: stato + '' };
    }

    if (stato >= 400) {
        return { esito: 'negato', come: stato + '' };
    }

    return { esito: 'consentito', come: stato + '' };
}

/**
 * Esito di una POST, con il token CSVF preso da una pagina a cui l'utente
 * ha accesso. Senza token il rifiuto sarebbe del token e non del permesso,
 * e il controllo non direbbe niente di utile.
 */
async function esitoPost(page, url, campi, paginaToken = '/profilo') {
    await page.goto(BASE + paginaToken);

    const token = await page.evaluate(() => {
        const campo = document.querySelector('input[name="_token"]');
        return campo ? campo.value : null;
    });

    if (token === null) {
        return { esito: 'ignoto', come: 'nessun token su ' + paginaToken };
    }

    const r = await page.evaluate(async ([url, campi, token]) => {
        const dati = new URLSearchParams();
        dati.append('_token', token);
        for (const [k, v] of campi) dati.append(k, v);

        const res = await fetch(url, {
            method: 'POST',
            body: dati,
            credentials: 'same-origin',
            redirect: 'manual',
        });

        return { stato: res.status, tipo: res.type };
    }, [url, campi, token]);

    // `redirect: 'manual'` restituisce stato 0 e tipo opaqueredirect su un
    // rimando: per una POST andata a buon fine è la risposta normale, perché
    // i controller rimandano sempre dopo aver scritto.
    if (r.stato === 403 || r.stato === 404 || r.stato === 419) {
        return { esito: 'negato', come: r.stato + '' };
    }

    if (r.tipo === 'opaqueredirect' || (r.stato >= 200 && r.stato < 400)) {
        return { esito: 'consentito', come: r.stato === 0 ? 'rimando' : r.stato + '' };
    }

    return { esito: 'negato', come: r.stato + '' };
}

(async () => {
    console.log('Semina dei due mondi…');
    const d = semina();
    const A = d.A;
    const B = d.B;

    const browser = await chromium.launch();

    /**
     * Le prove in sola lettura, per ruolo. Ogni voce dice l'indirizzo, che
     * cosa ci si aspetta e perché — il perché serve a chi leggerà un FAIL
     * fra sei mesi.
     */
    const LETTURA = [
        ['stud@test.it', 'studente del mondo A', [
            ['/courses/' + A.corso, 'consentito', 'è iscritto al corso A'],
            ['/lessons/' + A.lezione, 'consentito', 'lezione del suo corso'],
            ['/quizzes/' + A.quiz, 'consentito', 'quiz del suo corso'],
            ['/courses/' + B.corso, 'negato', 'non è iscritto al corso B'],
            ['/lessons/' + B.lezione, 'negato', 'lezione di un corso non suo'],
            ['/quizzes/' + B.quiz, 'negato', 'quiz di un corso non suo'],
            ['/reports', 'negato', 'gli studenti non hanno i report'],
            ['/reports/students/' + d.utenti.studenteB, 'negato', 'report di un altro studente'],
            ['/admin/users', 'negato', 'nessuna pagina di amministrazione'],
            ['/admin/courses', 'negato', 'nessuna pagina di amministrazione'],
            ['/admin/permissions', 'negato', 'la pagina più delicata del pannello'],
            ['/lessons/' + A.lezione + '/edit', 'negato', 'uno studente non modifica le lezioni'],
            ['/lessons/' + A.lezione + '/fruizione', 'negato', 'il rendiconto è dello staff'],
            ['/live/' + B.incontro, 'negato', 'incontro di un gruppo non suo'],
        ]],

        ['tutor1@test.it', 'tutor del mondo A', [
            ['/courses/' + A.corso, 'consentito', 'è il corso dei suoi gruppi'],
            ['/lessons/' + A.lezione + '/edit', 'consentito', 'può modificare le lezioni del corso A'],
            ['/quizzes/' + A.quiz + '/edit', 'consentito', 'può modificare il quiz del corso A'],
            ['/questions/' + A.domanda + '/edit', 'consentito', 'domanda del suo quiz'],
            ['/reports/courses/' + A.corso, 'consentito', 'report del suo corso'],
            ['/lessons/' + B.lezione + '/edit', 'negato', 'lezione di un corso non suo'],
            ['/quizzes/' + B.quiz + '/edit', 'negato', 'quiz di un corso non suo'],
            ['/questions/' + B.domanda + '/edit', 'negato', 'domanda del quiz di un collega'],
            ['/modules/' + B.modulo + '/edit', 'negato', 'modulo di un corso non suo'],
            ['/admin/courses/' + B.corso + '/edit', 'negato', 'corso non suo'],
            ['/reports/courses/' + B.corso, 'negato', 'report di un corso non suo'],
            ['/admin/users', 'negato', 'la gestione utenti è dell\'admin'],
            ['/admin/permissions', 'negato', 'i permessi sono dell\'admin'],
            ['/admin/settings', 'negato', 'le impostazioni sono dell\'admin'],
        ]],

        ['assist@test.it', 'assistente del tutor A', [
            ['/reports', 'consentito', 'vede i report, ristretti al suo perimetro'],
            ['/reports/courses/' + A.corso, 'consentito', 'corso del tutor che segue'],
            ['/reports/courses/' + B.corso, 'negato', 'corso di un tutor che non segue'],
            ['/reports/students/' + d.utenti.studenteB, 'negato', 'studente fuori dal suo perimetro'],
            ['/lessons/' + A.lezione + '/edit', 'negato', 'un assistente non modifica le lezioni'],
            ['/admin/users', 'negato', 'nessuna gestione utenti'],
            ['/admin/courses', 'negato', 'nessuna gestione corsi'],
        ]],

        ['admin@test.it', 'amministratore', [
            ['/courses/' + B.corso, 'consentito', 'l\'admin vede tutto'],
            ['/lessons/' + B.lezione + '/edit', 'consentito', 'l\'admin modifica tutto'],
            ['/reports/courses/' + B.corso, 'consentito', 'nessuna restrizione sui report'],
            ['/admin/permissions', 'consentito', 'è sua'],
            ['/admin/settings/bunny', 'consentito', 'è sua'],
        ]],
    ];

    try {
        for (const [email, chi, prove] of LETTURA) {
            console.log('\n--- ' + chi + ' (' + email + '), in lettura');

            const { ctx, page } = await entra(browser, email);

            for (const [url, atteso, perche] of prove) {
                const r = await esitoGet(page, url);
                check(
                    chi + ' → ' + url + ': ' + atteso,
                    r.esito === atteso,
                    r.esito === atteso ? [] : ['ottenuto «' + r.esito + '» (' + r.come + ') — ' + perche]
                );
            }

            await ctx.close();
        }

        // --- le azioni, cioè la metà che fa danno ------------------------
        //
        // Girano per ultime perché provano a scrivere e a cancellare. Se una
        // riuscisse quando non deve, il mondo seminato ne esce rotto: il giro
        // successivo lo ricrea da zero, quindi non è un problema.

        console.log('\n--- le azioni: nessuno deve poter scrivere nel mondo di un altro');

        const AZIONI = [
            ['stud@test.it', 'studente A', [
                ['/lessons/' + B.lezione + '/delete', [], 'negato', 'cancellare una lezione'],
                ['/lessons/' + A.lezione + '/delete', [], 'negato', 'cancellare una lezione del proprio corso'],
                ['/quizzes/' + A.quiz + '/delete', [], 'negato', 'cancellare un quiz'],
                ['/admin/users/' + d.utenti.studenteB + '/delete', [], 'negato', 'cancellare un altro utente'],
            ]],
            ['tutor1@test.it', 'tutor A', [
                ['/lessons/' + B.lezione + '/delete', [], 'negato', 'cancellare la lezione di un collega'],
                ['/questions/' + B.domanda + '/delete', [], 'negato', 'togliere una domanda dal quiz di un collega'],
                ['/quizzes/' + B.quiz + '/delete', [], 'negato', 'cancellare il quiz di un collega'],
                ['/modules/' + B.modulo + '/delete', [], 'negato', 'cancellare un modulo non suo'],
                ['/admin/groups/' + B.gruppo + '/delete', [], 'negato', 'cancellare il gruppo di un collega'],
                ['/admin/users/' + d.utenti.studenteB + '/delete', [], 'negato', 'cancellare un utente'],
                ['/questions/' + B.domanda + '/move', [['direction', 'up']], 'negato', 'riordinare le domande di un collega'],
            ]],
            ['assist@test.it', 'assistente', [
                ['/lessons/' + A.lezione + '/delete', [], 'negato', 'cancellare una lezione'],
                ['/questions/' + A.domanda + '/delete', [], 'negato', 'togliere una domanda'],
                ['/admin/users/' + d.utenti.studenteA + '/delete', [], 'negato', 'cancellare un utente'],
            ]],
        ];

        for (const [email, chi, prove] of AZIONI) {
            const { ctx, page } = await entra(browser, email);

            for (const [url, campi, atteso, cosa] of prove) {
                const r = await esitoPost(page, url, campi);
                check(
                    chi + ' → POST ' + url + ' (' + cosa + '): ' + atteso,
                    r.esito === atteso,
                    r.esito === atteso ? [] : ['ottenuto «' + r.esito + '» (' + r.come + ')']
                );
            }

            await ctx.close();
        }

        // --- senza aver fatto l'accesso -----------------------------------
        console.log('\n--- chi non ha fatto l\'accesso');

        const ctx = await browser.newContext();
        const page = await ctx.newPage();

        for (const url of [
            '/',
            '/courses/' + A.corso,
            '/lessons/' + A.lezione,
            '/quizzes/' + A.quiz,
            '/reports',
            '/admin/users',
            '/admin/permissions',
            '/profilo',
        ]) {
            const r = await esitoGet(page, url);
            check('anonimo → ' + url + ': negato', r.esito === 'negato', r.esito === 'negato' ? [] : ['ottenuto 200']);
        }

        await ctx.close();
    } finally {
        await browser.close();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
