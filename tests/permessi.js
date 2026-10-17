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
// Per riconoscere i messaggi scritti da questo giro nella cartella della posta.
const INIZIO_GIRO = Date.now() - 1000;

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
    // Un indirizzo che scarica un file non si puo' aprire con `goto`: il
    // browser avvia un download invece di navigare, e Playwright solleva un
    // errore. Si chiede con `fetch`, che del download ci da' solo il codice
    // — che e' tutto quello che serve qui.
    // Lo stesso per gli scaricamenti dei rendiconti, che finiscono in /csv o
    // /xlsx (17/10).
    if (url.includes('/download') || /\/(csv|xlsx)$/.test(url)) {
        await page.goto(BASE + '/profilo');

        const stato = await page.evaluate(async (u) => {
            const res = await fetch(u, { credentials: 'same-origin', redirect: 'manual' });
            return res.status;
        }, url);

        return stato >= 200 && stato < 400
            ? { esito: 'consentito', come: stato + '' }
            : { esito: 'negato', come: stato + '' };
    }

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
            // Gli elenchi sono pagine nuove, con un indirizzo diverso da
            // quello dell'indice: una porta nuova va provata, non dedotta
            // dal fatto che quella accanto è chiusa.
            ['/reports/elenco/students', 'negato', 'nemmeno l\'elenco degli studenti'],
            ['/reports/elenco/courses', 'negato', 'nemmeno l\'elenco dei corsi'],
            ['/reports/students/' + d.utenti.studenteB, 'negato', 'report di un altro studente'],
            // L'agenda si apre per tutti: e' un altro modo di guardare le
            // cose che uno gia' vede. Quello che NON deve contenere si
            // prova piu' sotto, guardandoci dentro.
            ['/agenda', 'consentito', 'l\'agenda e di chi la guarda'],
            ['/agenda?vista=mese', 'consentito', 'anche la vista del mese'],
            ['/admin/users', 'negato', 'nessuna pagina di amministrazione'],
            ['/admin/users/importa', 'negato', 'men che meno importare utenti'],
            ['/admin/courses', 'negato', 'nessuna pagina di amministrazione'],
            ['/admin/permissions', 'negato', 'la pagina più delicata del pannello'],
            ['/lessons/' + A.lezione + '/edit', 'negato', 'uno studente non modifica le lezioni'],
            ['/lessons/' + A.lezione + '/fruizione', 'negato', 'il rendiconto è dello staff'],
            ['/live/' + B.incontro, 'negato', 'incontro di un gruppo non suo'],

            // Rilascio progressivo (§8.7). Qui lo studente è iscritto e il
            // corso è suo: a rifiutare non è l'iscrizione ma la data del
            // modulo. Sono quattro ingressi separati nel codice, e si
            // provano tutti e quattro perché proteggerne tre su quattro
            // equivale a non proteggerne nessuno.
            ['/modules/' + A.modulo_chiuso + '/edit', 'negato', 'uno studente non modifica i moduli'],
            ['/lessons/' + A.lezione_chiusa, 'negato', 'lezione di un modulo non ancora aperto'],
            ['/quizzes/' + A.quiz_chiuso, 'negato', 'quiz di un modulo non ancora aperto'],
            ['/materials/' + A.materiale_chiuso + '/download', 'negato',
                'materiale di un modulo non ancora aperto'],
            ['/live/' + A.incontro_chiuso, 'negato', 'incontro di un modulo non ancora aperto'],
            ['/lessons/' + A.lezione, 'consentito',
                'controprova: il modulo aperto dello stesso corso resta aperto'],

            // La pagina del gruppo e le foto (06/10, `GroupPeers`). Le foto
            // esistono tutte sul disco (le mette la semina): un rifiuto qui
            // e' della regola, non di un file che manca. La controprova e'
            // dell'admin, piu' sotto.
            ['/gruppi/' + A.gruppo, 'consentito', 'è il suo gruppo'],
            ['/gruppi/' + B.gruppo, 'negato', 'gruppo di cui non fa parte'],
            ['/utenti/' + d.utenti.studenteA + '/immagine', 'consentito', 'la propria foto'],
            ['/utenti/' + d.utenti.tutorA + '/immagine', 'consentito',
                'il tutor del suo gruppo: sta al centro del cerchio'],
            ['/utenti/' + d.utenti.studenteB + '/immagine', 'negato',
                'nessun gruppo in comune: prima bastava aver fatto accesso'],

            // Il benvenuto del tutor (07/10): lo studente riceve quello del
            // tutor del suo gruppo, non quello di un altro corso. I file
            // esistono tutti (li mette la semina).
            ['/benvenuti/' + A.benvenuto + '/audio', 'consentito', 'l\'audio del benvenuto del suo tutor'],
            ['/benvenuti/' + B.benvenuto + '/audio', 'negato', 'il benvenuto di un altro tutor, in un altro corso'],
            ['/domande', 'negato', 'la pagina delle domande è di chi risponde'],
            ['/domande-e-risposte', 'consentito', 'l\'archivio delle domande e risposte dei suoi corsi'],

            // I tentativi dei quiz (17/10): ognuno vede i propri.
            ['/attempts/' + A.tentativo, 'consentito', 'il proprio tentativo'],
            ['/attempts/' + B.tentativo, 'negato', 'il tentativo di un altro studente'],

            // I certificati (17/10): il file c'e' sul disco (lo mette la
            // semina), quindi un rifiuto qui e' della regola.
            ['/certificates/' + A.certificato + '/download', 'consentito', 'il proprio certificato'],
            ['/certificates/' + B.certificato + '/download', 'negato', 'il certificato di un altro studente'],
        ]],

        ['tutor1@test.it', 'tutor del mondo A', [
            // I tentativi dei quiz (17/10): il tutor vede quelli degli
            // studenti dei suoi gruppi, come il loro report. Fino al 17/10
            // bastava essere tutor per aprire quelli di chiunque.
            ['/attempts/' + A.tentativo, 'consentito', 'tentativo di uno studente di un suo gruppo'],
            ['/attempts/' + B.tentativo, 'negato', 'tentativo di uno studente del gruppo di un collega'],
            // Gli incontri dal vivo (17/10): la pagina si apre se l'incontro
            // e' nel suo elenco, cioe' del suo corso o del suo gruppo. Prima
            // bastava essere staff per aprire quello di chiunque, link Meet
            // compreso.
            ['/live/' + A.incontro, 'consentito', 'incontro del suo gruppo'],
            ['/live/' + B.incontro, 'negato', 'incontro del gruppo di un collega'],
            // La fruizione dei video di una lezione (17/10): solo nei suoi
            // corsi. Prima `report.view`, che il tutor ha, apriva quella di
            // qualunque lezione, con tutti gli iscritti.
            ['/lessons/' + A.lezione + '/fruizione', 'consentito', 'fruizione di una lezione del suo corso'],
            ['/lessons/' + B.lezione + '/fruizione', 'negato', 'fruizione di una lezione del corso di un collega'],
            ['/lessons/' + B.lezione + '/fruizione/csv', 'negato', 'nemmeno scaricandola'],
            // Lo stesso per i certificati (17/10).
            ['/certificates/' + A.certificato + '/download', 'consentito', 'certificato di uno studente di un suo gruppo'],
            ['/certificates/' + B.certificato + '/download', 'negato', 'certificato di uno studente del gruppo di un collega'],

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
            ['/admin/users/importa', 'negato', 'importare utenti è dell\'admin'],
            ['/admin/permissions', 'negato', 'i permessi sono dell\'admin'],
            ['/admin/settings', 'negato', 'le impostazioni sono dell\'admin'],

            // Lo staff non è mai soggetto al rilascio: un modulo che si apre
            // fra un mese va preparato oggi, e chi lo prepara deve entrarci.
            ['/lessons/' + A.lezione_chiusa + '/edit', 'consentito',
                'il tutor prepara il modulo chiuso prima che si apra'],
            ['/live/' + A.incontro_chiuso, 'consentito',
                'l\'incontro del modulo chiuso è suo da organizzare'],
            ['/gruppi/' + A.gruppo, 'consentito', 'è il tutor del gruppo, anche se non ne è membro'],
            ['/gruppi/' + B.gruppo, 'negato', 'gruppo di un collega'],
            ['/utenti/' + d.utenti.studenteA + '/immagine', 'consentito', 'studente di un suo gruppo'],
            ['/utenti/' + d.utenti.studenteB + '/immagine', 'negato', 'studente del gruppo di un collega'],
            ['/benvenuti/' + A.benvenuto + '/audio', 'consentito', 'il proprio benvenuto'],
            ['/benvenuti/' + B.benvenuto + '/audio', 'negato', 'il benvenuto di un collega'],
            ['/domande', 'negato', 'risponde l\'esperto, l\'admin (09/10)'],
            ['/domande-e-risposte', 'consentito', 'legge «L\'esperto risponde» come uno studente'],
        ]],

        ['assist@test.it', 'assistente del tutor A', [
            ['/reports', 'consentito', 'vede i report, ristretti al suo perimetro'],
            ['/reports/elenco/students', 'consentito',
                'l\'elenco si apre: a restringerlo sono le righe, non la porta'],
            ['/reports/courses/' + A.corso, 'consentito', 'corso del tutor che segue'],
            ['/reports/courses/' + B.corso, 'negato', 'corso di un tutor che non segue'],
            ['/reports/students/' + d.utenti.studenteB, 'negato', 'studente fuori dal suo perimetro'],
            ['/attempts/' + A.tentativo, 'consentito', 'tentativo di uno studente del tutor che segue'],
            // Gli incontri (17/10): l'assistente apre quelli del suo elenco,
            // dove compare come destinatario, come uno studente. Quelli dei
            // gruppi del suo tutor non ci sono, e quindi nemmeno la pagina.
            ['/live/' + B.incontro, 'negato', 'incontro di un tutor che non segue'],
            ['/live/' + A.incontro, 'negato', 'incontro di un gruppo di cui non fa parte: non è nel suo elenco'],
            ['/attempts/' + B.tentativo, 'negato', 'tentativo di uno studente fuori dal suo perimetro'],
            ['/certificates/' + A.certificato + '/download', 'consentito', 'certificato di uno studente del tutor che segue'],
            ['/certificates/' + B.certificato + '/download', 'negato', 'certificato di uno studente fuori dal suo perimetro'],
            ['/lessons/' + A.lezione + '/edit', 'negato', 'un assistente non modifica le lezioni'],
            ['/admin/users', 'negato', 'nessuna gestione utenti'],
            ['/admin/users/importa', 'negato', 'nemmeno importarli da un file'],
            ['/admin/courses', 'negato', 'nessuna gestione corsi'],
            // Elena il 06/10: le foto le vedono admin, tutor e compagni.
            // L'assistente no, anche dello studente del tutor che segue.
            ['/utenti/' + d.utenti.studenteA + '/immagine', 'negato',
                'l\'assistente non è fra chi vede le foto'],
            ['/gruppi/' + A.gruppo, 'negato', 'né la pagina del gruppo'],
            ['/benvenuti/' + A.benvenuto + '/audio', 'negato', 'il benvenuto non è per lo staff che non lo carica'],
            ['/domande', 'negato', 'l\'assistente non risponde alle domande'],
        ]],

        ['admin@test.it', 'amministratore', [
            ['/courses/' + B.corso, 'consentito', 'l\'admin vede tutto'],
            ['/lessons/' + B.lezione + '/edit', 'consentito', 'l\'admin modifica tutto'],
            ['/reports/courses/' + B.corso, 'consentito', 'nessuna restrizione sui report'],
            ['/attempts/' + B.tentativo, 'consentito', 'l\'admin vede i tentativi di tutti'],
            ['/live/' + B.incontro, 'consentito', 'l\'admin apre ogni incontro'],
            ['/lessons/' + B.lezione + '/fruizione', 'consentito', 'l\'admin vede la fruizione di ogni lezione'],
            ['/certificates/' + B.certificato + '/download', 'consentito', 'l\'admin scarica i certificati di tutti'],
            ['/admin/permissions', 'consentito', 'è sua'],
            ['/admin/users/importa', 'consentito', 'l\'importazione è sua'],
            ['/admin/settings/bunny', 'consentito', 'è sua'],
            ['/lessons/' + A.lezione_chiusa, 'consentito', 'il rilascio non vale per l\'admin'],
            ['/materials/' + A.materiale_chiuso + '/download', 'consentito',
                'il rilascio non vale per l\'admin, e il file di prova esiste'],
            ['/gruppi/' + B.gruppo, 'consentito', 'l\'admin vede ogni gruppo'],
            ['/utenti/' + d.utenti.studenteB + '/immagine', 'consentito',
                'controprova: la foto di B esiste, quindi chi la rifiuta lo fa per la regola'],
            ['/benvenuti/' + B.benvenuto + '/audio', 'consentito',
                'controprova: l\'audio di B esiste, e l\'admin carica i benvenuti'],
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

        // --- il contenuto dell'elenco, non solo la porta ------------------
        //
        // L'elenco degli studenti è l'unica pagina dei report che si apre
        // per tutti e tre i ruoli dello staff e mostra righe **diverse** a
        // ciascuno. Qui il controllo "risponde 200?" non dice niente: la
        // porta deve aprirsi, e il difetto sarebbe dentro. Dal 04/10
        // c'è anche una ricerca, cioè un modo di chiedere per nome proprio
        // la riga che non si dovrebbe vedere: si prova anche quella.

        console.log('\n--- l\'elenco degli studenti: che cosa c\'è dentro');

        {
            // Si cerca l'**email**, non il nome: il nome dello studente B è
            // apposta pieno di virgolette e di `<tag>`, quindi nella pagina
            // arriva trasformato e un confronto con la stringa originale
            // fallirebbe sempre — dicendo "non c'è" anche quando c'è.
            const emailB = 'strano@test.it';
            const quanteRighe = (html) => (html.match(/data-label="Studente"/g) || []).length;

            const { ctx, page } = await entra(browser, 'assist@test.it');

            await page.goto(BASE + '/reports/elenco/students');
            const senzaRicerca = !(await page.content()).includes(emailB);
            check(
                'assistente → l\'elenco non contiene lo studente del mondo B',
                senzaRicerca,
                senzaRicerca ? [] : ['«' + emailB + '» compare in una pagina che risponde 200']
            );

            await page.goto(BASE + '/reports/elenco/students?cerca=' + encodeURIComponent(emailB));
            const conRicerca = await page.content();
            // Qui si contano le righe e basta: l'email cercata torna
            // comunque nella pagina, dentro al campo di ricerca e nella
            // frase «0 studenti per …», quindi cercarla nel testo direbbe
            // "c'è" anche con la tabella vuota.
            check(
                'assistente → e non lo trova nemmeno cercandolo per email',
                quanteRighe(conRicerca) === 0,
                quanteRighe(conRicerca) === 0 ? [] : ['la ricerca ha restituito ' + quanteRighe(conRicerca) + ' righe']
            );

            await ctx.close();

            const admin = await entra(browser, 'admin@test.it');
            await admin.page.goto(BASE + '/reports/elenco/students?cerca=' + encodeURIComponent(emailB));
            const daAdmin = await admin.page.content();
            const loTrova = daAdmin.includes(emailB) && quanteRighe(daAdmin) === 1;
            check(
                'controprova: l\'amministratore lo trova',
                loTrova,
                loTrova ? [] : ['se non lo trovasse nemmeno lui, le due prove qui sopra non direbbero niente']
            );
            await admin.ctx.close();
        }

        // --- l'elenco dei certificati: che cosa c'è dentro ----------------
        //
        // /certificates si apre a tutti: lo studente vede i propri, lo staff
        // quelli degli studenti che segue, l'admin tutti (17/10; prima lo
        // staff li vedeva tutti). Si guarda dentro, cercando i codici dei due
        // certificati della semina, che cominciano con PROVA-A- e PROVA-B-.

        console.log('\n--- l\'elenco dei certificati: che cosa c\'è dentro');

        for (const [email, chi, vedeA, vedeB] of [
            ['stud@test.it', 'studente del mondo A', true, false],
            ['tutor1@test.it', 'tutor del mondo A', true, false],
            ['assist@test.it', 'assistente del tutor A', true, false],
            ['tutor2@test.it', 'tutor del mondo B', false, true],
            ['admin@test.it', 'amministratore (controprova)', true, true],
        ]) {
            const { ctx, page } = await entra(browser, email);
            await page.goto(BASE + '/certificates');
            const html = await page.content();
            const a = html.includes('PROVA-A-');
            const b = html.includes('PROVA-B-');
            check(chi + ' → nei certificati ' + (vedeA ? 'c\'è' : 'non c\'è') + ' quello del mondo A, '
                + (vedeB ? 'c\'è' : 'non c\'è') + ' quello del mondo B',
                a === vedeA && b === vedeB, ['mondo A: ' + (a ? 'c\'è' : 'non c\'è') + ', mondo B: ' + (b ? 'c\'è' : 'non c\'è')]);
            await ctx.close();
        }

        // --- la fruizione di una lezione: che cosa c'è dentro --------------
        //
        // Su un corso suo il tutor vede gli studenti che segue, non tutti gli
        // iscritti: un corso puo' essere assegnato a piu' gruppi, con tutor
        // diversi (17/10). Per provarlo lo studente B si iscrive al corso A
        // per la durata della prova, e alla fine si toglie.

        console.log('\n--- la fruizione di una lezione: che cosa c\'è dentro');

        {
            const radice = path.join(__dirname, '..');
            const sql = (q) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";'
                + ' App\\Core\\Database::connection()->prepare($argv[1])->execute([(int) $argv[2], (int) $argv[3]]);',
                '--', q, String(d.utenti.studenteB), String(A.corso)], { cwd: radice, encoding: 'utf8' });

            sql('INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)');

            try {
                for (const [email, chi, vedeB] of [
                    ['tutor1@test.it', 'tutor del mondo A', false],
                    ['admin@test.it', 'amministratore (controprova)', true],
                ]) {
                    const { ctx, page } = await entra(browser, email);
                    await page.goto(BASE + '/lessons/' + A.lezione + '/fruizione');
                    const html = await page.content();
                    const a = html.includes('stud@test.it');
                    const b = html.includes('strano@test.it');
                    check(chi + ' → nella fruizione della lezione del corso A c\'è lo studente A, '
                        + (vedeB ? 'e anche' : 'non') + ' lo studente B iscritto allo stesso corso',
                        a && b === vedeB, ['studente A: ' + (a ? 'c\'è' : 'non c\'è') + ', studente B: ' + (b ? 'c\'è' : 'non c\'è')]);
                    await ctx.close();
                }
            } finally {
                sql('DELETE FROM enrollments WHERE user_id = ? AND course_id = ?');
            }
        }

        // --- il video di benvenuto ---------------------------------------
        //
        // Non e' un permesso ma un **dirottamento**, e sbagliarlo si paga
        // caro in tutte e due le direzioni: se scatta per chi non deve,
        // nessuno riesce piu' a usare la piattaforma; se non scatta per chi
        // deve, la funzione semplicemente non c'e'. Si prova con l'unico
        // utente che la semina lascia al primo accesso.

        // --- la pagina del gruppo: che cosa c'è dentro --------------------
        //
        // La porta si apre allo studente del gruppo; qui si guarda dentro.
        // Due cose decise da Elena: **nessuna email**, e nessuno che non
        // faccia parte del gruppo. Lo studente di B non deve comparire
        // nemmeno come nome.

        // --- la colonna Tutor dell'elenco utenti (06/10) ----------------
        //
        // Il tutor di riferimento dipende dal ruolo: per lo studente i tutor
        // dei suoi gruppi, per l'assistente i tutor che affianca, per il tutor
        // nessuno. Lo studente di A sta in due gruppi dello stesso tutor (A e
        // «cerchio»), quindi il nome deve comparire una volta sola.

        console.log('\n--- l\'elenco utenti: la colonna Tutor');

        {
            const { ctx, page } = await entra(browser, 'admin@test.it');
            await page.goto(BASE + '/admin/users');

            const riga = async (email) => page.evaluate((email) => {
                const tr = [...document.querySelectorAll('tbody tr')]
                    .find((r) => r.querySelector('td[data-label="Email"]')?.textContent.trim() === email);

                return tr ? {
                    nome: tr.querySelector('td[data-label="Nome"]').textContent.trim(),
                    tutor: tr.querySelector('td[data-label="Tutor"]').textContent.trim(),
                } : null;
            }, email);

            const tutorA = await riga('tutor1@test.it');
            const studente = await riga('stud@test.it');
            const assistente = await riga('assist@test.it');

            check(
                'admin → lo studente ha come tutor quello dei suoi gruppi, una volta sola',
                tutorA !== null && studente !== null && studente.tutor === tutorA.nome,
                studente === null ? ['riga dello studente non trovata'] : ['trovato: «' + studente.tutor + '»']
            );
            check(
                'admin → l\'assistente ha come tutor quello che affianca',
                tutorA !== null && assistente !== null && assistente.tutor === tutorA.nome,
                assistente === null ? ['riga dell\'assistente non trovata'] : ['trovato: «' + assistente.tutor + '»']
            );
            check(
                'admin → un tutor non ha un tutor di riferimento',
                tutorA !== null && tutorA.tutor === '—',
                tutorA === null ? ['riga del tutor non trovata'] : ['trovato: «' + tutorA.tutor + '»']
            );

            await ctx.close();
        }

        console.log('\n--- la pagina del gruppo: che cosa c\'è dentro');

        {
            const { ctx, page } = await entra(browser, 'stud@test.it');
            await page.goto(BASE + '/gruppi/' + d.gruppo_cerchio);
            const html = await page.content();
            const persone = await page.locator('.gruppo-cerchio .persona').count();
            const titolo = (await page.textContent('h1')).trim();

            // «Gruppo …» in cima, non il nome da solo (06/10).
            check(
                'studente → il titolo della pagina dice che è un gruppo',
                titolo === 'Gruppo [prova-permessi] cerchio',
                titolo === 'Gruppo [prova-permessi] cerchio' ? [] : ['titolo: ' + titolo]
            );

            check(
                'studente → la pagina del gruppo mostra il tutor e gli otto partecipanti',
                persone === 9,
                persone === 9 ? [] : ['trovate ' + persone + ' persone invece di 9']
            );
            // Nel testo, non nell'HTML intero: la barra laterale non c'entra,
            // ma un `mailto:` o un `title` nascosto si', quindi l'HTML.
            const email = (html.match(/[\w.+-]+@test\.it/g) || []);
            check(
                'studente → nella pagina del gruppo non c\'è nessuna email',
                email.length === 0,
                email.length === 0 ? [] : ['trovate: ' + [...new Set(email)].join(', ')]
            );
            // Per la foto e non per il nome: il nome di B, nel database di
            // prova, e' pieno di virgolette e di `<tag>` e arriva trasformato.
            // La foto di B esiste, quindi un elenco sbagliato la mostrerebbe.
            const fotoB = '/utenti/' + d.utenti.studenteB + '/immagine';
            check(
                'studente → nella pagina del gruppo non c\'è lo studente del mondo B',
                !html.includes(fotoB),
                html.includes(fotoB) ? ['la pagina carica ' + fotoB] : []
            );


            // La presentazione (06/10): quella del tutor del gruppo si legge,
            // quella dello studente di B, che con A non ha nessun gruppo, no.
            // Nel testo intero, popover chiusi compresi: chiusi non si vedono,
            // ma stanno nell'HTML, ed e' l'HTML che arriva al browser.
            check(
                'studente → nella pagina del gruppo c\'è la presentazione del tutor',
                html.includes('Presentazione del tutor del mondo A.')
            );
            check(
                'studente → nella pagina del gruppo non c\'è la presentazione dello studente di B',
                !html.includes('Presentazione dello studente del mondo B')
            );

            await ctx.close();
        }

        // --- come compaiono gli studenti (07/10) ---------------------------
        //
        // Il compagno 5 ha scelto le iniziali, il 6 il solo nome. Lo
        // studente di A li vede cosi', e il loro nome intero non deve stare
        // nemmeno nell'HTML; il tutor del gruppo li vede per intero.

        console.log('\n--- come compaiono gli studenti agli altri');

        for (const [email, chi, intero] of [['stud@test.it', 'studente', false], ['tutor1@test.it', 'tutor del gruppo', true]]) {
            const { ctx, page } = await entra(browser, email);
            await page.goto(BASE + '/gruppi/' + d.gruppo_cerchio);
            const nomi = await page.$$eval('.gruppo-cerchio .persona-nome', (ns) => ns.map((n) => n.childNodes[0].textContent.trim()));
            const html = await page.content();

            // Sotto il proprio nome, solo per se', come lo vedono gli altri
            // (08/10): lo studente ne ha una sola, sotto il suo nome intero;
            // lo staff nessuna, perche' compare sempre per intero.
            const righe = await page.$$eval('.persona-come-ti-vedono', (r) => r.map((x) => {
                // Il testo, non il riquadro: su una riga sola puo' sporgere
                // dal suo spazio, e deve farlo verso l'esterno, non sulla foto.
                const rg = document.createRange();
                rg.selectNodeContents(x);
                const tr = rg.getBoundingClientRect();
                const f = x.closest('.persona').querySelector('.persona-foto').getBoundingClientRect();
                return {
                    testo: x.textContent.trim(),
                    nome: x.closest('.persona-nome').childNodes[0].textContent.trim(),
                    cerchio: x.closest('.usa-cerchio') !== null && getComputedStyle(x.closest('.gruppo-cerchio')).display === 'block',
                    unaRiga: tr.height <= parseFloat(getComputedStyle(x).fontSize) * 1.6,
                    sullaFoto: !(tr.right <= f.left || tr.left >= f.right || tr.bottom <= f.top || tr.top >= f.bottom),
                };
            }));
            if (intero) {
                check(chi + ' → nessuna riga «Gli altri ti vedono come»: lo staff compare sempre per intero',
                    righe.length === 0, [JSON.stringify(righe)]);
            } else {
                check(chi + ' → sotto il proprio nome intero, e solo lì, «Gli altri ti vedono come …»',
                    righe.length === 1 && righe[0].testo.startsWith('Gli altri ti vedono come «') && righe[0].nome.startsWith('Studente Prova'),
                    [JSON.stringify(righe)]);
                // Nel cerchio su una riga sola, e mai sulla foto (09/10, Elena;
                // la prima versione su una riga sporgeva sotto la foto).
                check(chi + ' → la riga non finisce sulla foto, e nel cerchio sta su una riga sola',
                    righe.length === 1 && !righe[0].sullaFoto && (!righe[0].cerchio || righe[0].unaRiga),
                    [JSON.stringify(righe)]);
            }

            if (intero) {
                check(chi + ' → vede per intero chi ha scelto le iniziali o il solo nome',
                    nomi.includes('Compagno 5') && nomi.includes('Compagno 6'), [JSON.stringify(nomi)]);
            } else {
                check(chi + ' → vede le iniziali di chi le ha scelte, e il solo nome di chi ha scelto quello',
                    nomi.includes('C. 5.') && nomi.includes('Compagno') && !nomi.includes('Compagno 6'), [JSON.stringify(nomi)]);
                check(chi + ' → il nome intero di chi ha scelto le iniziali non c\'è nemmeno nell\'HTML',
                    !html.includes('Compagno 5'), html.includes('Compagno 5') ? ['trovato «Compagno 5»'] : []);
            }

            await ctx.close();
        }

        // --- le domande: che cosa c'e' dentro (07/10) ----------------------
        //
        // Dal 09/10 l'archivio sta nella pagina «Domande e risposte», non piu'
        // in fondo al corso. Lo studente di A trova le due pubblicate della
        // semina, divise per modulo, e non quelle in attesa o scartate; nella
        // pagina del corso l'archivio non c'e' piu'. Lo studente di B, che
        // scrive a mano il corso di A nell'indirizzo, non vede le sue domande.
        // Il tutor non ha la pagina: ha «Domande», con il numero di quelle in
        // attesa.

        console.log('\n--- le domande: che cosa c\'è dentro');

        {
            const { ctx, page } = await entra(browser, 'stud@test.it');
            await page.goto(BASE + '/domande-e-risposte?corso=' + A.corso);
            const archivio = await page.$$eval('.qa-voce summary', (s) => s.map((x) => x.textContent.trim()));
            check('studente → nell\'archivio ci sono le pubblicate, e non quelle in attesa o scartate',
                archivio.some((q) => q.includes('pubblicata sul modulo')) && archivio.some((q) => q.includes('corso in generale'))
                    && !archivio.some((q) => q.includes('scartata')) && !archivio.some((q) => q.includes('in attesa')),
                [JSON.stringify(archivio)]);
            const moduli = await page.$$eval('.qa-modulo', (h) => h.map((x) => x.textContent.trim()));
            check('studente → l\'archivio è diviso per modulo, il corso in generale in fondo',
                moduli.length >= 2 && moduli[moduli.length - 1] === 'Il corso in generale', [JSON.stringify(moduli)]);
            await page.goto(BASE + '/courses/' + A.corso);
            const nelCorso = await page.locator('#domande .qa-voce').count();
            check('studente → nella pagina del corso l\'archivio non c\'è più', nelCorso === 0, ['domande: ' + nelCorso]);
            await ctx.close();
        }
        {
            const { ctx, page } = await entra(browser, 'strano@test.it');
            await page.goto(BASE + '/domande-e-risposte?corso=' + A.corso);
            const diA = await page.locator('.qa-voce', { hasText: 'mondo A' }).count();
            const titolo = ((await page.locator('.qa-archivio h2').textContent().catch(() => '')) || '').trim();
            check('studente B → chiedendo a mano il corso di A, non ne vede le domande', diA === 0 && !titolo.includes('mondo A'),
                ['domande di A: ' + diA + ', corso aperto: ' + titolo]);
            await ctx.close();
        }
        {
            // Il numero di quelle in attesa lo vede l'esperto, l'admin (09/10).
            const { ctx, page } = await entra(browser, 'admin@test.it');
            await page.goto(BASE + '/');
            const voce = (await page.locator('a.nav-link[href="/domande"]').innerText()).replace(/\s+/g, ' ').trim();
            check('admin → nel menu, «Domande» con il numero di quelle in attesa', /^Domande \d+ in attesa$/.test(voce), ['voce: ' + voce]);
            await ctx.close();
        }
        {
            // Il tutor non ha «Domande», e ha «L'esperto risponde».
            const { ctx, page } = await entra(browser, 'tutor1@test.it');
            await page.goto(BASE + '/');
            const voci = await page.$$eval('.sidebar-nav a.nav-link', (a) => a.map((x) => x.textContent.trim()));
            check('tutor → nel menu «L\'esperto risponde» e non «Domande»',
                voci.includes('L\'esperto risponde') && !voci.some((v) => v.startsWith('Domande')), [JSON.stringify(voci)]);
            await ctx.close();
        }

        console.log('\n--- il video di benvenuto');

        {
            const admin = await entra(browser, 'admin@test.it');

            // Lo stato di partenza si legge e si rimette a posto alla fine:
            // questa prova cambia un'impostazione vera del database.
            await admin.page.goto(BASE + '/admin/settings/benvenuto');

            // Prima di tutto: l'admin e' arrivato dove voleva. Se la regola
            // sul ruolo si rompesse, l'admin verrebbe dirottato al benvenuto
            // e tutto il resto di questo blocco esploderebbe su un campo che
            // non c'e' — un errore di JavaScript invece di una prova rossa,
            // che e' un modo peggiore di dire la stessa cosa.
            const paginaAdmin = await admin.page.$('#welcome-provider');
            check(
                'l\'admin raggiunge le impostazioni del benvenuto',
                paginaAdmin !== null,
                paginaAdmin !== null ? [] : ['dirottato su ' + new URL(admin.page.url()).pathname]
            );

            if (paginaAdmin === null) {
                await admin.ctx.close();
                return;
            }

            const prima = await admin.page.evaluate(() => ({
                provider: document.querySelector('#welcome-provider').value,
                ref: document.querySelector('#welcome-ref').value,
            }));

            const configura = async (provider, ref) => {
                await admin.page.goto(BASE + '/admin/settings/benvenuto');
                await admin.page.selectOption('#welcome-provider', provider);
                await admin.page.fill('#welcome-ref', ref);
                await Promise.all([
                    admin.page.waitForNavigation(),
                    admin.page.click('form.form button[type="submit"]'),
                ]);
            };

            const dove = async (page, url) => {
                await page.goto(BASE + url);

                return new URL(page.url()).pathname;
            };

            // Dal 08/10 lo studente al primo accesso sceglie prima come lo
            // vedono gli altri, nella pagina «Primo accesso»: qui la si
            // completa, e da li' in poi le prove del video sono quelle di prima.
            const completaPrimoAccesso = async (page, password = null) => {
                if (new URL(page.url()).pathname !== '/primo-accesso') {
                    await page.goto(BASE + '/primo-accesso');
                }
                if (new URL(page.url()).pathname !== '/primo-accesso') {
                    return;
                }
                if (password !== null) {
                    await page.fill('#current_password', PASSWORD);
                    await page.fill('#new_password', password);
                    await page.fill('#confirm_password', password);
                }
                await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
            };

            // 1. Senza video non si dirotta nessuno: il benvenuto si accende
            //    mettendo il video, non con un interruttore a parte.
            await configura('none', '');
            const nuovoSenza = await entra(browser, 'nuovo@test.it');
            await completaPrimoAccesso(nuovoSenza.page);
            const senza = await dove(nuovoSenza.page, '/');
            check(
                'senza video configurato nessuno viene portato al benvenuto',
                senza === '/',
                senza === '/' ? [] : ['e finito su ' + senza]
            );
            await nuovoSenza.ctx.close();

            // 2. Con il video, chi non l'ha ancora visto ci finisce da
            //    qualunque pagina parta.
            await configura('bunny', 'prova-benvenuto');
            const nuovo = await entra(browser, 'nuovo@test.it');

            for (const url of ['/', '/profilo', '/agenda']) {
                const finito = await dove(nuovo.page, url);
                check(
                    'studente al primo accesso: ' + url + ' porta al benvenuto',
                    finito === '/benvenuto',
                    finito === '/benvenuto' ? [] : ['e finito su ' + finito]
                );
            }

            // 2b. Il primo accesso con il video configurato (08/10): prima la
            //     pagina «Primo accesso», poi il video. Al primo giro i due
            //     cancelli si rimandavano a vicenda senza fine.
            {
                const primo = await entra(browser, 'primo@test.it');
                const prima = new URL(primo.page.url()).pathname;
                check('primo accesso con il video: prima la pagina «Primo accesso»', prima === '/primo-accesso',
                    prima === '/primo-accesso' ? [] : ['e finito su ' + prima]);
                await completaPrimoAccesso(primo.page, 'NuovaPass9');
                const poi = new URL(primo.page.url()).pathname;
                check('primo accesso con il video: dopo «Continua», il video', poi === '/benvenuto',
                    poi === '/benvenuto' ? [] : ['e finito su ' + poi]);
                await primo.ctx.close();
            }

            // 3. Il pulsante registra la visione e libera la navigazione.
            //    Senza questa prova il difetto trovato a mano — la POST che
            //    veniva dirottata verso la pagina stessa, cioe' un giro che
            //    non si chiudeva mai — tornerebbe senza che nessuno lo veda.
            await nuovo.page.goto(BASE + '/benvenuto');
            await Promise.all([
                nuovo.page.waitForNavigation(),
                nuovo.page.click('.benvenuto-azioni button'),
            ]);
            const dopo = await dove(nuovo.page, '/');
            check(
                'dopo «Vai ai miei corsi» non viene piu\' dirottato',
                dopo === '/',
                dopo === '/' ? [] : ['e finito su ' + dopo]
            );

            // 4. E il video resta rivedibile dal profilo.
            await nuovo.page.goto(BASE + '/profilo');
            const rivedi = await nuovo.page.$('.profilo-benvenuto a');
            check('il profilo offre di rivedere il benvenuto', rivedi !== null);
            await nuovo.ctx.close();

            // 5. Lo staff non ci finisce mai: entra per lavorare.
            for (const [email, ruolo] of [
                ['admin@test.it', 'l\'amministratore'],
                ['tutor1@test.it', 'il tutor'],
                ['assist@test.it', 'l\'assistente'],
            ]) {
                const s = await entra(browser, email);
                const dovE = await dove(s.page, '/');
                check(
                    ruolo + ' non viene portato al benvenuto',
                    dovE === '/',
                    dovE === '/' ? [] : ['e finito su ' + dovE]
                );
                await s.ctx.close();
            }

            await configura(prima.provider, prima.ref);
            await admin.ctx.close();
        }

        // --- un permesso che non si raggiunge non e' un permesso ---------
        //
        // Il tutor *poteva* modificare ed eliminare il proprio quiz — le
        // prove qui sopra lo dicono — ma dall'interfaccia non si arrivava:
        // l'unica via era un collegamento etichettato «Quiz» in mezzo a dei
        // verbi, e dalla pagina del quiz non c'era niente. Per chi lo
        // cercava equivaleva a una funzione mancante. Qui si verifica la
        // **via**, non il diritto: che dalle due pagine dove si guarda un
        // quiz si arrivi a modificarlo, e che allo studente non compaia.

        console.log('\n--- la via per modificare un quiz');

        {
            const viaDa = async (page, url) => {
                await page.goto(BASE + url);

                return await page.$$eval(
                    'a[href$="/edit"]',
                    (link, atteso) => link.some((a) => a.getAttribute('href') === atteso),
                    '/quizzes/' + String(A.quiz) + '/edit'
                );
            };

            const staff = await entra(browser, 'tutor1@test.it');

            for (const [url, dove] of [
                ['/courses/' + A.corso, 'dalla pagina del corso'],
                ['/quizzes/' + A.quiz, 'dalla pagina del quiz'],
            ]) {
                const c = await viaDa(staff.page, url);
                check(
                    'tutor A → si arriva a modificare il quiz ' + dove,
                    c,
                    c ? [] : ['nessun collegamento a /quizzes/' + A.quiz + '/edit']
                );
            }

            await staff.ctx.close();

            // Controprova: allo studente quella via non si mostra. Senza,
            // le due prove qui sopra passerebbero anche con il collegamento
            // stampato per tutti.
            const studente = await entra(browser, 'stud@test.it');
            const visto = await viaDa(studente.page, '/quizzes/' + A.quiz);
            check(
                'controprova: allo studente il collegamento non compare',
                !visto,
                visto ? ['lo studente vede la via per modificare il quiz'] : []
            );
            await studente.ctx.close();
        }

        // --- l'agenda: quello che non deve contenere ---------------------
        //
        // L'agenda e' un elenco di cose che esistono altrove, ed e'
        // esattamente il genere di pagina da cui si scopre per sbaglio
        // l'esistenza di un corso altrui. Qui si guarda **dentro**: lo
        // studente del mondo A non deve trovarci l'incontro del mondo B,
        // ne' nell'elenco ne' nella griglia del mese ne' nel file .ics.

        console.log('\n--- l\'agenda: che cosa c\'è dentro');

        {
            const incontroB = String(B.incontro);
            const { ctx, page } = await entra(browser, 'stud@test.it');

            for (const [url, dove] of [['/agenda', 'nell\'elenco'], ['/agenda?vista=mese', 'nel mese']]) {
                await page.goto(BASE + url);
                const html = await page.content();

                const assente = !html.includes('/live/' + incontroB);
                check(
                    'studente A → l\'incontro del mondo B non compare ' + dove,
                    assente,
                    assente ? [] : ['trovato un collegamento a /live/' + incontroB]
                );
            }

            // Controprova: l'incontro del SUO mondo c'e'. Senza, le due
            // prove qui sopra passerebbero anche con l'agenda rotta.
            await page.goto(BASE + '/agenda');
            const ilSuo = (await page.content()).includes('/live/' + String(A.incontro));
            check(
                'controprova: il suo incontro c\'è',
                ilSuo,
                ilSuo ? [] : ['se non c\'e nemmeno il suo, le prove qui sopra non dicono niente']
            );

            // Il file .ics del singolo incontro segue la stessa regola
            // della pagina: non e' un indirizzo piu' permissivo.
            const suo = await page.request.get(BASE + '/agenda/evento/' + String(A.incontro) + '.ics');
            const altrui = await page.request.get(BASE + '/agenda/evento/' + incontroB + '.ics');

            check('studente A → il .ics del suo incontro si scarica', suo.status() === 200);
            check(
                'studente A → il .ics di un incontro non suo non esiste',
                altrui.status() === 404,
                altrui.status() === 404 ? [] : ['ha risposto ' + altrui.status()]
            );

            // «Aggiungi al calendario» non si offre per un incontro gia' finito:
            // mettere in agenda un appuntamento passato non serve a niente.
            // Si guarda la voce, non la pagina: il collegamento c'e' sugli
            // incontri futuri, e la controprova e' quella che distingue
            // «tolto dove va tolto» da «tolto dappertutto».
            const linkIcs = async (id) => await page.$$eval(
                '.agenda-voce',
                (voci, atteso) => voci
                    .filter(v => v.querySelector('a[href="/live/' + atteso + '"]'))
                    .some(v => v.querySelector('a[href$="/' + atteso + '.ics"]')),
                String(id)
            );

            const concluso = await linkIcs(A.incontro_concluso);
            check(
                'studente A → nello Storico non si offre «Aggiungi al calendario»',
                !concluso,
                concluso ? ['l\'incontro concluso ha ancora il collegamento al .ics'] : []
            );

            const futuro = await linkIcs(A.incontro);
            check(
                'controprova: su un incontro futuro «Aggiungi al calendario» c\'è',
                futuro,
                futuro ? [] : ['se mancasse anche li\', la prova qui sopra non direbbe niente']
            );

            // «Entra» solo dentro alla finestra: da un quarto d'ora prima
            // fino alla fine. Si prova sulle due pagine che lo mostrano —
            // l'agenda e l'elenco degli incontri — e su tutti e tre gli
            // stati, perche' la stessa regola era scritta in tre modi
            // diversi e nessuno se ne accorgeva guardando una pagina sola.
            const comandoEntra = async (url, id) => {
                await page.goto(BASE + url);

                return await page.$$eval(
                    'tr, .agenda-voce',
                    (righe, atteso) => righe
                        .filter(r => r.querySelector('a[href*="/live/' + atteso + '"]'))
                        .some(r => r.querySelector('a[href="/live/' + atteso + '/join"]')),
                    String(id)
                );
            };

            for (const [url, dove] of [['/agenda', 'in agenda'], ['/live', 'in Sessioni live']]) {
                const lontano = await comandoEntra(url, A.incontro);
                check(
                    'studente A → «Entra» non c\'è su un incontro fra tre giorni ' + dove,
                    !lontano,
                    lontano ? ['il comando compare fuori dalla finestra d\'ingresso'] : []
                );

                const imminente = await comandoEntra(url, A.incontro_imminente);
                check(
                    'controprova: «Entra» c\'è su un incontro che comincia fra 5 minuti ' + dove,
                    imminente,
                    imminente ? [] : ['se mancasse anche qui, la prova sopra non direbbe niente']
                );

                const finito = await comandoEntra(url, A.incontro_concluso);
                check(
                    'studente A → «Entra» non c\'è su un incontro concluso ' + dove,
                    !finito,
                    finito ? ['il comando compare su una riunione gia\' finita'] : []
                );
            }

            await page.goto(BASE + '/agenda');

            // Il calendario sottoscritto risponde senza accesso: e' il solo
            // indirizzo che lo fa, e regge solo finche' il token e'
            // imprevedibile e verificato.
            const anonimo = await browser.newContext();
            const ap = await anonimo.newPage();

            for (const token of ['0'.repeat(48), 'abc', '1', '../../etc/passwd']) {
                const r = await ap.request.get(BASE + '/calendario/' + encodeURIComponent(token) + '.ics');
                check(
                    'un token inventato («' + token.slice(0, 12) + '») non apre nessun calendario',
                    r.status() === 404,
                    r.status() === 404 ? [] : ['ha risposto ' + r.status()]
                );
            }

            // Le due date accanto all'indirizzo sono quello su cui una
            // persona decide se revocarlo. Se la lettura non venisse
            // registrata, la pagina direbbe «mai» a un calendario che
            // qualcuno sta leggendo da settimane: una rassicurazione
            // falsa, che e' peggio del non dire niente.
            await page.goto(BASE + '/agenda');
            const creaOrigenera = await page.$('.agenda-calendario button');
            await Promise.all([page.waitForNavigation(), creaOrigenera.click()]);

            const indirizzo = await page.evaluate(() => {
                const c = document.querySelector('.agenda-indirizzo');
                return c === null ? null : c.textContent.trim();
            });

            check('creando l\'indirizzo, la pagina lo mostra', indirizzo !== null);

            if (indirizzo !== null) {
                const prima = await page.evaluate(() =>
                    document.querySelector('.agenda-dati').textContent.replace(/\s+/g, ' '));

                check(
                    'appena creato, risulta non ancora letto da nessuno',
                    prima.includes('mai'),
                    [prima]
                );

                const lettura = await ap.request.get(indirizzo);
                check('e il calendario si legge senza accesso', lettura.status() === 200,
                    lettura.status() === 200 ? [] : ['ha risposto ' + lettura.status()]);

                await page.goto(BASE + '/agenda');
                const dopo = await page.evaluate(() =>
                    document.querySelector('.agenda-dati').textContent.replace(/\s+/g, ' '));

                check(
                    'dopo una lettura, la data dell ultima lettura c e',
                    !dopo.includes('mai') && /\d{2}\/\d{2}\/\d{4}/.test(dopo),
                    [dopo]
                );

                // Rigenerare e' una revoca: il link vecchio deve smettere
                // di funzionare **subito**, non alla prossima lettura.
                const vecchio = indirizzo;
                await Promise.all([
                    page.waitForNavigation(),
                    page.click('.agenda-calendario form[action="/agenda/calendario"] button'),
                ]);
                const morto = await ap.request.get(vecchio);

                check(
                    'rigenerando, il vecchio indirizzo non apre piu niente',
                    morto.status() === 404,
                    morto.status() === 404 ? [] : ['ha risposto ' + morto.status()]
                );
            }

            await anonimo.close();
            await ctx.close();
        }

        // --- l'ingresso in riunione (17/10) -------------------------------
        //
        // /live/{id}/join rimanda al link Meet: la pagina dell'incontro si
        // prova nell'elenco qui sopra, l'ingresso qui, senza seguire il
        // rimando (Meet da qui non si raggiunge). Un rimando e' un ingresso
        // consentito, un 403 un rifiuto.

        console.log('\n--- l\'ingresso in riunione');

        for (const [email, chi, id, atteso, perche] of [
            ['tutor1@test.it', 'tutor del mondo A', A.incontro, 'consentito', 'incontro del suo gruppo'],
            ['tutor1@test.it', 'tutor del mondo A', B.incontro, 'negato', 'incontro del gruppo di un collega: il link Meet non gli si consegna'],
            ['assist@test.it', 'assistente del tutor A', B.incontro, 'negato', 'incontro di un tutor che non segue'],
            ['admin@test.it', 'amministratore', B.incontro, 'consentito', 'l\'admin entra ovunque'],
        ]) {
            const { ctx, page } = await entra(browser, email);
            await page.goto(BASE + '/profilo');
            const stato = await page.evaluate(async (u) => {
                const res = await fetch(u, { credentials: 'same-origin', redirect: 'manual' });
                return res.type === 'opaqueredirect' ? 'rimando' : String(res.status);
            }, '/live/' + id + '/join');
            const esito = stato === 'rimando' ? 'consentito' : 'negato';
            check(chi + ' → /live/' + id + '/join: ' + atteso, esito === atteso, ['ottenuto ' + stato + ' — ' + perche]);
            await ctx.close();
        }

        // Nella pagina della lezione, il riquadro «Incontri dal vivo di questo
        // modulo» mostra solo gli incontri che chi guarda puo' aprire (17/10):
        // il tutor A legge la lezione del corso B in sola lettura, ma
        // l'incontro di quel modulo non e' suo. Si usano le lezioni dei moduli
        // chiusi, che hanno un incontro legato al modulo (lo staff non e'
        // soggetto al rilascio).
        {
            const { ctx, page } = await entra(browser, 'tutor1@test.it');
            const riquadro = async (lezione) => {
                await page.goto(BASE + '/lessons/' + lezione);
                return (await page.content()).includes('Incontri dal vivo di questo modulo');
            };
            const nelB = await riquadro(B.lezione_chiusa);
            const nelA = await riquadro(A.lezione_chiusa);
            check('tutor del mondo A → nella lezione del corso B non compare l\'incontro di quel modulo', !nelB,
                nelB ? ['il riquadro degli incontri c\'è, e «Entra» porterebbe a un 403'] : []);
            check('controprova: nella lezione del suo corso l\'incontro compare', nelA,
                nelA ? [] : ['se mancasse anche qui, la prova sopra non direbbe niente']);
            await ctx.close();
        }

        // --- la foto del tutor (09/10) -----------------------------------
        //
        // Un tutor ha una foto sola, quella del profilo, e ne ha sempre una
        // (decisione di Alessandro, `AvatarImage::obbligatoria()`). Il
        // benvenuto in cima al corso mostra quella; dal pannello non si crea,
        // e non si fa diventare tutor, un utente senza foto, e la foto si
        // carica solo ai tutor; il tutor la sostituisce ma non la toglie (la
        // prova della POST sta fra le azioni).
        //
        // Le richieste al pannello sono moduli multiparte mandati da dentro
        // la pagina, senza passare dallo script che nasconde il campo: e' il
        // giro di chi non ha JavaScript, e quello che il server deve reggere
        // da solo. L'esito si legge nel database, non nel messaggio.

        console.log('\n--- la foto del tutor: una sola, e sempre');

        {
            const { ctx, page } = await entra(browser, 'stud@test.it');
            await page.goto(BASE + '/courses/' + A.corso);
            const foto = await page.$$eval('img.tutor-benvenuto-foto, img.tutor-benvenuto-miniatura',
                (imgs) => imgs.map((i) => ({ src: i.getAttribute('src'), caricata: i.complete && i.naturalWidth > 0 })));
            const attesa = '/utenti/' + d.utenti.tutorA + '/immagine';
            check('nel benvenuto la foto è quella del profilo del tutor, la stessa della pagina del gruppo',
                foto.length > 0 && foto.every((f) => f.src === attesa && f.caricata), [JSON.stringify(foto)]);
            await ctx.close();
        }
        {
            const { ctx, page } = await entra(browser, 'tutor1@test.it');
            await page.goto(BASE + '/profilo');
            const rimuovi = await page.locator('form[action="/profilo/immagine/elimina"]').count();
            const sostituisci = await page.locator('form[action="/profilo/immagine"] input[type=file]').count();
            check('profilo del tutor: la foto si sostituisce, «Rimuovi immagine» non c\'è',
                rimuovi === 0 && sostituisci === 1, ['rimuovi: ' + rimuovi + ', sostituisci: ' + sostituisci]);
            await ctx.close();
        }
        {
            const radice = path.join(__dirname, '..');
            const png = execFileSync('php', ['-r',
                '$i=imagecreatetruecolor(120,120);imagefill($i,0,0,imagecolorallocate($i,90,120,90));imagepng($i);']).toString('base64');
            const leggi = (email) => JSON.parse(execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";'
                + '$s=App\\Core\\Database::connection()->prepare("SELECT id, role, avatar_path FROM users WHERE email = ?");'
                + '$s->execute([$argv[1]]); echo json_encode($s->fetch() ?: null);', '--', email],
            { cwd: radice, encoding: 'utf8' }));

            const { ctx, page } = await entra(browser, 'admin@test.it');
            const invia = async (url, campi, conFoto) => {
                await page.goto(BASE + '/admin/users/create');
                await page.evaluate(async ([url, campi, png]) => {
                    const dati = new FormData();
                    dati.append('_token', document.querySelector('input[name="_token"]').value);
                    for (const [k, v] of campi) dati.append(k, v);
                    if (png !== null) {
                        const byte = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
                        dati.append('avatar', new Blob([byte], { type: 'image/png' }), 'foto.png');
                    }
                    await fetch(url, { method: 'POST', body: dati, credentials: 'same-origin' });
                }, [url, campi, conFoto ? png : null]);
            };
            // Un indirizzo per caso: un utente creato per sbaglio in un caso
            // non deve cambiare l'esito di quello dopo.
            const giro = Date.now();
            const persona = (caso, ruolo) => [['first_name', 'Foto'], ['last_name', 'Di Prova'],
                ['email', 'foto-' + caso + '-' + giro + '@test.it'], ['role', ruolo], ['is_active', '1']];
            const chi = (caso) => leggi('foto-' + caso + '-' + giro + '@test.it');
            const creati = [];

            await invia('/admin/users', persona('a', 'tutor'), false);
            creati.push(chi('a'));
            check('pannello: un tutor senza foto non si crea', chi('a') === null);

            await invia('/admin/users', persona('b', 'studente'), true);
            creati.push(chi('b'));
            check('pannello: la foto si carica solo ai tutor, non a uno studente', chi('b') === null);

            await invia('/admin/users', persona('c', 'studente'), false);
            const studente = chi('c');
            creati.push(studente);
            check('pannello: uno studente senza foto si crea (la controprova)',
                studente !== null && studente.role === 'studente' && studente.avatar_path === null);

            if (studente !== null) {
                await invia('/admin/users/' + studente.id, persona('c', 'tutor'), false);
                const dopo = chi('c');
                check('pannello: uno studente senza foto non diventa tutor', dopo !== null && dopo.role === 'studente',
                    [JSON.stringify(dopo)]);

                await invia('/admin/users/' + studente.id, persona('c', 'tutor'), true);
                const tutor = chi('c');
                check('pannello: con la foto nello stesso invio diventa tutor, e la foto è la sua',
                    tutor !== null && tutor.role === 'tutor' && String(tutor.avatar_path).startsWith('avatars/' + studente.id + '/'),
                    [JSON.stringify(tutor)]);

                await page.goto(BASE + '/admin/users/' + studente.id + '/edit');
                const campo = await page.locator('[data-foto-field]').isVisible();
                await page.selectOption('#role', 'studente');
                const nascosto = !(await page.locator('[data-foto-field]').isVisible());
                check('modifica utente: il campo della foto si vede per un tutor, e sparisce scegliendo un altro ruolo',
                    campo && nascosto, ['visibile da tutor: ' + campo + ', nascosto da studente: ' + nascosto]);
            }

            // Pulizia: via chi e' stato creato, anche per sbaglio.
            for (const u of creati.filter((x) => x !== null)) {
                await esitoPost(page, '/admin/users/' + u.id + '/delete', [], '/admin/users/' + u.id + '/edit');
            }
            check('pulizia: gli utenti di prova si tolgono', ['a', 'b', 'c'].every((caso) => chi(caso) === null));

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
            // Il benvenuto lo carica il tutor, il proprio; l'admin quello di
            // tutti (07/10). Il tutor di A non tocca quello del tutor di B, lo
            // studente nessuno. L'ultima prova toglie davvero il benvenuto del
            // tutor di A: e' l'ultima apposta, e la semina del giro dopo lo
            // rimette.
            ['stud@test.it', 'studente A (benvenuto)', [
                ['/admin/courses/' + A.corso + '/benvenuti/' + d.utenti.tutorA + '/elimina', [], 'negato',
                    'togliere il benvenuto del proprio tutor'],
            ]],
            // Le domande (07/10). Lo studente di B non chiede nel corso di A,
            // il tutor di B non pubblica ne' scarta la domanda assegnata al
            // tutor di A, lo studente non pubblica. Poi le due cose che
            // riescono: il tutor di A pubblica la sua, lo studente di A
            // chiede (e la notifica al tutor si controlla dopo).
            ['strano@test.it', 'studente B (domande)', [
                ['/courses/' + A.corso + '/domande', [['question', 'Domanda in un corso non mio'], ['module_id', '']], 'negato',
                    'fare una domanda in un corso a cui non è iscritto'],
            ]],
            ['tutor2@test.it', 'tutor B (domande)', [
                ['/domande/' + A.domanda_in_attesa + '/pubblica', [['question', 'x'], ['answer', 'y'], ['module_id', '']], 'negato',
                    'pubblicare la domanda di uno studente di un collega'],
                ['/domande/' + A.domanda_in_attesa + '/scarta', [], 'negato', 'scartarla'],
                // Le pubblicate (08/10): correggerle e toglierle dall'archivio
                // segue la stessa regola.
                ['/domande/' + A.domanda_pubblicata + '/modifica', [['question', 'x'], ['answer', 'y'], ['module_id', '']], 'negato',
                    'correggere una pubblicata di un collega'],
                ['/domande/' + A.domanda_pubblicata + '/togli', [], 'negato', 'toglierla dall\'archivio'],
            ]],
            ['stud@test.it', 'studente A (domande)', [
                ['/domande/' + A.domanda_in_attesa + '/scarta', [], 'negato', 'scartare una domanda: lo fa il tutor'],
                ['/domande/' + A.domanda_pubblicata + '/togli', [], 'negato', 'togliere una domanda dall\'archivio'],
                ['/courses/' + A.corso + '/domande', [['question', 'Domanda di permessi.js sul corso?'], ['module_id', '']], 'consentito',
                    'fare una domanda nel proprio corso'],
            ]],
            // Dal 09/10 risponde l'esperto, cioe' l'admin: il tutor del gruppo
            // non pubblica ne' corregge piu' le domande dei suoi studenti; lo
            // fa l'admin.
            ['tutor1@test.it', 'tutor A (domande)', [
                ['/domande/' + A.domanda_in_attesa + '/pubblica',
                    [['question', 'x'], ['answer', 'y'], ['module_id', '']], 'negato',
                    'pubblicare la domanda di un suo studente: risponde l\'esperto'],
                ['/domande/' + A.domanda_pubblicata + '/togli', [], 'negato', 'togliere una pubblicata dall\'archivio'],
            ]],
            ['admin@test.it', 'admin, l\'esperto (domande)', [
                ['/domande/' + A.domanda_in_attesa + '/pubblica',
                    [['question', 'Domanda in attesa del mondo A?'], ['answer', 'Risposta di permessi.js.'], ['module_id', '']], 'consentito',
                    'pubblicare la domanda di uno studente'],
                ['/domande/' + A.domanda_pubblicata + '/modifica',
                    [['question', 'Domanda pubblicata sul modulo del mondo A?'], ['answer', 'Risposta corretta da permessi.js.'], ['module_id', '']],
                    'consentito', 'correggere una pubblicata'],
                ['/domande/' + A.domanda_pubblicata + '/togli', [], 'consentito', 'toglierla dall\'archivio'],
            ]],
            // La pagina del gruppo (12/10): la domanda al gruppo la pongono
            // il tutor del gruppo e l'admin, che moderano anche le risposte;
            // rispondono e dicono «Ci sarò» i partecipanti. Nessuno scrive
            // nel gruppo di un altro.
            ['strano@test.it', 'studente B (gruppo)', [
                ['/gruppi/' + A.gruppo + '/risposta', [['question_id', '0'], ['answer', 'x']], 'negato',
                    'rispondere nel gruppo di un altro'],
                ['/gruppi/' + A.gruppo + '/ci-saro', [['session_id', String(A.incontro_imminente)], ['ci', '1']], 'negato',
                    'dire «Ci sarò» a un incontro di un altro gruppo'],
                ['/gruppi/risposte/' + d.risposta_nascosta, [['azione', 'rimetti']], 'negato',
                    'rimettere una risposta in un gruppo non suo'],
            ]],
            ['tutor2@test.it', 'tutor B (gruppo)', [
                ['/gruppi/' + d.gruppo_cerchio + '/domanda', [['question', 'Domanda in un gruppo non mio?']], 'negato',
                    'porre una domanda al gruppo di un collega'],
                ['/gruppi/risposte/' + d.risposta_nascosta, [['azione', 'rimetti']], 'negato',
                    'moderare le risposte del gruppo di un collega'],
            ]],
            ['stud@test.it', 'studente A (gruppo)', [
                ['/gruppi/' + d.gruppo_cerchio + '/domanda', [['question', 'Domanda di uno studente?']], 'negato',
                    'porre la domanda al gruppo: la pone il tutor'],
                ['/gruppi/risposte/' + d.risposta_nascosta, [['azione', 'rimetti']], 'negato',
                    'rimettere la risposta nascosta di un compagno'],
                ['/gruppi/' + d.gruppo_cerchio + '/risposta', [['question_id', String(d.domanda_aperta)], ['answer', 'Risposta di permessi.js.']],
                    'consentito', 'rispondere nel proprio gruppo'],
                ['/gruppi/' + A.gruppo + '/ci-saro', [['session_id', String(A.incontro_imminente)], ['ci', '1']], 'consentito',
                    'dire «Ci sarò» al prossimo incontro del proprio gruppo'],
            ]],
            ['tutor1@test.it', 'tutor A (gruppo)', [
                ['/gruppi/risposte/' + d.risposta_nascosta, [['azione', 'nascondi']], 'consentito',
                    'moderare le risposte del proprio gruppo'],
            ]],
            // Come compaiono agli altri studenti: e' una scelta solo degli
            // studenti (07/10). Il tutor compare sempre per intero.
            ['tutor2@test.it', 'tutor B (come ti vedono)', [
                ['/profilo/come-ti-vedono', [['name_display', 'initials']], 'negato',
                    'il tutor non sceglie come compare: compare sempre per intero'],
            ]],
            // La foto del tutor (09/10): la sostituisce, non la toglie.
            ['tutor1@test.it', 'tutor A (foto)', [
                ['/profilo/immagine/elimina', [], 'negato', 'togliere la propria foto: un tutor ce l\'ha sempre'],
            ]],
            ['tutor1@test.it', 'tutor A (benvenuto)', [
                ['/admin/courses/' + B.corso + '/benvenuti/' + d.utenti.tutorB + '/elimina', [], 'negato',
                    'togliere il benvenuto di un collega'],
                ['/admin/courses/' + A.corso + '/benvenuti/' + d.utenti.tutorA + '/elimina', [], 'consentito',
                    'togliere il proprio benvenuto: lo carica lui'],
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
        // La notifica al tutor (07/10, Elena: da subito). L'azione qui sopra
        // ha fatto una domanda come studente di A: nella posta del contenitore
        // (MAIL_TRANSPORT=log, un file per messaggio) ci dev'essere l'email
        // all'esperto, l'admin, con il testo della domanda; e dal 09/10 non al
        // tutor. Si guardano solo i messaggi di questo giro: nella cartella
        // restano quelli dei giri prima.
        {
            const fs = require('fs');
            const cartella = path.join(__dirname, '..', 'storage', 'mail');
            const diQuestoGiro = (chi) => (fs.existsSync(cartella) ? fs.readdirSync(cartella) : [])
                .filter((f) => f.includes(chi) && fs.statSync(path.join(cartella, f)).mtimeMs >= INIZIO_GIRO)
                .some((f) => {
                    const testo = fs.readFileSync(path.join(cartella, f), 'utf8');
                    return testo.includes('Subject: Nuova domanda') && testo.includes('Domanda di permessi.js sul corso?');
                });
            check('la domanda nuova arriva per email all\'esperto, l\'admin', diQuestoGiro('admin'),
                ['nessuna email «Nuova domanda» all\'admin in storage/mail']);
            check('e non al tutor del gruppo', !diQuestoGiro('tutor1'), ['il tutor l\'ha ricevuta']);
        }

        // --- gli a capo nei campi con il limite (10/10) --------------------
        //
        // Il campo conta un a capo come un carattere, il browser lo invia come
        // due (\r\n). Il taglio sul server deve contarlo uno, o un testo pieno
        // con degli a capo perde la fine, un carattere per a capo. Succedeva
        // nella presentazione del profilo e nelle risposte aperte: si scrive
        // un testo lungo esattamente il limite, con un a capo ogni cento
        // caratteri e «FINE.» in fondo, e si controlla che si salvi intero.
        // Le risposte aperte si guardano nel database, dopo un tentativo vero:
        // per questo la prova sta in fondo, e alla domanda aperta la aggiunge
        // qui al questionario del mondo A, che la semina ricrea a ogni giro.

        console.log('\n--- gli a capo nei campi con il limite');

        {
            const radice = path.join(__dirname, '..');
            const pieno = (massimo) => ('a'.repeat(99) + '\n').repeat(Math.floor(massimo / 100) - 1) + 'a'.repeat(95) + 'FINE.';
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php"; $db = App\\Core\\Database::connection();' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });

            const { ctx, page } = await entra(browser, 'stud@test.it');

            await page.goto(BASE + '/profilo');
            const prima = await page.$eval('#bio', (t) => t.value);
            const bio = pieno(1000);
            await page.fill('#bio', bio);
            await Promise.all([page.waitForNavigation(), page.click('form[action="/profilo"] button[type=submit]')]);
            const salvata = await page.$eval('#bio', (t) => t.value);
            check('presentazione: 1.000 caratteri con 9 a capo si salvano interi',
                salvata.length === 1000 && salvata.endsWith('FINE.'),
                ['nel campo ' + bio.length + ', salvati ' + salvata.length + ', finisce con «' + salvata.slice(-5) + '»']);
            // La presentazione di prima torna al suo posto: la pagina del
            // gruppo, piu' sotto nei giri dopo, la cerca.
            await page.fill('#bio', prima);
            await Promise.all([page.waitForNavigation(), page.click('form[action="/profilo"] button[type=submit]')]);

            php('$db->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type, position) VALUES (?, ?, \'open\', 9)")'
                + '->execute([$argv[1], "Domanda aperta di permessi.js"]);', A.quiz);
            await page.goto(BASE + '/quizzes/' + A.quiz);
            const risposta = pieno(3000);
            await page.$$eval('.quiz-form input[type=radio]', (r) => {
                const visti = new Set();
                r.forEach((x) => { if (!visti.has(x.name)) { visti.add(x.name); x.checked = true; } });
            });
            await page.fill('.quiz-form textarea.quiz-open-answer', risposta);
            await Promise.all([page.waitForNavigation(), page.click('.quiz-form button[type=submit]')]);
            const scritta = php('$s = $db->prepare("SELECT a.answer_text FROM quiz_attempt_answers a JOIN quiz_attempts t ON t.id = a.attempt_id'
                + ' WHERE t.quiz_id = ? AND a.answer_text IS NOT NULL ORDER BY a.id DESC LIMIT 1"); $s->execute([$argv[1]]);'
                + ' echo (string) $s->fetchColumn();', A.quiz);
            check('risposta aperta: 3.000 caratteri con 29 a capo si salvano interi',
                [...scritta].length === 3000 && scritta.endsWith('FINE.'),
                ['nel campo ' + risposta.length + ', salvati ' + [...scritta].length + ', finisce con «' + scritta.slice(-5) + '»']);

            await ctx.close();

            // L'obiettivo del corso e del modulo e la descrizione del corso
            // (11/10): qualche tag HTML, e il limite conta solo il testo
            // visibile. Per ogni campo: un testo pieno con gli a capo e un
            // grassetto si salva intero, anche se scritto e' piu' lungo del
            // limite; oltre il limite il server rifiuta, dice di quanto e
            // ripropone il testo, e quello salvato prima resta. Il corso e il
            // modulo sono quelli del mondo A, che la semina ricrea a ogni giro.
            const admin = await entra(browser, 'admin@test.it');
            const migliaia = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            const conTag = (massimo) => pieno(massimo).replace(/a{95}FINE\.$/, '<strong>' + 'a'.repeat(95) + 'FINE.</strong>');
            for (const [nome, campo, massimo, indirizzo, modulo] of [
                ['obiettivo del corso', '#objective', 1000, '/admin/courses/' + A.corso + '/edit', 'form[action="/admin/courses/' + A.corso + '"]'],
                ['descrizione del corso', '#description', 3000, '/admin/courses/' + A.corso + '/edit', 'form[action="/admin/courses/' + A.corso + '"]'],
                ['obiettivo del modulo', '#objective', 1000, '/modules/' + A.modulo + '/edit', 'form[action="/modules/' + A.modulo + '"]'],
            ]) {
                const invia = async (testo) => {
                    await admin.page.goto(BASE + indirizzo);
                    await admin.page.fill(campo, testo);
                    await Promise.all([admin.page.waitForNavigation(), admin.page.click(modulo + ' button[type=submit]')]);
                };
                const salvato = async () => {
                    await admin.page.goto(BASE + indirizzo);
                    return admin.page.$eval(campo, (t) => t.value);
                };

                const testo = conTag(massimo);
                await invia(testo);
                const intero = await salvato();
                check(nome + ': ' + migliaia(massimo) + ' caratteri visibili con gli a capo e un grassetto si salvano interi',
                    intero === testo, ['scritti ' + testo.length + ' (con i tag), salvati ' + intero.length + ', finisce con «' + intero.slice(-14) + '»']);

                // 37 oltre: il rifiuto, la frase, il testo riproposto. Poi una
                // pagina nuova: il testo riproposto non resta, quello salvato si'.
                const troppo = 'c'.repeat(massimo + 37);
                await invia(troppo);
                const errore = await admin.page.$eval('.alert-error', (e) => e.textContent.trim()).catch(() => '');
                const riproposto = await admin.page.$eval(campo, (t) => t.value);
                const contatore = await admin.page.$eval(campo + '-resta', (c) => c.textContent.trim());
                check(nome + ': oltre il limite il server rifiuta e dice di quanto',
                    errore.includes('supera di 37 caratteri il limite di ' + migliaia(massimo)), ['messaggio: «' + errore + '»']);
                check(nome + ': e ripropone il testo scritto, con il contatore che dice quanti in più',
                    riproposto === troppo && contatore === '37 caratteri in più',
                    ['nel campo ' + riproposto.length + ' caratteri, contatore «' + contatore + '»']);
                const dopo = await salvato();
                check(nome + ': il testo salvato prima resta', dopo === testo, ['salvati ' + dopo.length + ' caratteri']);
            }

            // Nella pagina del corso la descrizione si vede con i suoi tag,
            // ripulita: il grassetto c'e', lo script e i gestori di eventi no,
            // e una riga vuota apre un paragrafo.
            await admin.page.goto(BASE + '/admin/courses/' + A.corso + '/edit');
            await admin.page.fill('#description', 'Primo <strong onclick="window.__preso = 1">forte</strong>\n\n'
                + 'Secondo<script>window.__preso = 2</script>\n<ul>\n<li>uno</li>\n</ul>');
            await Promise.all([admin.page.waitForNavigation(),
                admin.page.click('form[action="/admin/courses/' + A.corso + '"] button[type=submit]')]);
            await admin.page.goto(BASE + '/courses/' + A.corso);
            const vista = await admin.page.evaluate(() => {
                const d = document.querySelector('.course-description');
                return d === null ? null : {
                    html: d.innerHTML,
                    paragrafi: d.querySelectorAll(':scope > p').length,
                    elenco: d.querySelectorAll(':scope > ul > li').length,
                    preso: window.__preso === undefined ? null : window.__preso,
                };
            });
            check('descrizione nella pagina del corso: grassetto, due paragrafi e un elenco',
                vista !== null && vista.html.includes('<strong>forte</strong>') && vista.paragrafi === 2 && vista.elenco === 1,
                [JSON.stringify(vista)]);
            check('descrizione nella pagina del corso: niente script, niente gestori di eventi',
                vista !== null && vista.preso === null && !vista.html.includes('onclick') && !vista.html.includes('script'),
                [JSON.stringify(vista)]);
            await admin.ctx.close();
        }

        console.log('\n--- un account cambiato mentre è collegato (13/10)');

        {
            // Lo stato dell'account si legge a ogni richiesta, non solo
            // all'accesso: un tutor passato a studente perde le pagine da
            // tutor alla richiesta dopo, e chi viene disattivato esce.
            const radice = path.join(__dirname, '..');
            const sql = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php"; $db = App\\Core\\Database::connection();' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const imposta = (ruolo, attivo) => sql(
                '$db->prepare("UPDATE users SET role = ?, is_active = ? WHERE id = ?")->execute([$argv[1], $argv[2], $argv[3]]);',
                ruolo, attivo, d.utenti.tutorB);

            const { ctx, page } = await entra(browser, 'tutor2@test.it');

            try {
                let r = await esitoGet(page, '/reports');
                check('tutor B collegato → /reports: consentito', r.esito === 'consentito', [r.come]);

                // Da studente il primo accesso lo porta alla sua pagina (il
                // tutor non ha scelto come lo vedono gli altri): conta che
                // non arrivi ai report, in un modo o nell'altro.
                imposta('studente', 1);
                const risposta = await page.goto(BASE + '/reports');
                const dove = new URL(page.url()).pathname;
                check('tutor B passato a studente, senza uscire → /reports: negato',
                    dove !== '/reports' || risposta.status() >= 400, [risposta.status() + ' ' + dove]);

                imposta('tutor', 1);
                r = await esitoGet(page, '/reports');
                check('e tornato tutor → /reports: di nuovo consentito', r.esito === 'consentito', [r.come]);

                imposta('tutor', 0);
                r = await esitoGet(page, '/profilo');
                const avviso = await page.evaluate(() => document.body.innerText.includes('stato disattivato'));
                check('tutor B disattivato, senza uscire → /profilo: torna all\'accesso, con il motivo',
                    r.esito === 'negato' && page.url().includes('motivo=disattivato') && avviso, [r.come, page.url()]);

                imposta('tutor', 1);
                r = await esitoGet(page, '/profilo');
                check('e riattivato: la sessione chiusa resta chiusa', r.esito === 'negato', [r.come]);
            } finally {
                imposta('tutor', 1);
                await ctx.close();
            }
        }

        console.log('\n--- il video caricato sul server (13/10)');

        {
            // Un file `.mp4` che dentro e' una pagina HTML: il tipo si decide
            // dall'estensione, e il browser non deve dedurlo dal contenuto.
            const radice = path.join(__dirname, '..');
            const sql = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php"; $db = App\\Core\\Database::connection();' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const cartella = path.join(radice, 'storage', 'videos', 'prova-permessi');
            const fs = require('node:fs');
            fs.mkdirSync(cartella, { recursive: true });
            fs.writeFileSync(path.join(cartella, 'finto.mp4'), '<html><body><script>document.title = "preso"</script></body></html>');
            const video = (lezione, ref) => sql(
                '$db->prepare("UPDATE lessons SET video_provider = ?, video_ref = ? WHERE id = ?")->execute([$argv[1], $argv[2] === "" ? null : $argv[2], $argv[3]]);',
                ref === '' ? 'none' : 'self_hosted', ref, lezione);

            video(A.lezione, 'videos/prova-permessi/finto.mp4');
            video(A.lezione_chiusa, 'videos/prova-permessi/finto.mp4');
            const { ctx, page } = await entra(browser, 'stud@test.it');

            try {
                await page.goto(BASE + '/profilo');
                const chiedi = (u) => page.evaluate(async (u) => {
                    const res = await fetch(u, { credentials: 'same-origin' });
                    return { stato: res.status, tipo: res.headers.get('content-type'), nosniff: res.headers.get('x-content-type-options') };
                }, u);

                const aperto = await chiedi('/lessons/' + A.lezione + '/video');
                check('video con dentro una pagina HTML: esce come video/mp4, con nosniff',
                    aperto.stato === 200 && (aperto.tipo || '').startsWith('video/mp4') && aperto.nosniff === 'nosniff',
                    [JSON.stringify(aperto)]);

                const chiuso = await chiedi('/lessons/' + A.lezione_chiusa + '/video');
                check('studente A → video di una lezione di un modulo chiuso: negato', chiuso.stato === 403, [JSON.stringify(chiuso)]);
            } finally {
                video(A.lezione, '');
                video(A.lezione_chiusa, '');
                fs.rmSync(cartella, { recursive: true, force: true });
                await ctx.close();
            }
        }

        console.log('\n--- l\'accesso protetto (13/10)');

        {
            const radice = path.join(__dirname, '..');
            const sql = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php"; $db = App\\Core\\Database::connection();' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const EMAIL = 'strano@test.it';
            const azzera = () => sql('$db->prepare("DELETE FROM login_failures WHERE email = ?")->execute([$argv[1]]);', EMAIL);

            // Il cookie di sessione: non si legge da JavaScript e non parte
            // con le richieste nascoste di un altro sito. Secure qui non si
            // prova: il server di sviluppo e' in http (lo prova accesso_test.php).
            const { ctx: c1 } = await entra(browser, 'stud@test.it');
            const cookie = (await c1.cookies()).find((c) => c.name === 'PHPSESSID');
            check('cookie di sessione HttpOnly e SameSite=Lax',
                cookie !== undefined && cookie.httpOnly === true && cookie.sameSite === 'Lax', [JSON.stringify(cookie)]);
            await c1.close();

            // Cinque password sbagliate bloccano l'account per un'ora (13/10,
            // Alessandro): dalla terza l'avviso a schermo dice quanti
            // tentativi restano, alla quinta che l'account e' bloccato, e
            // durante il blocco nemmeno la password giusta apre. La
            // reimpostazione della password lo sblocca.
            azzera();
            const ctx = await browser.newContext();
            const page = await ctx.newPage();
            const avviso = async () => page.evaluate(() => {
                const a = document.querySelector('.alert-error');
                return a === null ? '' : a.innerText;
            });
            const prova = async (password) => {
                await page.goto(BASE + '/login');
                await page.fill('input[name="email"]', EMAIL);
                await page.fill('input[name="password"]', password);
                await Promise.all([page.waitForNavigation(), page.click('form.auth-form button[type="submit"]')]);
                return avviso();
            };

            try {
                const avvisi = [];
                for (let i = 0; i < 5; i++) {
                    avvisi.push(await prova('sbagliata-' + i));
                }

                check('prima e seconda password sbagliata: l\'avviso di sempre',
                    avvisi[0] === 'Email o password non corretti.' && avvisi[1] === avvisi[0], avvisi.slice(0, 2));
                check('terza e quarta: l\'avviso dice quanti tentativi restano',
                    avvisi[2].includes('Ancora 2 tentativi') && avvisi[3].includes('Ancora 1 tentativo,'), avvisi.slice(2, 4));
                check('quinta: a schermo, l\'account è bloccato per un\'ora',
                    avvisi[4].includes('l’account è bloccato per un’ora') && avvisi[4].includes('Password dimenticata?'), [avvisi[4]]);

                const conGiusta = await prova(PASSWORD);
                check('durante il blocco nemmeno la password giusta apre, e l\'avviso resta',
                    page.url().includes('/login') && conGiusta.includes('bloccato per un’ora'), [page.url(), conGiusta]);

                // La via d'uscita indicata dall'avviso: il link per
                // reimpostare la password. Si reimposta la stessa, cosi' gli
                // altri giri la trovano com'era.
                await ctx.clearCookies();
                const token = sql('echo App\\Models\\UserTokenModel::issue((int) $argv[1], App\\Models\\UserTokenModel::PURPOSE_RESET, 3600);',
                    d.utenti.studenteB);
                await page.goto(BASE + '/password/reimposta/' + token);
                await page.fill('#password', PASSWORD);
                await page.fill('#password_confirm', PASSWORD);
                await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
                await prova(PASSWORD);
                check('reimpostata la password, l\'account è sbloccato', !page.url().includes('/login'), [page.url()]);
            } finally {
                azzera();
                await ctx.close();
            }
        }

        console.log('\n--- cornici e file scaricati (13/10)');

        {
            const radice = path.join(__dirname, '..');
            const sql = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php"; $db = App\\Core\\Database::connection();' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });

            // Nessun altro sito incornicia la piattaforma: sulla pagina
            // d'accesso, che non chiede di essere dentro, e su una pagina
            // dell'admin, quella che un clic rubato farebbe piu' danni.
            const { ctx, page } = await entra(browser, 'admin@test.it');
            const intestazioni = async (url) => {
                const r = await page.goto(BASE + url);
                const h = r.headers();
                return { xfo: h['x-frame-options'] || null, csp: h['content-security-policy'] || null };
            };

            for (const url of ['/login', '/admin/users']) {
                const h = await intestazioni(url);
                check(url + ': nessun altro sito la incornicia',
                    h.xfo === 'SAMEORIGIN' && h.csp !== null && h.csp.includes("frame-ancestors 'self'"), [JSON.stringify(h)]);
            }

            // Un nome che Excel eseguirebbe come formula esce, nel CSV degli
            // utenti, con l'apostrofo davanti.
            const formula = '=HYPERLINK("http://esempio.invalid";"clic")';
            // Il nome intero si compone da nome e cognome: si cambia il nome.
            const prima = sql('$s = $db->prepare("SELECT first_name FROM users WHERE id = ?"); $s->execute([$argv[1]]); echo $s->fetchColumn();',
                d.utenti.studenteB);
            const nome = (n) => sql('$db->prepare("UPDATE users SET first_name = ? WHERE id = ?")->execute([$argv[1], $argv[2]]);',
                n, d.utenti.studenteB);

            try {
                nome(formula);
                await page.goto(BASE + '/admin/users');
                const csv = await page.evaluate(async () => (await (await fetch('/admin/users/csv', { credentials: 'same-origin' })).text()));
                const riga = csv.split('\n').find((r) => r.includes('HYPERLINK')) || '';
                check('CSV degli utenti: un nome-formula esce con l\'apostrofo davanti',
                    riga.includes(';"\'=HYPERLINK(') || riga.includes(';\'=HYPERLINK('), [riga]);
            } finally {
                nome(prima);
                await ctx.close();
            }
        }

        console.log('\n--- «Vai al tuo gruppo» nella pagina del corso (14/10)');

        {
            // Lo studente vede il collegamento al suo gruppo, quello del corso,
            // e il collegamento si apre; lo staff non lo vede.
            const vai = async (page, corso) => {
                await page.goto(BASE + '/courses/' + corso);
                return page.evaluate(() => [...document.querySelectorAll('.page-header a')]
                    .filter((a) => a.textContent.trim() === 'Vai al tuo gruppo')
                    .map((a) => a.getAttribute('href')));
            };

            for (const [email, chi, corso, gruppo] of [
                ['stud@test.it', 'studente A', A.corso, A.gruppo],
                ['strano@test.it', 'studente B', B.corso, B.gruppo],
            ]) {
                const { ctx, page } = await entra(browser, email);
                const link = await vai(page, corso);
                check(chi + ' → pagina del suo corso: «Vai al tuo gruppo» porta al suo gruppo',
                    link.length === 1 && link[0] === '/gruppi/' + gruppo, [JSON.stringify(link)]);
                const r = await esitoGet(page, link[0] || '/gruppi/0');
                check(chi + ' → il collegamento si apre', r.esito === 'consentito', [r.come]);
                await ctx.close();
            }

            const { ctx, page } = await entra(browser, 'admin@test.it');
            const allo = await vai(page, A.corso);
            check('admin → pagina del corso A: nessun «Vai al tuo gruppo»', allo.length === 0, [JSON.stringify(allo)]);
            await ctx.close();
        }

        console.log('\n--- «Annulla» nella creazione di moduli e lezioni (14/10)');

        {
            // «Annulla» accanto a «Crea»: riporta al corso senza salvare
            // niente. Si scrive un titolo, si preme Annulla, e si guarda che
            // nessun modulo o lezione con quel titolo esista.
            const radice = path.join(__dirname, '..');
            const conta = (tabella, titolo) => Number(execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";'
                + '$s = App\\Core\\Database::connection()->prepare("SELECT COUNT(*) FROM ' + tabella + ' WHERE title = ?");'
                + '$s->execute([$argv[1]]); echo $s->fetchColumn();', '--', titolo], { cwd: radice, encoding: 'utf8' }));
            const { ctx, page } = await entra(browser, 'admin@test.it');

            for (const [url, tabella, chi, attesa] of [
                ['/courses/' + A.corso + '/modules/create', 'modules', 'nuovo modulo', '/courses/' + A.corso],
                ['/modules/' + A.modulo + '/lessons/create', 'lessons', 'nuova lezione', '/courses/' + A.corso + '#modulo-' + A.modulo],
            ]) {
                const titolo = '[prova-annulla] ' + chi;
                await page.goto(BASE + url);
                await page.fill('#title', titolo);
                const annulla = page.locator('.form-actions a.btn', { hasText: 'Annulla' });

                if (await annulla.count() !== 1) {
                    check(chi + ': «Annulla» riporta al corso e non salva niente', false, ['«Annulla» non c\'è']);
                    continue;
                }

                const href = await annulla.getAttribute('href');
                await Promise.all([page.waitForNavigation(), annulla.click()]);
                check(chi + ': «Annulla» riporta al corso e non salva niente',
                    href === attesa && new URL(page.url()).pathname === '/courses/' + A.corso && conta(tabella, titolo) === 0,
                    [href, page.url()]);
            }

            // E nella modifica (14/10, «ovunque si salva»): riporta al corso.
            for (const [url, attesa] of [
                ['/modules/' + A.modulo + '/edit', '/courses/' + A.corso],
                ['/lessons/' + A.lezione + '/edit', '/courses/' + A.corso + '#modulo-' + A.modulo],
            ]) {
                await page.goto(BASE + url);
                const href = await page.locator('form.stacked-form > .form-actions a.btn', { hasText: 'Annulla' }).evaluateAll((l) => l.map((a) => a.getAttribute('href')));
                check(url + ': anche nella modifica «Annulla» riporta al corso', href.length === 1 && href[0] === attesa, [JSON.stringify(href)]);
            }

            await ctx.close();
        }

        console.log('\n--- «Annulla» che rimette i valori salvati (14/10)');

        {
            // Nelle pagine con più moduli «Annulla» rimette i valori salvati e
            // si resta lì; nei pannelli li rimette e chiude il pannello. Il
            // contatore dei caratteri torna al numero di prima.
            const { ctx, page } = await entra(browser, 'admin@test.it');
            const prova = async (selettoreCampo) => page.evaluate(async (sel) => {
                const campo = document.querySelector(sel);
                const contatore = document.getElementById(campo.id + '-resta');
                const prima = { valore: campo.value, conto: contatore ? contatore.textContent : null };
                campo.value = prima.valore + ' testo in più che non si salva';
                campo.dispatchEvent(new Event('input', { bubbles: true }));
                const durante = contatore ? contatore.textContent : null;
                const annulla = [...campo.form.querySelectorAll('button[type="reset"]')].find((b) => b.textContent.trim() === 'Annulla');
                const pannello = campo.closest('details');
                if (annulla === undefined) return { manca: 'nessun «Annulla» nel modulo' };
                annulla.click();
                await new Promise((r) => setTimeout(r, 50));
                return {
                    rimesso: campo.value === prima.valore,
                    conto: contatore ? contatore.textContent === prima.conto && durante !== prima.conto : true,
                    chiuso: pannello === null ? null : !pannello.open,
                    url: location.pathname,
                };
            }, selettoreCampo);

            await page.goto(BASE + '/admin/settings/email');
            const email = await prova('#testo-verifica');
            check('Messaggi email: «Annulla» rimette il testo salvato e il contatore, e si resta nella pagina',
                email.rimesso && email.conto && email.url === '/admin/settings/email', [JSON.stringify(email)]);

            // La prima domanda pubblicata della pagina: quella seminata puo'
            // essere stata tolta dall'archivio da una prova piu' sopra.
            await page.goto(BASE + '/domande');
            const voce = await page.evaluate(() => {
                const li = document.querySelector('li[id^="pubblicata-"]');
                if (li === null) return null;
                li.querySelector('details').open = true;
                return li.id;
            });
            const domanda = voce === null ? { nessuna: true } : await prova('#' + voce + '-risposta');
            check('Domande, pubblicata: «Annulla» rimette la risposta e chiude il pannello',
                domanda.rimesso && domanda.conto && domanda.chiuso === true, [JSON.stringify(domanda)]);

            await ctx.close();

            // E accanto a «Invia la domanda» nella pagina del corso (14/10):
            // svuota il campo e chiude «Fai una domanda all'esperto».
            const stud = await entra(browser, 'stud@test.it');
            await stud.page.goto(BASE + '/courses/' + A.corso);
            await stud.page.evaluate(() => { const d = document.querySelector('#qa-domanda').closest('details'); if (d) d.open = true; });
            const studente = await stud.page.evaluate(async (sel) => {
                const campo = document.querySelector(sel);
                const prima = campo.value;
                campo.value = 'Una domanda che non mando';
                const annulla = [...campo.form.querySelectorAll('button[type="reset"]')].find((b) => b.textContent.trim() === 'Annulla');
                if (annulla === undefined) return { manca: 'nessun «Annulla»' };
                annulla.click();
                await new Promise((r) => setTimeout(r, 50));
                return { rimesso: campo.value === prima, chiuso: !campo.closest('details').open };
            }, '#qa-domanda');
            check('pagina del corso: «Annulla» accanto a «Invia la domanda» svuota il campo e chiude il riquadro',
                studente.rimesso === true && studente.chiuso === true, [JSON.stringify(studente)]);
            await stud.ctx.close();
        }

        console.log('\n--- chi non ha fatto l\'accesso');

        const ctx = await browser.newContext();
        const page = await ctx.newPage();

        for (const url of [
            '/',
            '/courses/' + A.corso,
            '/lessons/' + A.lezione,
            '/quizzes/' + A.quiz,
            '/reports',
            '/reports/elenco/students',
            '/agenda',
            '/admin/users',
            '/admin/permissions',
            '/profilo',
            '/benvenuti/' + A.benvenuto + '/audio',
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
