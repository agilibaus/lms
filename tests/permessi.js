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
    // Con la lezione del modulo fatta: il questionario si apre solo dopo (27/10).
    const uscita = execFileSync('php', [path.join(radice, 'tests', 'semina_permessi.php'), '--lezioni-fatte'], {
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
            ['/reports/fermi', 'negato', 'né l\'elenco degli studenti fermi'],
            ['/reports/fermi/csv', 'negato', 'né scaricandolo'],
            ['/reports/courses/' + A.corso + '/griglia', 'negato', 'né la griglia del suo corso'],
            ['/reports/courses/' + A.corso + '/griglia/csv', 'negato', 'né scaricandola'],
            ['/tre-parole?corso=' + A.corso, 'negato', 'le tre parole le leggono il tutor e l\'amministrazione'],
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
            // Chi e' atteso a un incontro (25/10): uno di tutto il corso e'
            // suo, anche quello passato, perche' era iscritto prima che finisse.
            ['/live/' + A.incontro_corso, 'consentito', 'incontro di tutto il suo corso'],
            ['/live/' + A.incontro_corso_passato, 'consentito', 'incontro di tutto il corso, finito quando era già iscritto'],
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

            // La Bacheca (17/10): quella del suo corso, non quella di B; propone
            // ma non pubblica, non valuta, non corregge. Il file c'e' sul disco.
            ['/bacheca?corso=' + A.corso, 'consentito', 'la bacheca del suo corso'],
            ['/bacheca/categorie/' + A.bacheca_categoria, 'consentito', 'una categoria della sua bacheca'],
            ['/bacheca/categorie/' + B.bacheca_categoria, 'negato', 'una categoria della bacheca di un corso non suo'],
            ['/bacheca/mie?corso=' + A.corso, 'consentito', 'le risorse che si tiene'],
            ['/bacheca/condividi?corso=' + A.corso, 'consentito', 'proporre una risorsa'],
            ['/bacheca/proposte?corso=' + A.corso, 'negato', 'le proposte le valuta il tutor'],
            ['/bacheca/nuova?corso=' + A.corso, 'negato', 'pubblica lo staff'],
            ['/bacheca/risorse/' + A.bacheca_risorsa + '/modifica', 'negato', 'corregge chi gestisce la risorsa'],
            ['/bacheca/risorse/' + A.bacheca_risorsa_studente + '/modifica', 'negato', 'nemmeno la propria, una volta pubblicata'],
            ['/bacheca/risorse/' + A.bacheca_risorsa + '/download', 'consentito', 'il file di una risorsa della sua bacheca'],
            ['/bacheca/risorse/' + B.bacheca_risorsa + '/download', 'negato', 'il file della bacheca di un altro corso'],
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
            // Dal 26/10 (conflitto 1) i contenuti del corso li modifica solo
            // l'admin, anche nel corso dei suoi gruppi.
            ['/lessons/' + A.lezione + '/edit', 'negato', 'le lezioni del corso dei suoi gruppi le modifica l\'admin'],
            ['/quizzes/' + A.quiz + '/edit', 'negato', 'il quiz del corso dei suoi gruppi lo modifica l\'admin'],
            ['/questions/' + A.domanda + '/edit', 'negato', 'le domande del quiz le modifica l\'admin'],
            ['/modules/' + A.modulo + '/edit', 'negato', 'il modulo del corso dei suoi gruppi lo modifica l\'admin'],
            ['/admin/courses/' + A.corso + '/edit', 'consentito', 'la pagina del corso dei suoi gruppi, con il suo benvenuto e i suoi studenti'],
            ['/reports/courses/' + A.corso, 'consentito', 'report del suo corso'],
            ['/reports/courses/' + A.corso + '/griglia', 'consentito', 'la griglia del suo corso'],
            ['/reports/courses/' + B.corso + '/griglia', 'negato', 'la griglia di un corso non suo'],
            ['/reports/courses/' + B.corso + '/griglia/csv', 'negato', 'nemmeno scaricandola'],
            ['/tre-parole?corso=' + A.corso, 'consentito', 'le tre parole del corso dei suoi gruppi'],
            ['/tre-parole?corso=' + B.corso, 'negato', 'non quelle di un corso non suo'],
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
            ['/admin/settings/informazioni', 'negato', 'nemmeno la versione installata (18/10)'],

            // Lo staff non è mai soggetto al rilascio: il tutor legge il
            // modulo chiuso prima che si apra (dal 26/10 non lo modifica).
            ['/lessons/' + A.lezione_chiusa, 'consentito',
                'il tutor legge il modulo chiuso prima che si apra'],
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
            // La Bacheca (17/10): pubblica nel corso dei suoi gruppi e valuta
            // le proposte dei suoi studenti; la bacheca di un collega no.
            ['/bacheca/categorie/' + A.bacheca_categoria, 'consentito', 'la bacheca del corso dei suoi gruppi'],
            ['/bacheca/categorie/' + B.bacheca_categoria, 'negato', 'la bacheca del corso di un collega'],
            ['/bacheca/proposte?corso=' + A.corso, 'consentito', 'le proposte dei suoi studenti'],
            ['/bacheca/nuova?corso=' + A.corso, 'consentito', 'pubblica nel corso dei suoi gruppi'],
            ['/bacheca/condividi?corso=' + A.corso, 'negato', 'propongono gli studenti: lui pubblica'],
            ['/bacheca/risorse/' + A.bacheca_proposta + '/modifica', 'consentito', 'corregge la proposta di un suo studente'],
            ['/bacheca/risorse/' + B.bacheca_proposta + '/modifica', 'negato', 'non quella di uno studente di un collega'],
            ['/bacheca/risorse/' + B.bacheca_risorsa + '/download', 'negato', 'il file della bacheca di un collega'],
        ]],

        ['assist@test.it', 'assistente del tutor A', [
            ['/reports', 'consentito', 'vede i report, ristretti al suo perimetro'],
            ['/reports/fermi', 'consentito', 'gli studenti fermi, ristretti al suo perimetro'],
            ['/reports/fermi/csv', 'consentito', 'e li scarica, con lo stesso perimetro'],
            ['/reports/elenco/students', 'consentito',
                'l\'elenco si apre: a restringerlo sono le righe, non la porta'],
            ['/reports/courses/' + A.corso, 'consentito', 'corso del tutor che segue'],
            ['/reports/courses/' + B.corso, 'negato', 'corso di un tutor che non segue'],
            ['/reports/courses/' + A.corso + '/griglia', 'consentito', 'la griglia del corso del tutor che segue'],
            ['/reports/courses/' + B.corso + '/griglia', 'negato', 'la griglia di un corso di un tutor che non segue'],
            ['/tre-parole?corso=' + A.corso, 'negato', 'le tre parole le leggono il tutor e l\'amministrazione, non l\'assistente'],
            ['/reports/students/' + d.utenti.studenteB, 'negato', 'studente fuori dal suo perimetro'],
            ['/attempts/' + A.tentativo, 'consentito', 'tentativo di uno studente del tutor che segue'],
            // Gli incontri (26/10, conflitto 6): l'assistente vede quelli dei
            // gruppi del suo tutor, in sola lettura.
            ['/live/' + B.incontro, 'negato', 'incontro di un tutor che non segue'],
            ['/live/' + A.incontro, 'consentito', 'incontro del gruppo del tutor che segue'],
            ['/live/' + A.incontro + '/edit', 'negato', 'ma non lo modifica'],
            ['/attempts/' + B.tentativo, 'negato', 'tentativo di uno studente fuori dal suo perimetro'],
            ['/certificates/' + A.certificato + '/download', 'consentito', 'certificato di uno studente del tutor che segue'],
            ['/certificates/' + B.certificato + '/download', 'negato', 'certificato di uno studente fuori dal suo perimetro'],
            // La fruizione di una lezione (17/10, Alessandro): con
            // report.view_assigned l'assistente la apre per i corsi del
            // tutor che affianca, e vede solo gli studenti di quel tutor.
            ['/lessons/' + A.lezione + '/fruizione', 'consentito', 'fruizione di una lezione di un corso del tutor che segue'],
            ['/lessons/' + B.lezione + '/fruizione', 'negato', 'fruizione di una lezione di un corso di un tutor che non segue'],
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
            // La Bacheca (17/10): la legge come il tutor che affianca, ma non
            // pubblica e non modera.
            ['/bacheca/categorie/' + A.bacheca_categoria, 'consentito', 'la bacheca di un corso del tutor che segue'],
            ['/bacheca/categorie/' + B.bacheca_categoria, 'negato', 'la bacheca di un corso di un tutor che non segue'],
            ['/bacheca/risorse/' + A.bacheca_risorsa + '/download', 'consentito', 'i file della bacheca che legge'],
            ['/bacheca/proposte?corso=' + A.corso, 'negato', 'non valuta le proposte'],
            ['/bacheca/nuova?corso=' + A.corso, 'negato', 'non pubblica'],
            ['/bacheca/risorse/' + A.bacheca_risorsa + '/modifica', 'negato', 'non corregge'],
        ]],

        ['admin@test.it', 'amministratore', [
            ['/courses/' + B.corso, 'consentito', 'l\'admin vede tutto'],
            ['/lessons/' + B.lezione + '/edit', 'consentito', 'l\'admin modifica tutto'],
            ['/reports/courses/' + B.corso, 'consentito', 'nessuna restrizione sui report'],
            ['/reports/courses/' + B.corso + '/griglia', 'consentito', 'e nessuna sulla griglia'],
            ['/tre-parole?corso=' + B.corso, 'consentito', 'le tre parole di ogni corso'],
            ['/attempts/' + B.tentativo, 'consentito', 'l\'admin vede i tentativi di tutti'],
            ['/live/' + B.incontro, 'consentito', 'l\'admin apre ogni incontro'],
            ['/lessons/' + B.lezione + '/fruizione', 'consentito', 'l\'admin vede la fruizione di ogni lezione'],
            ['/certificates/' + B.certificato + '/download', 'consentito', 'l\'admin scarica i certificati di tutti'],
            ['/admin/permissions', 'consentito', 'è sua'],
            ['/admin/users/importa', 'consentito', 'l\'importazione è sua'],
            ['/admin/settings/bunny', 'consentito', 'è sua'],
            ['/admin/settings/informazioni', 'consentito', 'la versione installata e il codice sorgente (18/10)'],
            ['/lessons/' + A.lezione_chiusa, 'consentito', 'il rilascio non vale per l\'admin'],
            ['/materials/' + A.materiale_chiuso + '/download', 'consentito',
                'il rilascio non vale per l\'admin, e il file di prova esiste'],
            ['/gruppi/' + B.gruppo, 'consentito', 'l\'admin vede ogni gruppo'],
            ['/utenti/' + d.utenti.studenteB + '/immagine', 'consentito',
                'controprova: la foto di B esiste, quindi chi la rifiuta lo fa per la regola'],
            ['/benvenuti/' + B.benvenuto + '/audio', 'consentito',
                'controprova: l\'audio di B esiste, e l\'admin carica i benvenuti'],
            ['/bacheca/categorie/' + B.bacheca_categoria, 'consentito', 'l\'admin legge ogni bacheca'],
            ['/bacheca/proposte?corso=' + B.corso, 'consentito', 'e ne valuta le proposte'],
            ['/bacheca/risorse/' + B.bacheca_proposta + '/modifica', 'consentito', 'e le corregge'],
            ['/bacheca/risorse/' + B.bacheca_risorsa + '/download', 'consentito', 'controprova: il file di B esiste'],
        ]],
        // Chi e' atteso a un incontro (25/10, conflitti 1 e 2). Con modulo e
        // gruppo l'incontro e' del solo gruppo, non di tutti gli iscritti al
        // corso; con il solo modulo e' degli iscritti prima che finisse che,
        // quando e' stato creato, non avevano ancora finito il corso.
        ['altrogruppo@test.it', 'studente dell\'altra classe del corso A', [
            ['/live/' + A.incontro, 'negato', 'incontro del gruppo di un\'altra classe, anche se il modulo è del suo corso'],
            ['/live/' + A.incontro_corso, 'consentito', 'controprova: l\'incontro di tutto il suo corso è suo'],
            ['/live/' + A.incontro_corso_passato, 'negato', 'incontro di tutto il corso finito prima della sua iscrizione'],
        ]],
        ['finito@test.it', 'studente che aveva già finito il corso A', [
            ['/live/' + A.incontro_corso, 'negato', 'incontro di tutto il corso creato quando l\'aveva già finito'],
            ['/live/' + A.incontro_corso_passato, 'consentito', 'controprova: creato quando non l\'aveva ancora finito'],
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

        // --- chi e' atteso a un incontro, nella griglia del corso ---------
        //
        // La porta dell'incontro e la griglia usano la stessa regola
        // (`LiveSessionModel::atteso`): a chi non e' atteso l'incontro e'
        // «non previsto», non «assente». Si legge il CSV dell'admin, che ha
        // tutte le righe, e una parola per casella.

        console.log('\n--- chi è atteso a un incontro: la griglia del corso A');

        {
            const { ctx, page } = await entra(browser, 'admin@test.it');
            const csv = await page.evaluate(async (u) => (await (await fetch(u, { credentials: 'same-origin' })).text()),
                '/reports/courses/' + A.corso + '/griglia/csv');
            const righe = csv.replace(/^\uFEFF/, '').split(/\r?\n/).filter((r) => r !== '').map((r) => {
                const sep = csv.includes(';') ? ';' : ',';
                const out = []; let cur = ''; let q = false;
                for (let i = 0; i < r.length; i++) {
                    const ch = r[i];
                    if (q) { if (ch === '"' && r[i + 1] === '"') { cur += '"'; i++; } else if (ch === '"') q = false; else cur += ch; }
                    else if (ch === '"') q = true; else if (ch === sep) { out.push(cur); cur = ''; } else cur += ch;
                }
                out.push(cur);
                return out;
            });
            const testa = righe[0] || [];
            const colonna = (fine) => testa.findIndex((t) => t.endsWith(fine));
            const casella = (email, fine) => {
                const r = righe.find((x) => x[1] === email);
                const c = colonna(fine);
                return r && c >= 0 ? r[c] : '(manca)';
            };
            const ATTESE = [
                ['stud@test.it', 'mondo A incontro del corso passato', 'assente', 'era iscritto prima che finisse'],
                ['stud@test.it', 'mondo A incontro concluso', 'assente', 'l\'incontro passato del suo gruppo'],
                ['altrogruppo@test.it', 'mondo A incontro del corso passato', 'non previsto', 'finito prima della sua iscrizione'],
                ['altrogruppo@test.it', 'mondo A incontro concluso', 'non previsto', 'l\'incontro di un altro gruppo dello stesso corso'],
                ['altrogruppo@test.it', 'mondo A incontro del corso', 'non ancora svolto', 'controprova: quello futuro di tutto il corso è suo'],
                ['finito@test.it', 'mondo A incontro del corso', 'non previsto', 'creato quando aveva già finito il corso'],
                ['finito@test.it', 'mondo A incontro del corso passato', 'assente', 'controprova: creato quando non l\'aveva ancora finito'],
            ];
            for (const [email, titolo, atteso, perche] of ATTESE) {
                const v = casella(email, titolo);
                check('griglia del corso A → ' + email + ', «' + titolo + '»: ' + atteso + ' (' + perche + ')',
                    v === atteso, v === atteso ? [] : ['ottenuto «' + v + '»']);
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

        // --- «Studenti fermi»: che cosa c'è dentro -------------------------
        //
        // Lo studente B viene iscritto per la durata della prova al corso
        // del mondo A, con l'iscrizione di 40 giorni fa e nessuna attività:
        // è fermo in un corso che l'assistente segue. Se la pagina guardasse
        // il corso e non il perimetro degli studenti, comparirebbe.

        console.log('\n--- «Studenti fermi»: che cosa c\'è dentro');

        {
            const radice = path.join(__dirname, '..');
            const sql = (q) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";'
                + ' App\\Core\\Database::connection()->prepare($argv[1])->execute([(int) $argv[2], (int) $argv[3]]);',
                '--', q, String(d.utenti.studenteB), String(A.corso)], { cwd: radice, encoding: 'utf8' });
            const emailB = 'strano@test.it';

            sql('INSERT IGNORE INTO enrollments (user_id, course_id, enrolled_at) VALUES (?, ?, NOW() - INTERVAL 40 DAY)');

            try {
                for (const email of ['assist@test.it', 'tutor1@test.it']) {
                    const { ctx, page } = await entra(browser, email);
                    const risposta = await page.goto(BASE + '/reports/fermi?giorni=30');
                    const html = await page.content();
                    const pulito = risposta.status() === 200 && !html.includes(emailB);
                    check(
                        email + ' → «Studenti fermi» non contiene lo studente del mondo B',
                        pulito,
                        pulito ? [] : ['stato ' + risposta.status() + (html.includes(emailB) ? ', «' + emailB + '» compare' : '')]
                    );
                    // La griglia del corso A, dove lo studente B e' iscritto
                    // per la prova: la riga non c'e', ne' a schermo ne' nel file.
                    await page.goto(BASE + '/reports/courses/' + A.corso + '/griglia');
                    const rigaB = '/reports/students/' + d.utenti.studenteB + '"';
                    const grigliaPulita = !(await page.content()).includes(rigaB);
                    const csvGriglia = await page.evaluate(async (u) => (await (await fetch(u, { credentials: 'same-origin' })).text()), '/reports/courses/' + A.corso + '/griglia/csv');
                    const csvGrigliaPulito = csvGriglia.includes('Studente;Email;Gruppo') && !csvGriglia.includes(emailB);
                    check(
                        email + ' → la griglia del corso non ha la riga dello studente del mondo B, nemmeno nel CSV',
                        grigliaPulita && csvGrigliaPulito,
                        grigliaPulita && csvGrigliaPulito ? [] : ['pagina: ' + (grigliaPulita ? 'pulita' : 'c\'è') + ', CSV: ' + (csvGrigliaPulito ? 'pulito' : csvGriglia.slice(0, 80))]
                    );
                    // Il file scaricato ha lo stesso perimetro della pagina.
                    const csv = await page.evaluate(async () => (await (await fetch('/reports/fermi/csv?giorni=30', { credentials: 'same-origin' })).text()));
                    // Prima si controlla che sia davvero il file: una pagina
                    // d'errore non contiene l'email e passerebbe per pulita.
                    const eIlFile = csv.includes('Studente;Email;Corso');
                    const csvPulito = eIlFile && !csv.includes(emailB);
                    check(
                        email + ' → e nemmeno il CSV',
                        csvPulito,
                        csvPulito ? [] : [eIlFile ? '«' + emailB + '» compare nel file' : 'non è il CSV: ' + csv.slice(0, 60)]
                    );
                    await ctx.close();
                }

                const admin = await entra(browser, 'admin@test.it');
                await admin.page.goto(BASE + '/reports/fermi?giorni=30');
                const csvAdmin = await admin.page.evaluate(async () => (await (await fetch('/reports/fermi/csv?giorni=30', { credentials: 'same-origin' })).text()));
                await admin.page.goto(BASE + '/reports/courses/' + A.corso + '/griglia');
                const nellaGriglia = (await admin.page.content()).includes('/reports/students/' + d.utenti.studenteB + '"');
                await admin.page.goto(BASE + '/reports/fermi?giorni=30');
                const loTrova = (await admin.page.content()).includes(emailB) && csvAdmin.includes(emailB) && nellaGriglia;
                check(
                    'controprova: l\'amministratore lo vede fra i fermi, nella pagina e nel CSV, e nella griglia',
                    loTrova,
                    loTrova ? [] : ['se non lo vedesse nemmeno lui, le prove qui sopra non direbbero niente']
                );
                await admin.ctx.close();
            } finally {
                sql('DELETE FROM enrollments WHERE user_id = ? AND course_id = ?');
            }
        }

        // --- «Tre parole»: chi le legge, e chi le scrive -------------------
        //
        // Il corso A le chiede da ieri. Nel suo modulo ci sono le parole
        // dello studente A (gruppo A) e una terna con il gruppo B, come se
        // nel corso ci fosse anche un gruppo di un collega. Il tutor A vede
        // le prime e non la seconda, in tutte e due le viste; l'admin tutte.
        // E nessuno scrive parole per un modulo che non ha completato.

        console.log('\n--- «Tre parole»: chi le legge, e chi le scrive');

        {
            const radice = path.join(__dirname, '..');
            const sql = (q, ...valori) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";'
                + ' App\\Core\\Database::connection()->prepare($argv[1])->execute(array_slice($argv, 2));',
                '--', q, ...valori.map(String)], { cwd: radice, encoding: 'utf8' });
            const parola = (u, g, p) => sql('INSERT INTO module_words (user_id, module_id, course_id, group_id, word1, word2, word3) VALUES (?, ?, ?, ?, ?, ?, ?)',
                u, A.modulo, A.corso, g, p, 'seconda', 'terza');

            sql('UPDATE courses SET three_words_since = NOW() - INTERVAL 1 DAY WHERE id = ?', A.corso);
            parola(d.utenti.studenteA, A.gruppo, 'paroladia');
            parola(d.utenti.studenteB, B.gruppo, 'paroladib');

            try {
                const { ctx, page } = await entra(browser, 'tutor1@test.it');

                for (const vista of ['', '&vista=nomi']) {
                    const r = await page.goto(BASE + '/tre-parole?corso=' + A.corso + vista);
                    const html = await page.content();
                    const giusto = r.status() === 200 && html.includes('paroladia') && !html.includes('paroladib');
                    check(
                        'tutor del mondo A → «Tre parole»' + (vista ? ' con i nomi' : ' anonime') + ': le parole del suo gruppo, non quelle di un altro',
                        giusto,
                        giusto ? [] : ['stato ' + r.status() + ', A: ' + html.includes('paroladia') + ', B: ' + html.includes('paroladib')]
                    );
                }

                // Il tutor non scrive parole: e' una cosa dello studente.
                const t = await esitoPost(page, '/courses/' + A.corso + '/moduli/' + A.modulo + '/tre-parole',
                    [['parole[0]', 'a'], ['parole[1]', 'b'], ['parole[2]', 'c']]);
                check('tutor del mondo A → POST tre parole: negato', t.esito === 'negato', t.esito === 'negato' ? [] : ['ottenuto ' + t.esito]);
                await ctx.close();

                const admin = await entra(browser, 'admin@test.it');
                await admin.page.goto(BASE + '/tre-parole?corso=' + A.corso);
                const tutte = await admin.page.content();
                check('controprova: l\'amministratore le vede tutte e due',
                    tutte.includes('paroladia') && tutte.includes('paroladib'), []);
                await admin.ctx.close();

                // Lo studente A non ha completato il modulo (il suo tentativo
                // al questionario e' fallito): niente parole, anche con un
                // invio costruito a mano.
                const studente = await entra(browser, 'strano@test.it');
                const s = await esitoPost(studente.page, '/courses/' + A.corso + '/moduli/' + A.modulo + '/tre-parole',
                    [['parole[0]', 'a'], ['parole[1]', 'b'], ['parole[2]', 'c']]);
                check('studente del mondo B → POST tre parole in un corso non suo: negato', s.esito === 'negato', s.esito === 'negato' ? [] : ['ottenuto ' + s.esito]);
                await studente.ctx.close();

                const stud = await entra(browser, 'stud@test.it');
                const m = await esitoPost(stud.page, '/courses/' + A.corso + '/moduli/' + A.modulo + '/tre-parole',
                    [['parole[0]', 'a'], ['parole[1]', 'b'], ['parole[2]', 'c']]);
                check('studente del mondo A → POST tre parole per un modulo non completato: negato', m.esito === 'negato', m.esito === 'negato' ? [] : ['ottenuto ' + m.esito]);
                await stud.ctx.close();
            } finally {
                sql('DELETE FROM module_words WHERE course_id = ?', A.corso);
                sql('UPDATE courses SET three_words_since = NULL WHERE id = ?', A.corso);
            }
        }

        // --- Questionari H5P: i file del contenuto e il punteggio ----------
        //
        // Il questionario del mondo A diventa per la durata della prova un
        // questionario H5P, con un pacchetto minimo aperto da
        // `H5PArchivio::apri` (lo stesso codice del caricamento). Lo
        // studente A apre i file e salva un tentativo; lo studente B, che
        // non e' iscritto, no; un indirizzo con «..» non esce dalla
        // cartella; un punteggio senza senso non si salva.

        console.log('\n--- Questionari H5P: file del contenuto e punteggio');

        {
            const radice = path.join(__dirname, '..');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const dir = php(`
                $f = tempnam(sys_get_temp_dir(), "h5p");
                $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
                $z->addFromString("h5p.json", json_encode(["title" => "Prova", "mainLibrary" => "H5P.Prova", "preloadedDependencies" => [["machineName" => "H5P.Prova", "majorVersion" => 1, "minorVersion" => 0]]]));
                $z->addFromString("content/content.json", "{}");
                $z->addFromString("H5P.Prova-1.0/library.json", "{}");
                $z->close();
                $a = App\\Core\\H5PArchivio::apri($f, (int) $argv[1]); unlink($f);
                App\\Models\\QuizModel::impostaH5P((int) $argv[1], $a["dir"], $a["libreria"], "prova.h5p");
                echo $a["dir"];`, A.quiz).trim();
            const file = '/quizzes/' + A.quiz + '/h5p/content/content.json';
            const tentativo = '/quizzes/' + A.quiz + '/h5p/tentativo';

            try {
                const stud = await entra(browser, 'stud@test.it');
                let r = await esitoGet(stud.page, file);
                check('studente del mondo A → un file del suo questionario H5P: consentito', r.esito === 'consentito', r.esito === 'consentito' ? [] : [r.come]);
                r = await esitoGet(stud.page, '/quizzes/' + A.quiz + '/h5p/%2e%2e/%2e%2e/%2e%2e/config/config.php');
                check('studente del mondo A → «..» fuori dalla cartella del contenuto: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                r = await esitoPost(stud.page, tentativo, [['raw', '3'], ['max', '4']]);
                check('studente del mondo A → salva un tentativo: consentito', r.esito === 'consentito', r.esito === 'consentito' ? [] : [r.come]);
                r = await esitoPost(stud.page, tentativo, [['raw', '9'], ['max', '4']]);
                check('studente del mondo A → un punteggio oltre il massimo: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                // Superato al 100% (23/10): da li' in poi e' concluso.
                r = await esitoPost(stud.page, tentativo, [['raw', '4'], ['max', '4']]);
                check('studente del mondo A → salva un tentativo al 100%: consentito', r.esito === 'consentito', r.esito === 'consentito' ? [] : [r.come]);
                r = await esitoPost(stud.page, tentativo, [['raw', '2'], ['max', '4']]);
                check('studente del mondo A → un altro tentativo dopo il 100%: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                await stud.page.goto(BASE + '/quizzes/' + A.quiz);
                const spento = await stud.page.evaluate(() => {
                    const c = document.querySelector('#h5p-contenitore');
                    return !!c && c.hasAttribute('inert') && document.body.textContent.includes('Superato al 100%: il questionario è concluso.');
                });
                check('studente del mondo A → dopo il 100% l esercizio è spento, con l avviso', spento, spento ? [] : ['non spento']);
                await stud.ctx.close();

                const salvati = php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = " . (int) $argv[1] . " AND h5p_raw = 3 AND h5p_max = 4")->fetchColumn();', A.quiz).trim();
                check('il tentativo e nel database, con «3 su 4»', salvati === '1', salvati === '1' ? [] : ['trovati ' + salvati]);
                const dopo = php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = " . (int) $argv[1] . " AND h5p_raw = 2")->fetchColumn();', A.quiz).trim();
                check('il tentativo dopo il 100% non è nel database', dopo === '0', dopo === '0' ? [] : ['trovati ' + dopo]);

                // L'altra classe e' iscritta al corso A ma non ha fatto la
                // lezione del modulo: il questionario e' ancora chiuso
                // (27/10), anche i suoi file e il punteggio mandato a mano.
                const altra = await entra(browser, 'altrogruppo@test.it');
                r = await esitoGet(altra.page, file);
                check('altra classe, lezione del modulo non fatta → un file del questionario H5P: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                r = await esitoPost(altra.page, tentativo, [['raw', '4'], ['max', '4']]);
                check('altra classe, lezione del modulo non fatta → un tentativo H5P: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                await altra.ctx.close();

                const strano = await entra(browser, 'strano@test.it');
                r = await esitoGet(strano.page, file);
                check('studente del mondo B → un file del questionario del mondo A: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                r = await esitoPost(strano.page, tentativo, [['raw', '4'], ['max', '4']]);
                check('studente del mondo B → un tentativo nel questionario del mondo A: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                await strano.ctx.close();
            } finally {
                php(`
                    $pdo = App\\Core\\Database::connection();
                    $pdo->prepare("DELETE FROM quiz_attempts WHERE quiz_id = ? AND h5p_raw IS NOT NULL")->execute([(int) $argv[1]]);
                    $pdo->prepare("UPDATE quizzes SET kind = 'domande', h5p_dir = NULL, h5p_library = NULL, h5p_file = NULL WHERE id = ?")->execute([(int) $argv[1]]);
                    App\\Core\\H5PArchivio::elimina($argv[2]);`, A.quiz, dir);
            }
        }

        // --- Il questionario, test d'uscita del modulo (27/10) --------------
        //
        // Il questionario si apre con le lezioni del modulo completate; un
        // modulo senza lezioni con il questionario e' la verifica finale, e
        // si apre con i moduli prima completati. Chiuso, nella pagina del
        // corso e' grigio e senza collegamento; chi ci arriva dall'indirizzo
        // torna al corso con l'avviso, e un invio a mano non si salva. Lo
        // studente A ha fatto la lezione (`--lezioni-fatte`), l'altra classe
        // no.

        console.log('\n--- Il questionario, test d\'uscita del modulo');

        {
            const radice = path.join(__dirname, '..');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const rigaQuiz = (page, quiz) => page.evaluate((quiz) => {
                const link = document.querySelector('.module-quiz-row a.quiz-link[href="/quizzes/' + quiz + '"]');
                const chiuso = [...document.querySelectorAll('.module-quiz-row .quiz-link-chiuso')];
                return { link: !!link, chiuso: chiuso.length, fallo: !!document.querySelector('.module-quiz-row .da-qui-comando[href="/quizzes/' + quiz + '"]') };
            }, quiz);
            const tentativiDi = (utente, quiz) => php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM quiz_attempts WHERE user_id = " . (int) $argv[1] . " AND quiz_id = " . (int) $argv[2])->fetchColumn();', utente, quiz).trim();

            const altra = await entra(browser, 'altrogruppo@test.it');
            await altra.page.goto(BASE + '/courses/' + A.corso);
            let q = await rigaQuiz(altra.page, A.quiz);
            check('altra classe, lezione non fatta → nel corso il questionario è grigio, senza collegamento né «Fallo ora»',
                !q.link && q.chiuso >= 1 && !q.fallo, [JSON.stringify(q)]);
            await altra.page.goto(BASE + '/quizzes/' + A.quiz);
            // Senza l'ancora del modulo: l'avviso e' in cima, e saltando al
            // modulo non si vedrebbe.
            const arrivo = await altra.page.evaluate(() => ({
                dove: location.pathname + location.hash,
                avviso: (document.querySelector('.alert.alert-error') || {}).textContent || '',
            }));
            check('altra classe → il questionario dall\'indirizzo: torna al corso, con «si apre quando hai fatto le lezioni del modulo»',
                arrivo.dove === '/courses/' + A.corso && arrivo.avviso.includes('si apre quando hai fatto le lezioni del modulo.'), [JSON.stringify(arrivo)]);
            // Un invio completo, una risposta per domanda (l’ultima opzione,
            // di solito sbagliata, e un testo per le aperte): aperto salva un
            // tentativo, e la controprova qui sotto, con lo studente A, lo
            // manda uguale e poi lo toglie.
            const invio = JSON.parse(php(`
                $pdo = App\\Core\\Database::connection();
                $campi = [];
                foreach ($pdo->query("SELECT id, question_type FROM quiz_questions WHERE quiz_id = " . (int) $argv[1]) as $q) {
                    if ($q["question_type"] === "open") { $campi[] = ["open[" . $q["id"] . "]", "Una risposta."]; continue; }
                    $o = (int) $pdo->query("SELECT id FROM quiz_options WHERE question_id = " . (int) $q["id"] . " ORDER BY position DESC, id DESC LIMIT 1")->fetchColumn();
                    $campi[] = [$q["question_type"] === "multiple_choice" ? "answers[" . $q["id"] . "][]" : "answers[" . $q["id"] . "]", (string) $o];
                }
                echo json_encode($campi);`, A.quiz));
            const prima = tentativiDi(d.utenti.altroGruppo, A.quiz);
            await esitoPost(altra.page, '/quizzes/' + A.quiz + '/attempts', invio, '/courses/' + A.corso);
            const dopo = tentativiDi(d.utenti.altroGruppo, A.quiz);
            check('altra classe → un tentativo mandato a mano non si salva', prima === dopo, ['prima ' + prima + ', dopo ' + dopo]);
            await altra.ctx.close();

            const stud = await entra(browser, 'stud@test.it');
            await stud.page.goto(BASE + '/courses/' + A.corso);
            q = await rigaQuiz(stud.page, A.quiz);
            check('controprova: studente A, lezione fatta → il questionario ha il collegamento e «Fallo ora»', q.link && q.fallo, [JSON.stringify(q)]);
            const apre = await esitoGet(stud.page, '/quizzes/' + A.quiz);
            check('controprova: studente A → il questionario si apre', apre.esito === 'consentito', [apre.come]);
            const primaA = tentativiDi(d.utenti.studenteA, A.quiz);
            const ultimo = php('echo (int) App\\Core\\Database::connection()->query("SELECT COALESCE(MAX(id), 0) FROM quiz_attempts")->fetchColumn();').trim();
            await esitoPost(stud.page, '/quizzes/' + A.quiz + '/attempts', invio, '/quizzes/' + A.quiz);
            const dopoA = tentativiDi(d.utenti.studenteA, A.quiz);
            check('controprova: studente A → lo stesso invio salva il tentativo', Number(dopoA) === Number(primaA) + 1, ['prima ' + primaA + ', dopo ' + dopoA]);
            php('App\\Core\\Database::connection()->prepare("DELETE FROM quiz_attempts WHERE id > ? AND user_id = ? AND quiz_id = ?")->execute([(int) $argv[1], (int) $argv[2], (int) $argv[3]]);', ultimo, d.utenti.studenteA, A.quiz);

            // La verifica finale: un corso a parte con due moduli da una
            // lezione e la verifica (dal 28/10 la dichiara la casella,
            // `is_final`), per lo studente A.
            const [corso, l1, l2, verifica] = php(`
                $pdo = App\\Core\\Database::connection();
                $pdo->prepare("INSERT INTO courses (title, slug, description, is_published, created_by) VALUES (?, ?, '', 1, ?)")->execute(["[prova-permessi] verifica finale", "prova-permessi-verifica-" . time(), (int) $argv[2]]);
                $c = (int) $pdo->lastInsertId();
                $l = [];
                foreach ([0, 1] as $i) {
                    $pdo->prepare("INSERT INTO modules (course_id, title, position) VALUES (?, ?, ?)")->execute([$c, "Tappa " . ($i + 1), $i]);
                    $m = (int) $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO lessons (module_id, title, content_html, position) VALUES (?, ?, '<p>Testo.</p>', 0)")->execute([$m, "Lezione " . ($i + 1)]);
                    $l[] = (int) $pdo->lastInsertId();
                }
                $pdo->prepare("INSERT INTO modules (course_id, title, position, is_final) VALUES (?, 'Verifica finale', 2, 1)")->execute([$c]);
                $m = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO quizzes (module_id, title, passing_score_pct) VALUES (?, 'Verifica del corso', 60)")->execute([$m]);
                $q = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)")->execute([(int) $argv[1], $c]);
                echo $c, " ", $l[0], " ", $l[1], " ", $q;`, d.utenti.studenteA, d.utenti.admin).trim().split(' ');

            try {
                await stud.page.goto(BASE + '/courses/' + corso);
                const riga = await stud.page.evaluate(() => [...document.querySelectorAll('details.modulo-riga')]
                    .map((x) => x.querySelector('.modulo-riga-titolo').textContent.trim() + ': ' + x.querySelector('.modulo-riga-stato').textContent.trim()));
                check('verifica finale → finché è una riga dice «questionario», non «nessuna lezione»', riga.includes('Verifica finale: questionario'), [JSON.stringify(riga)]);
                await stud.page.goto(BASE + '/courses/' + corso + '?moduli=tutti');
                q = await rigaQuiz(stud.page, verifica);
                const vuota = (await stud.page.content()).includes('Nessuna lezione in questo modulo.');
                check('verifica finale, moduli prima non fatti → grigia, senza collegamento, e senza «Nessuna lezione»', !q.link && q.chiuso === 1 && !vuota, [JSON.stringify(q), 'nessuna lezione: ' + vuota]);
                await stud.page.goto(BASE + '/quizzes/' + verifica);
                let avviso = await stud.page.evaluate(() => location.pathname + ' ' + ((document.querySelector('.alert.alert-error') || {}).textContent || ''));
                check('verifica finale dall\'indirizzo → torna al corso, con «si apre quando hai completato i moduli precedenti»',
                    avviso.startsWith('/courses/' + corso + ' ') && avviso.includes('si apre quando hai completato i moduli precedenti.'), [avviso]);

                await esitoPost(stud.page, '/lessons/' + l1 + '/complete', [], '/lessons/' + l1);
                const meta = await esitoGet(stud.page, '/quizzes/' + verifica);
                check('verifica finale, un modulo prima su due → ancora chiusa', meta.esito !== 'consentito' || (await stud.page.content()).includes('si apre quando hai completato i moduli precedenti'), [meta.come]);
                await esitoPost(stud.page, '/lessons/' + l2 + '/complete', [], '/lessons/' + l2);
                await stud.page.goto(BASE + '/courses/' + corso);
                q = await rigaQuiz(stud.page, verifica);
                check('verifica finale, moduli prima completati → si apre, con «Fallo ora»', q.link && q.fallo, [JSON.stringify(q)]);
                await stud.page.goto(BASE + '/quizzes/' + verifica);
                avviso = await stud.page.evaluate(() => location.pathname);
                check('verifica finale, moduli prima completati → la pagina del questionario si apre', avviso === '/quizzes/' + verifica, [avviso]);
            } finally {
                await stud.ctx.close();
                php('App\\Core\\Database::connection()->prepare("DELETE FROM courses WHERE id = ?")->execute([(int) $argv[1]]);', corso);
            }
        }

        // --- L'avviso delle richieste di iscrizione (29/10) ----------------
        //
        // Con una richiesta sola, al singolare: prima diceva «Ci sono 1
        // richieste». Le due righe si creano qui e si tolgono alla fine; con
        // altre richieste gia' in attesa nel database si prova solo il
        // plurale.

        console.log('\n--- L\'avviso delle richieste di iscrizione');

        {
            const radice = path.join(__dirname, '..');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const chiedi = (corso) => php('App\\Core\\Database::connection()->prepare("INSERT INTO enrollment_requests (user_id, course_id, message) VALUES (?, ?, \'prova\')")->execute([(int) $argv[1], (int) $argv[2]]);', d.utenti.studenteNuovo, corso);
            const gia = Number(php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM enrollment_requests WHERE status = \'pending\'")->fetchColumn();').trim());
            const admin = await entra(browser, 'admin@test.it');
            const avviso = async () => { await admin.page.goto(BASE + '/admin/courses'); return admin.page.evaluate(() => ((document.querySelector('.alert.alert-warning') || {}).textContent || '').replace(/\s+/g, ' ').trim()); };

            try {
                chiedi(A.corso);
                const una = await avviso();
                if (gia === 0) {
                    check('admin → una richiesta in attesa: «C’è 1 richiesta di iscrizione in attesa: la trovi nella scheda del corso interessato.»',
                        una === 'C’è 1 richiesta di iscrizione in attesa: la trovi nella scheda del corso interessato.', [una]);
                }
                chiedi(B.corso);
                const due = await avviso();
                check('admin → più richieste in attesa: al plurale, con il numero',
                    due === 'Ci sono ' + (gia + 2) + ' richieste di iscrizione in attesa: le trovi nella scheda dei corsi interessati.', [due]);
            } finally {
                await admin.ctx.close();
                php('App\\Core\\Database::connection()->prepare("DELETE FROM enrollment_requests WHERE user_id = ? AND message = \'prova\'")->execute([(int) $argv[1]]);', d.utenti.studenteNuovo);
            }
        }

        // --- La verifica finale (28/10) -------------------------------------
        //
        // Un modulo che l'admin dichiara verifica finale con la casella: solo
        // il questionario, sempre in fondo, una per corso. Non e' un
        // contenuto: non si sceglie per incontri, Bacheca, domande, e non
        // chiede le Tre parole. Si prova nel corso A, con un modulo creato
        // dall'admin come dal pannello e tolto alla fine.

        console.log('\n--- La verifica finale');

        {
            const radice = path.join(__dirname, '..');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const ordine = () => php('echo json_encode(App\\Core\\Database::connection()->query("SELECT id, title, is_final, quiz_required FROM modules WHERE course_id = " . (int) $argv[1] . " ORDER BY position, id")->fetchAll(PDO::FETCH_ASSOC));', A.corso);
            const leggi = () => JSON.parse(ordine());
            const crea = '/courses/' + A.corso + '/modules';
            const nuovo = '/courses/' + A.corso + '/modules/create';
            const avviso = (page) => page.evaluate(() => ((document.querySelector('.alert.alert-error') || {}).textContent || '').trim());
            // Il rifiuto e' un rimando con l'avviso in sessione: si apre la
            // pagina dove torna, e si legge li'.
            const rifiutoDi = async (page, url, campi, tokenDa, torna) => {
                await esitoPost(page, url, campi, tokenDa);
                await page.goto(BASE + torna);
                return avviso(page);
            };
            const admin = await entra(browser, 'admin@test.it');
            let verifica = null;
            let altro = null;

            try {
                const primo = leggi().find((x) => Number(x.id) === Number(A.modulo));
                const conLezioni = await rifiutoDi(admin.page, '/modules/' + A.modulo, [['title', primo.title], ['is_final', '1']],
                    '/modules/' + A.modulo + '/edit', '/modules/' + A.modulo + '/edit');
                check('admin → accenderla su un modulo con lezioni: rifiutato, con la frase',
                    conLezioni.includes('Una verifica finale non ha lezioni: toglile prima di farne una verifica.')
                    && leggi().every((x) => Number(x.is_final) === 0), [conLezioni]);

                await esitoPost(admin.page, crea, [['title', 'Verifica di prova'], ['is_final', '1'], ['quiz_required', '1']], nuovo);
                let m = leggi();
                verifica = m.find((x) => x.title === 'Verifica di prova');
                check('admin → crea la verifica finale: in fondo al corso, e senza «questionario obbligatorio»',
                    !!verifica && m[m.length - 1].id === verifica.id && Number(verifica.is_final) === 1 && Number(verifica.quiz_required) === 0, [JSON.stringify(m)]);

                await esitoPost(admin.page, crea, [['title', 'Tappa dopo la verifica']], nuovo);
                m = leggi();
                altro = m.find((x) => x.title === 'Tappa dopo la verifica');
                check('admin → un modulo creato dopo va prima della verifica, che resta in fondo',
                    !!altro && m[m.length - 1].id === verifica.id && m[m.length - 2].id === altro.id, [JSON.stringify(m.map((x) => x.title))]);

                const rifiuto = await rifiutoDi(admin.page, crea, [['title', 'Seconda verifica'], ['is_final', '1']], nuovo, nuovo);
                m = leggi();
                check('admin → una seconda verifica nello stesso corso: rifiutata, con la frase',
                    !m.some((x) => x.title === 'Seconda verifica') && rifiuto.includes('Questo corso ha già una verifica finale: «Verifica di prova». Ce ne può essere una sola per corso.'), [rifiuto]);

                const seconda = await rifiutoDi(admin.page, '/modules/' + altro.id, [['title', 'Tappa dopo la verifica'], ['is_final', '1']],
                    '/modules/' + altro.id + '/edit', '/modules/' + altro.id + '/edit');
                check('admin → accenderla su un secondo modulo esistente: rifiutato',
                    seconda.includes('Questo corso ha già una verifica finale') && leggi().filter((x) => Number(x.is_final) === 1).length === 1, [seconda]);

                await admin.page.goto(BASE + '/courses/' + A.corso + '/modules/create');
                const vietaLezioni = await esitoPost(admin.page, '/modules/' + verifica.id + '/lessons', [['title', 'Lezione nella verifica']], '/courses/' + A.corso);
                const lezioni = php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM lessons WHERE module_id = " . (int) $argv[1])->fetchColumn();', verifica.id).trim();
                await admin.page.goto(BASE + '/modules/' + verifica.id + '/lessons/create');
                const daForm = { dove: new URL(admin.page.url()).pathname, avviso: await avviso(admin.page) };
                check('admin → nessuna lezione nella verifica, né dal modulo né dall\'indirizzo',
                    lezioni === '0' && daForm.dove === '/courses/' + A.corso && daForm.avviso.includes('La verifica finale contiene solo il questionario'), [vietaLezioni.come, lezioni, JSON.stringify(daForm)]);

                await esitoPost(admin.page, '/modules/' + verifica.id + '/move', [['direction', 'up']], '/courses/' + A.corso);
                await esitoPost(admin.page, '/modules/' + altro.id + '/move', [['direction', 'down']], '/courses/' + A.corso);
                m = leggi();
                check('admin → la verifica non sale, e nessun modulo scende sotto di lei',
                    m[m.length - 1].id === verifica.id && m[m.length - 2].id === altro.id, [JSON.stringify(m.map((x) => x.title))]);

                await admin.page.goto(BASE + '/courses/' + A.corso);
                const carta = await admin.page.evaluate((id) => {
                    const c = document.getElementById('modulo-' + id);
                    const prima = c && c.previousElementSibling;
                    return c && {
                        etichetta: [...c.querySelectorAll('h2 .badge')].map((b) => b.textContent.trim()),
                        lezione: !!c.querySelector('a[href$="/lessons/create"]'),
                        frecce: [...c.querySelectorAll('button[title^="Sposta il modulo"]')].map((b) => b.disabled),
                        giuPrima: prima ? (prima.querySelector('button[title="Sposta il modulo giù"]') || {}).disabled : null,
                    };
                }, verifica.id);
                check('admin → nella pagina del corso: «verifica finale», senza «+ Lezione», frecce spente, e il modulo prima non scende',
                    !!carta && carta.etichetta.includes('verifica finale') && !carta.lezione && carta.frecce.every((x) => x) && carta.giuPrima === true, [JSON.stringify(carta)]);

                await admin.page.goto(BASE + '/modules/' + verifica.id + '/edit');
                const scheda = await admin.page.evaluate(() => ({
                    avviso: (document.getElementById('is_final_aiuto') || {}).textContent || '',
                    obbligatorioVisibile: (() => { const l = document.querySelector('[data-questionario-obbligatorio]'); return !!l && getComputedStyle(l).display !== 'none'; })(),
                }));
                await admin.page.uncheck('[data-verifica-finale]');
                const spenta = await admin.page.evaluate(() => getComputedStyle(document.querySelector('[data-questionario-obbligatorio]')).display !== 'none');
                check('admin → «Modifica modulo» della verifica: l\'avviso sullo spegnerla, e «Questionario obbligatorio» nascosto finché è accesa',
                    scheda.avviso.includes('Spegnendola, il modulo resta senza lezioni e il suo questionario si apre subito a tutti gli studenti')
                    && !scheda.obbligatorioVisibile && spenta, [JSON.stringify(scheda), 'spenta: ' + spenta]);

                await admin.page.goto(BASE + '/live/create');
                const nelleSessioni = await admin.page.evaluate((id) => !!document.querySelector('select[name="module_id"] option[value="' + id + '"]'), verifica.id);
                check('admin → la verifica non è fra i moduli di un incontro', !nelleSessioni, []);
                const sessioni = (titolo) => php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM live_sessions WHERE title = " . App\\Core\\Database::connection()->quote($argv[1]))->fetchColumn();', titolo).trim();
                const incontro = (titolo, modulo) => [['title', titolo], ['module_id', String(modulo)], ['group_id', String(A.gruppo)],
                    ['starts_at', '2030-01-10T10:00'], ['ends_at', '2030-01-10T11:00']];
                const rifiutoIncontro = await rifiutoDi(admin.page, '/live', incontro('[prova-permessi] ripasso sulla verifica', verifica.id), '/live/create', '/live/create');
                await esitoPost(admin.page, '/live', incontro('[prova-permessi] ripasso controprova', A.modulo), '/live/create');
                check('admin → un incontro sulla verifica, mandato a mano, non si salva (sul primo modulo sì)',
                    sessioni('[prova-permessi] ripasso sulla verifica') === '0' && sessioni('[prova-permessi] ripasso controprova') === '1'
                    && rifiutoIncontro.includes('La verifica finale non ha incontri: scegli un altro modulo.'), [rifiutoIncontro]);
                php('App\\Core\\Database::connection()->exec("DELETE FROM live_sessions WHERE title = \'[prova-permessi] ripasso controprova\'");');

                await admin.page.goto(BASE + '/tre-parole?corso=' + A.corso);
                const treParole = (await admin.page.content()).includes('Verifica di prova');
                check('admin → la verifica non è fra i moduli delle Tre parole', !treParole, []);

                const stud = await entra(browser, 'stud@test.it');
                await stud.page.goto(BASE + '/courses/' + A.corso);
                const domande = await stud.page.evaluate((id) => !!document.querySelector('#qa-modulo option[value="' + id + '"]'), verifica.id);
                await stud.page.goto(BASE + '/bacheca/condividi?corso=' + A.corso);
                const bacheca = await stud.page.evaluate((id) => !!document.querySelector('select[name="module_id"] option[value="' + id + '"]'), verifica.id);
                check('studente A → la verifica non è fra i moduli delle domande all\'esperto né della Bacheca', !domande && !bacheca, ['domande: ' + domande, 'bacheca: ' + bacheca]);
                const domandeCon = (testo) => php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM course_questions WHERE question = " . App\\Core\\Database::connection()->quote($argv[1]))->fetchColumn();', testo).trim();
                const domandePrima = domandeCon('Una domanda sulla verifica?');
                await esitoPost(stud.page, '/courses/' + A.corso + '/domande', [['question', 'Una domanda sulla verifica?'], ['module_id', String(verifica.id)]], '/courses/' + A.corso);
                const domandeDopo = domandeCon('Una domanda sulla verifica?');
                await esitoPost(stud.page, '/courses/' + A.corso + '/domande', [['question', 'Una domanda di controprova?'], ['module_id', String(A.modulo)]], '/courses/' + A.corso);
                const controprova = domandeCon('Una domanda di controprova?');
                php('App\\Core\\Database::connection()->exec("DELETE FROM course_questions WHERE question = \'Una domanda di controprova?\'");');
                // Le Tre parole: il corso le chiede, e lo studente A ha appena
                // superato il questionario della verifica, quindi l'ha
                // completata. Non gliele chiede lo stesso.
                php(`
                    $pdo = App\\Core\\Database::connection();
                    $pdo->prepare("INSERT INTO quizzes (module_id, title, passing_score_pct) VALUES (?, 'Verifica di prova', 60)")->execute([(int) $argv[1]]);
                    $q = (int) $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO quiz_attempts (user_id, quiz_id, score_pct, passed) VALUES (?, ?, 100, 1)")->execute([(int) $argv[2], $q]);
                    $pdo->prepare("UPDATE courses SET three_words_since = '2000-01-01 00:00:00' WHERE id = ?")->execute([(int) $argv[3]]);`,
                    verifica.id, d.utenti.studenteA, A.corso);
                await stud.page.goto(BASE + '/courses/' + A.corso);
                const chiede = await stud.page.evaluate((id) => !!document.querySelector('form[action$="/moduli/' + id + '/tre-parole"]'), verifica.id);
                check('studente A, verifica superata → la pagina del corso non chiede le Tre parole sulla verifica', !chiede, []);
                const tre = await esitoPost(stud.page, '/courses/' + A.corso + '/moduli/' + verifica.id + '/tre-parole', [['parole[0]', 'a'], ['parole[1]', 'b'], ['parole[2]', 'c']], '/courses/' + A.corso);
                check('studente A → una domanda o le Tre parole sulla verifica, mandate a mano: rifiutate',
                    domandePrima === '0' && domandeDopo === '0' && controprova === '1' && tre.esito === 'negato',
                    ['domande ' + domandePrima + '→' + domandeDopo + ', controprova ' + controprova, 'tre parole: ' + tre.come]);
                await stud.ctx.close();
            } finally {
                await admin.ctx.close();
                php(`
                    $pdo = App\\Core\\Database::connection();
                    $pdo->exec("DELETE FROM modules WHERE course_id = " . (int) $argv[1] . " AND title IN ('Verifica di prova', 'Tappa dopo la verifica', 'Seconda verifica')");
                    $pdo->exec("DELETE FROM module_words WHERE course_id = " . (int) $argv[1]);
                    $pdo->exec("UPDATE courses SET three_words_since = NULL WHERE id = " . (int) $argv[1]);`, A.corso);
            }
        }

        // --- «Segna come completata»: video da guardare, esercizi da fare ---
        //
        // Dal 27/10 ogni lezione con il video aspetta che lo studente lo
        // avvii, anche con il testo; con gli esercizi H5P ci vogliono tutte e
        // due le cose, e la frase dice quello che manca. La lezione del mondo
        // A diventa per la prova una lezione con testo e video; l'altra classe
        // non l'ha completata. Gli esercizi H5P si tengono «in caricamento»
        // (le loro richieste non rispondono), cosi' restano da fare.

        console.log('\n--- «Segna come completata»: video ed esercizi');

        {
            const radice = path.join(__dirname, '..');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const prima = php('echo json_encode(App\\Core\\Database::connection()->query("SELECT video_provider, video_ref FROM lessons WHERE id = " . (int) $argv[1])->fetch(PDO::FETCH_ASSOC));', A.lezione);
            const pulsante = (page) => page.evaluate(() => {
                const b = document.querySelector('[data-completa]');
                const h = document.querySelector('[data-avviso-completa]');
                return { spento: !!b && b.disabled, frase: h && !h.hidden ? h.textContent.trim() : '' };
            });
            let h5p = null;

            try {
                php('App\\Core\\Database::connection()->prepare("UPDATE lessons SET video_provider = \'self_hosted\', video_ref = \'prova.mp4\' WHERE id = ?")->execute([(int) $argv[1]]);', A.lezione);

                let altra = await entra(browser, 'altrogruppo@test.it');
                await altra.page.goto(BASE + '/lessons/' + A.lezione);
                let p = await pulsante(altra.page);
                check('lezione con testo e video → il pulsante aspetta il video: «Guarda il video per poter segnare la lezione come completata.»',
                    p.spento && p.frase === 'Guarda il video per poter segnare la lezione come completata.', [JSON.stringify(p)]);
                await altra.page.click('[data-avvia]');
                p = await pulsante(altra.page);
                check('lezione con testo e video → avviato il video, il pulsante si accende e la frase sparisce', !p.spento && p.frase === '', [JSON.stringify(p)]);
                await altra.ctx.close();

                h5p = php(`
                    $f = tempnam(sys_get_temp_dir(), "h5p");
                    $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
                    $z->addFromString("h5p.json", json_encode(["title" => "Fisarmonica", "mainLibrary" => "H5P.Accordion", "preloadedDependencies" => [["machineName" => "H5P.Accordion", "majorVersion" => 1, "minorVersion" => 0]]]));
                    $z->addFromString("content/content.json", "{}");
                    $z->addFromString("H5P.Accordion-1.0/library.json", "{}");
                    $z->close();
                    $a = App\\Core\\H5PArchivio::apriPerLezione($f, (int) $argv[1]); unlink($f);
                    echo App\\Models\\LessonH5PModel::create((int) $argv[1], $a, "prova.h5p"), " ", $a["dir"];`, A.lezione).trim().split(' ');

                altra = await entra(browser, 'altrogruppo@test.it');
                await altra.page.route('**/lessons/' + A.lezione + '/h5p/**', () => {});
                await altra.page.goto(BASE + '/lessons/' + A.lezione, { waitUntil: 'domcontentloaded' });
                await altra.page.waitForSelector('[data-avviso-completa]:not([hidden])');
                p = await pulsante(altra.page);
                check('lezione con video ed esercizio → «Guarda il video e fai l’esercizio per poter segnare la lezione come completata.»',
                    p.spento && p.frase === 'Guarda il video e fai l’esercizio per poter segnare la lezione come completata.', [JSON.stringify(p)]);
                await altra.page.click('[data-avvia]');
                p = await pulsante(altra.page);
                check('lezione con video ed esercizio → avviato il video resta «Fai l’esercizio…», e il pulsante spento',
                    p.spento && p.frase === 'Fai l’esercizio per poter segnare la lezione come completata.', [JSON.stringify(p)]);
                await altra.ctx.close();
            } finally {
                const v = JSON.parse(prima);
                php('App\\Core\\Database::connection()->prepare("UPDATE lessons SET video_provider = ?, video_ref = ? WHERE id = ?")->execute([$argv[1], $argv[2] === "" ? null : $argv[2], (int) $argv[3]]);',
                    v.video_provider, v.video_ref || '', A.lezione);

                if (h5p !== null) {
                    php('App\\Core\\Database::connection()->prepare("DELETE FROM lesson_h5p WHERE id = ?")->execute([(int) $argv[1]]); App\\Core\\H5PArchivio::elimina($argv[2]);', h5p[0], h5p[1]);
                }
            }
        }

        // --- Questionario a domande concluso al 100% (23/10) ---------------
        //
        // Con un tentativo al 100% nel database, un nuovo invio non si salva
        // e la pagina mostra le domande spente.

        console.log('\n--- Questionario a domande concluso al 100%');

        {
            const radice = path.join(__dirname, '..');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const conta = () => php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM quiz_attempts a JOIN users u ON u.id = a.user_id WHERE u.email = \'stud@test.it\' AND a.quiz_id = " . (int) $argv[1])->fetchColumn();', A.quiz).trim();
            const aggiunto = php(`
                $pdo = App\\Core\\Database::connection();
                $u = (int) $pdo->query("SELECT id FROM users WHERE email = 'stud@test.it'")->fetchColumn();
                $pdo->prepare("INSERT INTO quiz_attempts (user_id, quiz_id, score_pct, passed) VALUES (?, ?, 100, 1)")->execute([$u, (int) $argv[1]]);
                echo $pdo->lastInsertId();`, A.quiz).trim();

            try {
                const prima = conta();
                const stud = await entra(browser, 'stud@test.it');
                await esitoPost(stud.page, '/quizzes/' + A.quiz + '/attempts', []);
                const dopo = conta();
                check('studente del mondo A → un nuovo invio dopo il 100%: non salvato', prima === dopo, prima === dopo ? [] : [prima + ' → ' + dopo]);
                await stud.page.goto(BASE + '/quizzes/' + A.quiz);
                const spento = await stud.page.evaluate(() => {
                    const f = document.querySelector('.quiz-concluso-campi');
                    const b = document.querySelector('.quiz-form button[type="submit"]');
                    return (!f || f.disabled) && (!b || b.matches(':disabled')) && document.body.textContent.includes('Superato al 100%: il questionario è concluso.');
                });
                check('studente del mondo A → dopo il 100% le domande sono spente, con l avviso', spento, spento ? [] : ['non spento']);
                await stud.ctx.close();
            } finally {
                php('App\\Core\\Database::connection()->prepare("DELETE FROM quiz_attempts WHERE id = ?")->execute([(int) $argv[1]]);', aggiunto);
            }
        }

        // --- Contenuti H5P nelle lezioni (23/10) ---------------------------
        //
        // Un contenuto nella lezione del mondo A e uno in quella del modulo
        // ancora chiuso. I file si servono a chi puo' aprire la lezione: lo
        // studente A si', ma non quelli della lezione chiusa; lo studente B
        // no. Un indirizzo con la cartella di prima (dopo una sostituzione)
        // non serve niente. Eliminare un contenuto e' di chi modifica il corso.

        console.log('\n--- Contenuti H5P nelle lezioni: file ed eliminazione');

        {
            const radice = path.join(__dirname, '..');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const crea = (lezione) => php(`
                $f = tempnam(sys_get_temp_dir(), "h5p");
                $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
                $z->addFromString("h5p.json", json_encode(["title" => "Fisarmonica", "mainLibrary" => "H5P.Accordion", "preloadedDependencies" => [["machineName" => "H5P.Accordion", "majorVersion" => 1, "minorVersion" => 0]]]));
                $z->addFromString("content/content.json", "{}");
                $z->addFromString("H5P.Accordion-1.0/library.json", "{}");
                $z->close();
                $a = App\\Core\\H5PArchivio::apriPerLezione($f, (int) $argv[1]); unlink($f);
                $id = App\\Models\\LessonH5PModel::create((int) $argv[1], $a, "prova.h5p");
                echo App\\Controllers\\H5PController::percorsoLezione(App\\Models\\LessonH5PModel::find($id)), " ", $id, " ", $a["dir"];`, lezione).trim().split(' ');
            const [aperta, idAperta, dirAperta] = crea(A.lezione);
            const [chiusa, idChiusa, dirChiusa] = crea(A.lezione_chiusa);

            try {
                const stud = await entra(browser, 'stud@test.it');
                let r = await esitoGet(stud.page, aperta + '/content/content.json');
                check('studente del mondo A → un file di un contenuto H5P della sua lezione: consentito', r.esito === 'consentito', r.esito === 'consentito' ? [] : [r.come]);
                r = await esitoGet(stud.page, aperta.replace(/-[0-9a-f]{12}$/, '-000000000000') + '/content/content.json');
                check('studente del mondo A → lo stesso contenuto con la cartella sbagliata: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                r = await esitoGet(stud.page, chiusa + '/content/content.json');
                check('studente del mondo A → un contenuto H5P di un modulo non ancora aperto: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                r = await esitoGet(stud.page, aperta + '/%2e%2e/%2e%2e/config/config.php');
                check('studente del mondo A → «..» fuori dalla cartella del contenuto: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                r = await esitoPost(stud.page, '/lessons/' + A.lezione + '/h5p/' + idAperta + '/delete', []);
                check('studente del mondo A → elimina un contenuto H5P: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                await stud.ctx.close();

                const strano = await entra(browser, 'strano@test.it');
                r = await esitoGet(strano.page, aperta + '/content/content.json');
                check('studente del mondo B → un file di un contenuto H5P del mondo A: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                await strano.ctx.close();

                const tutorB = await entra(browser, 'tutor2@test.it');
                r = await esitoPost(tutorB.page, '/lessons/' + A.lezione + '/h5p/' + idAperta + '/delete', []);
                check('tutor del mondo B → elimina un contenuto H5P del mondo A: negato', r.esito === 'negato', r.esito === 'negato' ? [] : [r.come]);
                await tutorB.ctx.close();

                const restano = php('echo (int) App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM lesson_h5p WHERE id = " . (int) $argv[1])->fetchColumn();', idAperta).trim();
                check('il contenuto c è ancora', restano === '1', restano === '1' ? [] : ['trovati ' + restano]);
            } finally {
                php(`
                    $pdo = App\\Core\\Database::connection();
                    $pdo->prepare("DELETE FROM lesson_h5p WHERE id IN (?, ?)")->execute([(int) $argv[1], (int) $argv[2]]);
                    App\\Core\\H5PArchivio::elimina($argv[3]);
                    App\\Core\\H5PArchivio::elimina($argv[4]);`, idAperta, idChiusa, dirAperta, dirChiusa);
            }
        }

        // --- I file H5P restano sul server, come gli altri (29/10) ----------
        //
        // Come video, materiali e immagini: togliere un contenuto H5P dalla
        // lezione, sostituirlo, eliminare la lezione o il questionario non
        // cancella la sua cartella in storage/h5p, e chi lo fa lo sa. Le
        // cartelle non usate si contano in Gestione corsi. Tutto in un corso
        // di prova, tolto alla fine con le sue cartelle.

        console.log('\n--- I file H5P restano sul server');

        {
            const radice = path.join(__dirname, '..');
            const fs = require('fs');
            const os = require('os');
            const php = (codice, ...argomenti) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";' + codice,
                '--', ...argomenti.map(String)], { cwd: radice, encoding: 'utf8' });
            const esiste = (dir) => fs.existsSync(path.join(radice, 'storage', 'h5p', dir));
            const dirLezione = (id) => php('echo (string) App\\Core\\Database::connection()->query("SELECT h5p_dir FROM lesson_h5p WHERE id = " . (int) $argv[1])->fetchColumn();', id).trim();
            const dirQuiz = (id) => php('echo (string) App\\Core\\Database::connection()->query("SELECT h5p_dir FROM quizzes WHERE id = " . (int) $argv[1])->fetchColumn();', id).trim();
            // Un pacchetto .h5p minimo, sul disco, per i campi «scegli file».
            const pacchetto = (libreria) => {
                const f = path.join(os.tmpdir(), 'prova-' + libreria + '-' + process.pid + '.h5p');
                php(`
                    $z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
                    $z->addFromString("h5p.json", json_encode(["title" => "Prova", "mainLibrary" => $argv[2], "preloadedDependencies" => [["machineName" => $argv[2], "majorVersion" => 1, "minorVersion" => 0]]]));
                    $z->addFromString("content/content.json", "{}");
                    $z->addFromString($argv[2] . "-1.0/library.json", "{}");
                    $z->close();`, f, libreria);
                return f;
            };
            const [corso, lezione, quiz, c1, c2] = php(`
                $pdo = App\\Core\\Database::connection();
                $pdo->prepare("INSERT INTO courses (title, slug, description, is_published, created_by) VALUES (?, ?, '', 0, ?)")->execute(["[prova-permessi] file h5p", "prova-permessi-h5p-" . time(), (int) $argv[1]]);
                $c = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO modules (course_id, title, position) VALUES (?, 'Tappa', 0)")->execute([$c]);
                $m = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO lessons (module_id, title, content_html, position) VALUES (?, 'Lezione con esercizi', '<p>Testo.</p>', 0)")->execute([$m]);
                $l = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO quizzes (module_id, title, passing_score_pct) VALUES (?, 'Questionario H5P', 60)")->execute([$m]);
                $q = (int) $pdo->lastInsertId();
                $f = tempnam(sys_get_temp_dir(), "h5p");
                $ids = [];
                foreach ([1, 2] as $i) {
                    $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
                    $z->addFromString("h5p.json", json_encode(["title" => "Fisarmonica", "mainLibrary" => "H5P.Accordion", "preloadedDependencies" => [["machineName" => "H5P.Accordion", "majorVersion" => 1, "minorVersion" => 0]]]));
                    $z->addFromString("content/content.json", "{}");
                    $z->addFromString("H5P.Accordion-1.0/library.json", "{}");
                    $z->close();
                    $a = App\\Core\\H5PArchivio::apriPerLezione($f, $l);
                    $ids[] = App\\Models\\LessonH5PModel::create($l, $a, "esercizio" . $i . ".h5p");
                }
                $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
                $z->addFromString("h5p.json", json_encode(["title" => "Prova", "mainLibrary" => "H5P.Prova", "preloadedDependencies" => [["machineName" => "H5P.Prova", "majorVersion" => 1, "minorVersion" => 0]]]));
                $z->addFromString("content/content.json", "{}");
                $z->addFromString("H5P.Prova-1.0/library.json", "{}");
                $z->close();
                $a = App\\Core\\H5PArchivio::apri($f, $q);
                App\\Models\\QuizModel::impostaH5P($q, $a["dir"], $a["libreria"], "prova.h5p");
                unlink($f);
                echo $c, " ", $l, " ", $q, " ", $ids[0], " ", $ids[1];`, d.utenti.admin).trim().split(' ');
            const cartelle = [dirLezione(c1), dirLezione(c2), dirQuiz(quiz)];
            const admin = await entra(browser, 'admin@test.it');
            const avviso = (page) => page.evaluate(() => [...document.querySelectorAll('.alert')].map((a) => a.textContent.replace(/\s+/g, ' ').trim()).join(' | '));

            try {
                // 1. Togliere un contenuto dalla lezione. Prima la domanda che
                // il browser fa (annullata), poi il comando.
                await admin.page.goto(BASE + '/lessons/' + lezione + '/edit');
                let domanda = '';
                admin.page.once('dialog', (dialogo) => { domanda = dialogo.message(); dialogo.dismiss(); });
                await admin.page.click('button[form="h5p-elimina-' + c1 + '"]');
                check('admin → la domanda prima di togliere un contenuto H5P dice che i file restano',
                    domanda === 'Togliere «esercizio1.h5p» dalla lezione? I suoi file restano sul server.' && dirLezione(c1) !== '', [domanda]);
                await esitoPost(admin.page, '/lessons/' + lezione + '/h5p/' + c1 + '/delete', [], '/lessons/' + lezione + '/edit');
                await admin.page.goto(BASE + '/lessons/' + lezione + '/edit');
                let a = await avviso(admin.page);
                check('admin → «Rimuovi dalla lezione» di un contenuto H5P: tolto, e la cartella resta sul server, e lo dice',
                    dirLezione(c1) === '' && esiste(cartelle[0]) && a.includes('tolto dalla lezione. I suoi file restano sul server, in storage/h5p/' + cartelle[0]), [a]);
                const comando = await admin.page.evaluate(() => [...document.querySelectorAll('.h5p-lezione-comandi button')].map((b) => b.textContent.trim()));
                check('admin → nella lezione il comando è «Rimuovi dalla lezione», non «Elimina»', comando.length === 1 && comando[0] === 'Rimuovi dalla lezione', [JSON.stringify(comando)]);
                const scollegati = await admin.page.evaluate(() => (document.body.textContent.match(/ci sono (\d+) file caricati che nessun\s+contenuto usa più/) || [])[1] || '0');
                check('admin → nella lezione la cartella tolta si conta fra i file che nessun contenuto usa più', Number(scollegati) >= 3, [scollegati]);

                // 2. Sostituire il contenuto rimasto, dal modulo della lezione.
                await admin.page.setInputFiles('#h5p-sostituisci-' + c2, pacchetto('H5P.Accordion'));
                await admin.page.click('.form-actions button[type="submit"]');
                await admin.page.waitForLoadState('networkidle');
                const nuova = dirLezione(c2);
                check('admin → sostituito un contenuto della lezione, quello di prima resta sul server',
                    nuova !== '' && nuova !== cartelle[1] && esiste(cartelle[1]), [nuova, cartelle[1]]);
                cartelle.push(nuova);

                // 3. Sostituire il contenuto del questionario.
                await admin.page.goto(BASE + '/quizzes/' + quiz + '/edit');
                await admin.page.setInputFiles('#h5p', pacchetto('H5P.Prova'));
                await admin.page.click('form button[type="submit"].btn-primary');
                await admin.page.waitForLoadState('networkidle');
                const nuovaQ = dirQuiz(quiz);
                a = await avviso(admin.page);
                check('admin → sostituito il contenuto del questionario, quello di prima resta sul server, e lo dice',
                    nuovaQ !== cartelle[2] && esiste(cartelle[2]) && a.includes('Quello di prima resta sul server, in storage/h5p/' + cartelle[2]), [a]);
                cartelle.push(nuovaQ);

                // 4. Eliminare il questionario e la lezione.
                await esitoPost(admin.page, '/quizzes/' + quiz + '/delete', [], '/quizzes/' + quiz + '/edit');
                await admin.page.goto(BASE + '/courses/' + corso);
                a = await avviso(admin.page);
                check('admin → eliminato il questionario, il suo contenuto H5P resta sul server, e lo dice',
                    esiste(nuovaQ) && a.includes('restano sul server, in storage/h5p/' + nuovaQ), [a]);
                await esitoPost(admin.page, '/lessons/' + lezione + '/delete', [], '/lessons/' + lezione + '/edit');
                await admin.page.goto(BASE + '/courses/' + corso);
                a = await avviso(admin.page);
                check('admin → eliminata la lezione, i suoi contenuti H5P restano sul server, e lo dice',
                    esiste(nuova) && a.includes('storage/h5p/' + nuova), [a]);

                // 5. In Gestione corsi si contano.
                await admin.page.goto(BASE + '/admin/courses');
                const riga = await admin.page.evaluate(() => {
                    const r = [...document.querySelectorAll('.orphan-summary tr')].find((x) => x.textContent.includes('storage/h5p'));
                    return r ? Number(r.querySelectorAll('td')[1].textContent) : 0;
                });
                check('admin → in Gestione corsi le cartelle H5P non usate si contano, nella riga storage/h5p', riga >= cartelle.length, ['file: ' + riga]);
            } finally {
                await admin.ctx.close();
                php(`
                    App\\Core\\Database::connection()->prepare("DELETE FROM courses WHERE id = ?")->execute([(int) $argv[1]]);
                    foreach (array_slice($argv, 2) as $d) { App\\Core\\H5PArchivio::elimina($d); }`, corso, ...cartelle.filter((x) => x));
            }
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
                    ['assist@test.it', 'assistente del tutor A', false],
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

        // Un tutor che nel gruppo e' solo membro (17/10): i nomi interi li
        // vede il tutor **di quel gruppo**, non chiunque abbia il ruolo. Il
        // tutor B diventa membro del gruppo «cerchio» per la durata della
        // prova, e alla fine si toglie.
        {
            const radice = path.join(__dirname, '..');
            const sql = (q, ...valori) => execFileSync('php', ['-r',
                'require "vendor/autoload.php"; require "config/config.php";'
                + ' App\\Core\\Database::connection()->prepare($argv[1])->execute(array_slice($argv, 2));',
                '--', q, ...valori.map(String)], { cwd: radice, encoding: 'utf8' });
            const membro = () => [d.gruppo_cerchio, d.utenti.tutorB];
            // Nell'archivio della semina il compagno 5 (iniziali) non ha
            // risposte: senza questa, la prova sulle domande passate sarebbe
            // verde per il motivo sbagliato. Si aggiunge alla domanda piu'
            // vecchia del gruppo, che e' in archivio, e si toglie alla fine.
            const rispostaDiProva = 'Risposta di prova del compagno 5 (permessi.js)';

            sql('INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)', ...membro());
            sql('INSERT INTO group_answers (question_id, user_id, answer)'
                + ' SELECT (SELECT MIN(id) FROM group_questions WHERE group_id = ?), id, ? FROM users WHERE email = ?',
            d.gruppo_cerchio, rispostaDiProva, 'compagno5@test.it');

            try {
                {
                    const tutore = await entra(browser, 'tutor1@test.it');
                    await tutore.page.goto(BASE + '/gruppi/' + d.gruppo_cerchio + '/domande-passate');
                    const suo = await tutore.page.content();
                    const ok = suo.includes(rispostaDiProva) && suo.includes('Compagno 5');
                    check('controprova: nelle domande passate c\'è la risposta del compagno 5, e il tutor del gruppo la vede per intero',
                        ok, ok ? [] : ['senza, la prova sull\'archivio non direbbe niente']);
                    await tutore.ctx.close();
                }

                const { ctx, page } = await entra(browser, 'tutor2@test.it');
                await page.goto(BASE + '/gruppi/' + d.gruppo_cerchio);
                const nomi = await page.$$eval('.gruppo-cerchio .persona-nome', (ns) => ns.map((n) => n.childNodes[0].textContent.trim()));
                const html = await page.content();
                check('tutor B, solo membro del gruppo → vede le iniziali e il solo nome come li hanno scelti',
                    nomi.includes('C. 5.') && !nomi.includes('Compagno 6'), [JSON.stringify(nomi)]);
                check('tutor B, solo membro del gruppo → il nome intero di chi ha scelto le iniziali non c\'è nell\'HTML',
                    !html.includes('Compagno 5'), html.includes('Compagno 5') ? ['trovato «Compagno 5»'] : []);

                await page.goto(BASE + '/gruppi/' + d.gruppo_cerchio + '/domande-passate');
                const archivio = await page.content();
                const archivioOk = archivio.includes(rispostaDiProva) && !archivio.includes('Compagno 5');
                check('tutor B, solo membro del gruppo → nemmeno nelle domande passate', archivioOk,
                    archivioOk ? [] : [archivio.includes(rispostaDiProva) ? 'trovato «Compagno 5»' : 'la risposta di prova non c\'è']);
                await ctx.close();
            } finally {
                sql('DELETE FROM group_answers WHERE answer = ?', rispostaDiProva);
                sql('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', ...membro());
            }
        }

        // --- la pagina del gruppo quando non c'e' niente in programma -------
        //
        // Variante A del mockup (17/10, Alessandro): il riquadro dell'incontro
        // e quello del giro del cerchio ci sono sempre, con una frase quando
        // sono vuoti, cosi' la pagina non si svuota dopo un incontro. Il
        // gruppo «cerchio» della semina non ha incontri in programma ma ha
        // una domanda aperta; quello del mondo A ha l'incontro e nessuna
        // domanda.

        console.log('\n--- la pagina del gruppo quando non c\'è niente in programma');

        {
            const NESSUN_INCONTRO = 'Nessun incontro in programma, per ora.';
            const NESSUNA_DOMANDA = 'Il tutor non ha ancora fatto una domanda al gruppo.';
            const riquadri = async (page, gruppo) => {
                await page.goto(BASE + '/gruppi/' + gruppo);
                return await page.evaluate(() => {
                    const testo = (s) => { const e = document.querySelector(s); return e === null ? null : e.textContent.replace(/\s+/g, ' ').trim(); };
                    // Il titolo di ciascun riquadro (17/10, variante A): un h2
                    // vero, a 16 px seminero come i titoli dei riquadri del
                    // profilo, ed e' lui a dare il nome al riquadro.
                    const titolo = (s) => {
                        const sezione = document.querySelector(s);
                        const h = sezione === null ? null : sezione.querySelector('h2');
                        if (h === null) {
                            return null;
                        }
                        const st = getComputedStyle(h);
                        const nome = document.getElementById(sezione.getAttribute('aria-labelledby') || '');
                        return h.textContent.trim() + ' · ' + st.fontSize + ' ' + st.fontWeight + ' · ' + (nome === h ? 'dà il nome' : 'non dà il nome');
                    };
                    return {
                        incontro: testo('.gruppo-incontro'), giro: testo('.giro'),
                        modulo: document.querySelector('.giro textarea[name="question"]') !== null,
                        titoli: [titolo('.gruppo-incontro'), titolo('.giro')],
                    };
                });
            };
            const TITOLI = ['Prossimo incontro del gruppo · 16px 600 · dà il nome', 'Il giro del cerchio · 16px 600 · dà il nome'];
            const titoliGiusti = (chi, r) => check(chi + ' → «Prossimo incontro del gruppo» e «Il giro del cerchio» sono titoli veri da 16 px seminero',
                JSON.stringify(r.titoli) === JSON.stringify(TITOLI), ['misurato ' + JSON.stringify(r.titoli)]);

            const studente = await entra(browser, 'stud@test.it');
            let r = await riquadri(studente.page, d.gruppo_cerchio);
            titoliGiusti('studente, senza incontro e con la domanda', r);
            check('studente → senza incontri in programma il riquadro dell\'incontro c\'è, e lo dice',
                r.incontro !== null && r.incontro.includes(NESSUN_INCONTRO), [JSON.stringify(r)]);
            check('studente → con una domanda aperta il giro mostra la domanda, non la frase del vuoto',
                r.giro !== null && !r.giro.includes(NESSUNA_DOMANDA), [JSON.stringify(r)]);

            r = await riquadri(studente.page, A.gruppo);
            titoliGiusti('studente, con l\'incontro e senza domanda', r);
            check('studente → senza una domanda il riquadro del giro del cerchio c\'è, e lo dice',
                r.giro !== null && r.giro.includes(NESSUNA_DOMANDA) && !r.modulo, [JSON.stringify(r)]);
            check('studente → con un incontro in programma il riquadro mostra l\'incontro, non la frase del vuoto',
                r.incontro !== null && !r.incontro.includes(NESSUN_INCONTRO), [JSON.stringify(r)]);
            await studente.ctx.close();

            const tutore = await entra(browser, 'tutor1@test.it');
            r = await riquadri(tutore.page, A.gruppo);
            titoliGiusti('tutor del gruppo, senza domanda', r);
            check('tutor del gruppo → senza una domanda vede il modulo per farla, non la frase dello studente',
                r.giro !== null && r.modulo && !r.giro.includes(NESSUNA_DOMANDA), [JSON.stringify(r)]);
            await tutore.ctx.close();
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
            // Il tutor non ha «Domande», e ha «L'esperto risponde»: dal 17/10
            // dentro il corso, nel ramo del corso (variante C della sidebar).
            const { ctx, page } = await entra(browser, 'tutor1@test.it');
            await page.goto(BASE + '/courses/' + A.corso);
            const voci = await page.$$eval('.sidebar-nav a.nav-link', (a) => a.map((x) => x.textContent.trim()));
            check('tutor → dentro il corso, nel menu «L\'esperto risponde» e non «Domande»',
                voci.includes('L\'esperto risponde') && !voci.some((v) => v.startsWith('Domande')), [JSON.stringify(voci)]);
            await ctx.close();
        }

        // --- l'esperto risponde come la Bacheca (25/10, conflitto 4) --------
        //
        // La risposta e' di tutti, la persona e' della classe: chi non e' della
        // classe di chi ha chiesto legge «uno studente del corso». Una domanda
        // su un modulo chiuso per chi legge non c'e', nemmeno per chi l'ha
        // fatta; lo staff la legge. Si chiede solo sui moduli aperti.

        console.log('\n--- l\'esperto risponde: nomi della classe e moduli aperti');

        {
            const ANONIMO = 'Chiesto da uno studente del corso';
            const chi = (page) => page.$$eval('.qa-voce', (v) => Object.fromEntries(v.map((x) => [
                x.querySelector('summary').textContent.trim(),
                (x.querySelector('.qa-chi') || { textContent: '' }).textContent.split('·')[0].trim(),
            ])));
            const voce = (righe, parola) => Object.entries(righe).find(([q]) => q.includes(parola));
            const CASI = [
                ['stud@test.it', 'studente A', [
                    ['altra classe', ANONIMO, true, 'la domanda dell\'altra classe è senza nome'],
                    ['pubblicata sul modulo del mondo A', ANONIMO, false, 'controprova: la sua ha il suo nome'],
                    ['modulo chiuso', null, null, 'la domanda sul modulo chiuso non c\'è, anche se è sua'],
                ]],
                ['altrogruppo@test.it', 'studente dell\'altra classe', [
                    ['pubblicata sul modulo del mondo A', ANONIMO, true, 'la domanda dello studente di A è senza nome'],
                    ['altra classe', ANONIMO, false, 'controprova: la sua ha il suo nome'],
                    ['modulo chiuso', null, null, 'la domanda sul modulo chiuso non c\'è'],
                ]],
                ['tutor1@test.it', 'tutor A', [
                    ['pubblicata sul modulo del mondo A', ANONIMO, false, 'il nome dello studente del suo gruppo'],
                    ['altra classe', ANONIMO, true, 'non il nome dello studente di un\'altra classe'],
                    ['modulo chiuso', ANONIMO, false, 'lo staff legge anche il modulo chiuso, con il nome del suo studente'],
                ]],
                ['tutor3@test.it', 'tutor dell\'altra classe', [
                    ['altra classe', ANONIMO, false, 'il nome dello studente del suo gruppo'],
                    ['pubblicata sul modulo del mondo A', ANONIMO, true, 'non il nome dello studente di A'],
                ]],
            ];
            for (const [email, nome, prove] of CASI) {
                const { ctx, page } = await entra(browser, email);
                await page.goto(BASE + '/domande-e-risposte?corso=' + A.corso);
                const righe = await chi(page);
                for (const [parola, testo, uguale, perche] of prove) {
                    const v = voce(righe, parola);
                    const ok = testo === null ? v === undefined : (v !== undefined && (v[1] === testo) === uguale);
                    check(nome + ' → «' + parola + '»: ' + perche, ok, ok ? [] : [JSON.stringify(righe)]);
                }
                await ctx.close();
            }
        }
        {
            const { ctx, page } = await entra(browser, 'stud@test.it');
            await page.goto(BASE + '/courses/' + A.corso);
            const scelte = await page.$$eval('#qa-modulo option', (o) => o.map((x) => x.value));
            check('studente A → per chiedere, il modulo chiuso non si offre (e il suo aperto sì)',
                !scelte.includes(String(A.modulo_chiuso)) && scelte.includes(String(A.modulo)), [JSON.stringify(scelte)]);
            const token = await page.$eval('#domande input[name="_token"]', (i) => i.value);
            const TESTO = 'Domanda scritta a mano sul modulo chiuso?';
            await page.evaluate(async ([u, t, m, q]) => {
                const d = new URLSearchParams({ _token: t, module_id: m, question: q });
                await fetch(u, { method: 'POST', body: d, credentials: 'same-origin', redirect: 'manual' });
            }, ['/courses/' + A.corso + '/domande', token, String(A.modulo_chiuso), TESTO]);
            await page.goto(BASE + '/courses/' + A.corso);
            const mie = await page.$$eval('.qa-mie-testo', (t) => t.map((x) => x.textContent.trim()));
            check('studente A → una domanda sul modulo chiuso scritta a mano non si salva', !mie.includes(TESTO), [JSON.stringify(mie)]);
            await ctx.close();
        }

        // --- le date dei moduli per gruppo (26/10, conflitto 3) -------------
        //
        // Il modulo «con le date del gruppo» per il corso si apre fra trenta
        // giorni, per l'altra classe si e' aperto ieri, per il gruppo di A si
        // apre fra venti. La data dello studente vale ovunque: l'ingresso,
        // la pagina del corso, l'agenda, il riepilogo dei corsi e le email
        // dell'apertura. Le date le scrive chi gestisce il gruppo.

        console.log('\n--- le date dei moduli per gruppo');

        {
            const D = (g, c) => '/admin/groups/' + g + '/courses/' + c + '/date';
            const PORTE = [
                ['stud@test.it', 'studente A', [
                    ['/lessons/' + A.lezione_gruppo, 'negato', 'per il suo gruppo il modulo si apre fra venti giorni'],
                    [D(A.gruppo, A.corso), 'negato', 'le date del gruppo non sono sue da scrivere'],
                ]],
                ['altrogruppo@test.it', 'studente dell\'altra classe', [
                    ['/lessons/' + A.lezione_gruppo, 'consentito', 'per il suo gruppo il modulo si è aperto ieri, anche se per il corso no'],
                    ['/lessons/' + A.lezione_chiusa, 'negato', 'controprova: dove il suo gruppo non ha date vale quella del corso'],
                ]],
                ['tutor1@test.it', 'tutor A', [
                    [D(A.gruppo, A.corso), 'consentito', 'le date del suo gruppo'],
                    [D(A.altra_classe, A.corso), 'negato', 'non quelle del gruppo di un collega'],
                    [D(A.gruppo, B.corso), 'negato', 'non per un corso che il gruppo non ha'],
                ]],
                ['tutor3@test.it', 'tutor dell\'altra classe', [
                    [D(A.altra_classe, A.corso), 'consentito', 'le date del suo gruppo'],
                    [D(A.gruppo, A.corso), 'negato', 'non quelle del gruppo di un collega'],
                ]],
            ];
            for (const [email, nome, prove] of PORTE) {
                const { ctx, page } = await entra(browser, email);
                for (const [url, atteso, perche] of prove) {
                    const r = await esitoGet(page, url);
                    check(nome + ' → ' + url + ': ' + atteso, r.esito === atteso,
                        r.esito === atteso ? [] : ['ottenuto «' + r.esito + '» (' + r.come + ') — ' + perche]);
                }
                if (email === 'stud@test.it') {
                    const [y, m, g] = A.data_gruppo_A.split('-').map(Number);
                    const attesa = g + '/' + m + '/' + y;
                    await page.goto(BASE + '/courses/' + A.corso + '?moduli=tutti');
                    const testo = await page.locator('#modulo-' + A.modulo_gruppo).innerText();
                    check('studente A → nella pagina del corso il modulo si apre alla data del suo gruppo, non del corso',
                        testo.includes(attesa), ['cercato «' + attesa + '»']);
                }
                if (email === 'tutor3@test.it') {
                    const negato = await esitoPost(page, D(A.gruppo, A.corso), [['date[' + A.modulo_gruppo + ']', '']], D(A.altra_classe, A.corso));
                    check('tutor dell\'altra classe → non salva le date del gruppo di un collega', negato.esito === 'negato', [negato.come]);
                }
                await ctx.close();
            }

            // Le altre tre strade della stessa data, lette dai modelli: le
            // email dell'apertura, l'agenda e il riepilogo dei corsi.
            const radice = path.join(__dirname, '..');
            const codice = `require 'vendor/autoload.php';
                $p = array_values(array_filter(App\\Models\\ModuleUnlockModel::pending(5000), fn ($r) => (int) $r['module_id'] === ${A.modulo_gruppo}));
                $agenda = null;
                foreach (App\\Models\\AgendaModel::apertureDi(${d.utenti.studenteA}) as $r) { if ((int) $r['id'] === ${A.modulo_gruppo}) { $agenda = substr((string) $r['available_from'], 0, 10); } }
                echo json_encode(['email' => array_map('intval', array_column($p, 'user_id')), 'agenda' => $agenda,
                    'prossima' => substr((string) (App\\Models\\LessonProgressModel::availabilitySummary(${d.utenti.studenteA})[${A.corso}]['prossima'] ?? ''), 0, 10)]);`;
            const m = JSON.parse(execFileSync('php', ['-r', codice], { encoding: 'utf8', cwd: radice }));
            check('email dell\'apertura → allo studente dell\'altra classe, per cui il modulo si è aperto ieri, e non allo studente di A',
                m.email.includes(d.utenti.altroGruppo) && !m.email.includes(d.utenti.studenteA), [JSON.stringify(m)]);
            check('agenda dello studente A → l\'apertura alla data del suo gruppo', m.agenda === A.data_gruppo_A, [JSON.stringify(m)]);
            check('riepilogo dei corsi dello studente A → la prossima apertura è quella del suo gruppo', m.prossima === A.data_gruppo_A, [JSON.stringify(m)]);

            // Salvare e togliere una data: si scrive, si rilegge, si toglie.
            const { ctx, page } = await entra(browser, 'admin@test.it');
            const input = 'input[name="date[' + A.modulo_dopo + ']"]';
            await page.goto(BASE + D(A.gruppo, A.corso));
            await page.fill(input, '2031-03-15T09:00');
            await Promise.all([page.waitForNavigation(), page.click('.main-content form button[type=submit]')]);
            await page.goto(BASE + D(A.gruppo, A.corso));
            const salvata = await page.inputValue(input);
            await page.fill(input, '');
            await Promise.all([page.waitForNavigation(), page.click('.main-content form button[type=submit]')]);
            await page.goto(BASE + D(A.gruppo, A.corso));
            const tolta = await page.inputValue(input);
            const restaLaltra = await page.inputValue('input[name="date[' + A.modulo_gruppo + ']"]');
            check('admin → una data del gruppo si salva e si toglie, e le altre restano',
                salvata === '2031-03-15T09:00' && tolta === '' && restaLaltra !== '', [JSON.stringify({ salvata, tolta, restaLaltra })]);
            await ctx.close();
        }

        // --- tutor e assistente: il perimetro sul corso (26/10) --------------
        //
        // Conflitti 2, 4 e 5 della verifica su tutor e assistente, e il testo
        // sbagliato. Un incontro con un gruppo e' del tutor di quel gruppo,
        // anche se ha un modulo; di uno studente il tutor vede i corsi che
        // fa con lui (il corso C, senza gruppi, no); i numeri dei report
        // contano solo i suoi studenti.

        console.log('\n--- tutor e assistente: il perimetro sul corso');

        {
            const C = d.C;
            const PORTE = [
                ['tutor3@test.it', 'tutor dell\'altra classe', [
                    ['/live/' + A.incontro, 'negato', 'l\'incontro del gruppo di un\'altra classe dello stesso corso'],
                    ['/live/' + A.incontro + '/edit', 'negato', 'né modificarlo'],
                    ['/reports/live/' + A.incontro, 'negato', 'né nei report'],
                    ['/live/' + A.incontro_corso, 'negato', 'nemmeno l\'incontro di tutto il corso: lo gestisce l\'admin'],
                ]],
                ['tutor1@test.it', 'tutor A', [
                    ['/live/' + A.incontro + '/edit', 'consentito', 'controprova: l\'incontro del suo gruppo lo modifica'],
                    ['/attempts/' + C.tentativo, 'negato', 'il tentativo del suo studente in un corso che non segue'],
                    ['/certificates/' + C.certificato + '/download', 'negato', 'il certificato del suo studente in un corso che non segue'],
                    ['/attempts/' + A.tentativo, 'consentito', 'controprova: il tentativo nel corso A sì'],
                ]],
                ['admin@test.it', 'amministratore', [
                    ['/attempts/' + C.tentativo, 'consentito', 'controprova: l\'admin vede tutto'],
                ]],
            ];
            for (const [email, nome, prove] of PORTE) {
                const { ctx, page } = await entra(browser, email);
                for (const [url, atteso, perche] of prove) {
                    const r = await esitoGet(page, url);
                    check(nome + ' → ' + url + ': ' + atteso, r.esito === atteso,
                        r.esito === atteso ? [] : ['ottenuto «' + r.esito + '» (' + r.come + ') — ' + perche]);
                }
                if (email === 'tutor3@test.it') {
                    await page.goto(BASE + '/live');
                    const html = await page.content();
                    check('tutor dell\'altra classe → fra le sessioni live non c\'è l\'incontro del gruppo A né quello di tutto il corso',
                        !html.includes('/live/' + A.incontro + '"') && !html.includes('/live/' + A.incontro_corso + '"'), []);
                    await page.goto(BASE + '/reports/elenco/courses');
                    const riga = await page.locator('tr', { hasText: '[prova-permessi] mondo A' }).first().innerText();
                    const iscritti = riga.split('\t').map((x) => x.trim())[1];
                    check('tutor dell\'altra classe → nei report «Corsi» il corso A conta solo il suo studente', iscritti === '1', [riga]);
                    await page.goto(BASE + '/reports/elenco/live');
                    const live = await page.content();
                    check('tutor dell\'altra classe → nei report «Incontri» non c\'è l\'incontro del gruppo A',
                        !live.includes('/reports/live/' + A.incontro + '"'), []);
                    await page.goto(BASE + '/reports');
                    const avviso = await page.locator('.page-header').innerText();
                    check('tutor → in Report l\'avviso dice «dei gruppi che segui»',
                        avviso.includes('dei gruppi che segui') && !avviso.includes('tutor di riferimento'), [avviso]);
                }
                if (email === 'tutor1@test.it') {
                    await page.goto(BASE + '/reports/students/' + d.utenti.studenteA);
                    const testo = await page.locator('.main-content').innerText();
                    check('tutor A → nel report del suo studente il corso A c\'è e il corso senza gruppi no',
                        testo.includes('[prova-permessi] mondo A') && !testo.includes('corso senza gruppi'), []);
                    await page.goto(BASE + '/certificates');
                    const cert = await page.locator('.main-content').innerText();
                    check('tutor A → fra i certificati non c\'è quello del corso senza gruppi', !cert.includes('corso senza gruppi'), []);
                }
                await ctx.close();
            }
            const { ctx, page } = await entra(browser, 'assist@test.it');
            await page.goto(BASE + '/reports');
            const avviso = await page.locator('.page-header').innerText();
            check('assistente → in Report l\'avviso dice «dal tuo tutor di riferimento»', avviso.includes('tutor di riferimento'), [avviso]);
            await ctx.close();
        }

        // --- tutor e assistente: corso, iscritti, incontri, esperto (26/10) ---
        //
        // Conflitti 1, 2, 3, 6 e 7, con le correzioni di Alessandro: i
        // contenuti e le iscrizioni del corso sono dell'admin; il tutor vede
        // in «Gestione corsi» i corsi dei suoi gruppi, con il suo benvenuto e
        // i suoi studenti in sola lettura; vede e crea solo incontri dei suoi
        // gruppi. L'assistente vede gli incontri dei gruppi del suo tutor in
        // sola lettura e legge «L'esperto risponde» senza nomi.

        console.log('\n--- tutor e assistente: corso, iscritti, incontri, esperto');

        {
            let { ctx, page } = await entra(browser, 'tutor1@test.it');
            await page.goto(BASE + '/courses/' + A.corso + '?moduli=tutti');
            const modifiche = await page.$$eval('.main-content a[href$="/edit"], .main-content a[href*="/modules/create"]', (a) => a.map((x) => x.getAttribute('href')));
            check('tutor A → nella pagina del corso dei suoi gruppi nessun comando di modifica', modifiche.length === 0, [JSON.stringify(modifiche)]);
            await page.goto(BASE + '/admin/courses');
            const elenco = await page.locator('.main-content').innerText();
            check('tutor A → in «Gestione corsi» solo i corsi dei suoi gruppi',
                elenco.includes('[prova-permessi] mondo A') && !elenco.includes('[prova-permessi] mondo B') && !elenco.includes('File sul server'), []);
            await page.goto(BASE + '/admin/courses/' + A.corso + '/edit');
            const scheda = await page.locator('.main-content').innerText();
            const iscritti = await page.$$eval('.main-content td[data-label="Studente"]', (t) => t.map((x) => x.textContent));
            check('tutor A → nella pagina del corso il suo benvenuto e solo i suoi studenti, senza dati del corso',
                scheda.includes('Il tuo benvenuto') && !scheda.includes('Dati del corso') && !scheda.includes('Copertina')
                    && iscritti.some((x) => x.includes('stud@test.it')) && !iscritti.some((x) => x.includes('altrogruppo@test.it')),
                [JSON.stringify(iscritti)]);
            const comandi = await page.$$eval('.main-content form[action*="/enrollments"]', (f) => f.length);
            check('tutor A → non iscrive e non toglie studenti dal corso', comandi === 0, ['moduli: ' + comandi]);
            for (const [url, campi, perche] of [
                ['/admin/courses/' + A.corso + '/enrollments', [['user_id', String(d.utenti.studenteNuovo)]], 'iscrivere a mano è dell\'admin'],
                ['/admin/courses/' + A.corso + '/enrollments/' + d.utenti.studenteA + '/delete', [], 'togliere uno studente dal corso è dell\'admin'],
            ]) {
                const r = await esitoPost(page, url, campi, '/admin/courses/' + A.corso + '/edit');
                check('tutor A → POST ' + url + ': negato', r.esito === 'negato', r.esito === 'negato' ? [] : ['ottenuto «' + r.esito + '» — ' + perche]);
            }
            await page.goto(BASE + '/live/create');
            const gruppo = await page.$eval('#group_id', (g) => ({ obbligatorio: g.required, nessuno: [...g.options].some((o) => o.value === '0') }));
            check('tutor A → nella nuova sessione il gruppo è obbligatorio', gruppo.obbligatorio && !gruppo.nessuno, [JSON.stringify(gruppo)]);
            const TITOLO = '[prova-permessi] incontro di tutto il corso scritto a mano';
            const token = await page.$eval('input[name="_token"]', (i) => i.value);
            await page.evaluate(async ([t, m, titolo]) => {
                const dati = new URLSearchParams({ _token: t, title: titolo, starts_at: '2031-01-10T10:00', ends_at: '2031-01-10T11:00', module_id: m, group_id: '0' });
                await fetch('/live', { method: 'POST', body: dati, credentials: 'same-origin', redirect: 'manual' });
            }, [token, String(A.modulo), TITOLO]);
            await ctx.close();
            ({ ctx, page } = await entra(browser, 'admin@test.it'));
            await page.goto(BASE + '/live');
            check('tutor A → un incontro di tutto il corso scritto a mano non si salva', !(await page.content()).includes(TITOLO), []);
            await ctx.close();

            ({ ctx, page } = await entra(browser, 'assist@test.it'));
            await page.goto(BASE + '/live');
            const lista = await page.content();
            check('assistente → fra le sessioni live gli incontri del gruppo del suo tutor, senza «Nuova sessione» né «Modifica»',
                lista.includes('/live/' + A.incontro_imminente + '/join') && !lista.includes('/live/create') && !lista.includes('/edit"'), []);
            await page.goto(BASE + '/live/' + A.incontro_imminente);
            const pagina = await page.locator('.main-content').innerText();
            check('assistente → nella pagina dell\'incontro le presenze, senza «Segna presente» né «Elimina»',
                pagina.includes('Presenze') && !pagina.includes('Segna presente') && !pagina.includes('Elimina sessione') && !pagina.includes('Invia inviti'), []);
            await page.goto(BASE + '/domande-e-risposte?corso=' + A.corso);
            const chi = await page.$$eval('.qa-chi', (p) => p.map((x) => x.textContent.trim()).filter((t) => t.startsWith('Chiesto da')));
            check('assistente → legge «L\'esperto risponde» del corso del suo tutor, senza i nomi',
                chi.length > 0 && chi.every((t) => t.startsWith('Chiesto da uno studente del corso')), [JSON.stringify(chi)]);
            await ctx.close();
        }

        // --- il ramo del corso nella sidebar (17/10, variante C) -------------
        //
        // Bacheca e «L'esperto risponde» compaiono solo dentro un corso, in un
        // ramo subito sotto «I miei corsi» (o «Corsi»): il nome del corso e le
        // sue voci, con il corso nell'indirizzo. Fuori dai corsi non ci sono.
        // Il ramo e' quello del corso della pagina, e le voci quelle che chi
        // guarda puo' aprire.
        console.log('\n--- il ramo del corso nella sidebar');
        {
            const ramo = (page) => page.evaluate(() => {
                const nav = document.querySelector('.sidebar-nav');
                const r = nav.querySelector('.nav-corso');
                const testo = (a) => a.childNodes[0].textContent.trim();
                const prima = r ? r.previousElementSibling : null;
                return {
                    ramo: r === null ? null : {
                        nome: testo(r.querySelector('.nav-corso-nome')),
                        corso: r.querySelector('.nav-corso-nome').getAttribute('href'),
                        voci: [...r.querySelectorAll('.nav-corso-voce')].map((a) => [testo(a), a.getAttribute('href'),
                            a.classList.contains('nav-link-active'), (a.querySelector('.nav-conteggio') || { firstChild: { textContent: '' } }).firstChild.textContent]),
                        sotto: prima === null ? null : prima.textContent.trim(),
                    },
                    fuori: [...nav.querySelectorAll('a.nav-link')].filter((a) => !a.closest('.nav-corso')).map((a) => a.textContent.trim().split(/\s+/)[0]),
                };
            });
            const B_ = (id) => '/bacheca?corso=' + id;
            const E_ = (id) => '/domande-e-risposte?corso=' + id;
            // «Il mio gruppo» (18/10): il gruppo dello studente in quel corso, con il
            // corso nell'indirizzo, cosi' sulla pagina del gruppo il ramo resta.
            const G_ = (id) => '/gruppi/' + A.gruppo + '?corso=' + id;
            const CASI = [
                ['stud@test.it', 'studente', [
                    ['/', null, 'la home: nessun ramo'],
                    ['/agenda', null, 'l\'agenda: nessun ramo'],
                    ['/certificates', null, 'i certificati: nessun ramo'],
                    ['/courses/' + A.corso, [A.corso, 'I miei corsi', [['Bacheca', B_(A.corso), false], ['L\'esperto risponde', E_(A.corso), false], ['Il mio gruppo', G_(A.corso), false]]], 'la pagina del corso'],
                    ['/lessons/' + A.lezione, [A.corso, 'I miei corsi', [['Bacheca', B_(A.corso), false], ['L\'esperto risponde', E_(A.corso), false], ['Il mio gruppo', G_(A.corso), false]]], 'una lezione del corso'],
                    ['/quizzes/' + A.quiz, [A.corso, 'I miei corsi', [['Bacheca', B_(A.corso), false], ['L\'esperto risponde', E_(A.corso), false], ['Il mio gruppo', G_(A.corso), false]]], 'il quiz del corso'],
                    ['/bacheca/categorie/' + A.bacheca_categoria, [A.corso, 'I miei corsi', [['Bacheca', B_(A.corso), true], ['L\'esperto risponde', E_(A.corso), false], ['Il mio gruppo', G_(A.corso), false]]], 'una categoria della bacheca: Bacheca evidenziata'],
                    [E_(A.corso), [A.corso, 'I miei corsi', [['Bacheca', B_(A.corso), false], ['L\'esperto risponde', E_(A.corso), true], ['Il mio gruppo', G_(A.corso), false]]], '«L\'esperto risponde»: evidenziata'],
                    [G_(A.corso), [A.corso, 'I miei corsi', [['Bacheca', B_(A.corso), false], ['L\'esperto risponde', E_(A.corso), false], ['Il mio gruppo', G_(A.corso), true]]], 'la pagina del gruppo, arrivando dal ramo: «Il mio gruppo» evidenziata'],
                    ['/gruppi/' + A.gruppo, null, 'la pagina del gruppo senza il corso nell\'indirizzo: nessun ramo'],
                    ['/gruppi/' + A.gruppo + '?corso=' + B.corso, null, 'con un corso che non è del gruppo: nessun ramo'],
                ]],
                ['tutor1@test.it', 'tutor A', [
                    ['/admin/courses', null, 'Gestione corsi: un elenco, nessun ramo'],
                    [B_(A.corso), [A.corso, 'Corsi', [['Bacheca', B_(A.corso), true, '1'], ['L\'esperto risponde', E_(A.corso), false]]], 'la bacheca, con la proposta da valutare sulla voce'],
                    ['/admin/courses/' + A.corso + '/edit', [A.corso, 'Corsi', [['Bacheca', B_(A.corso), false, '1'], ['L\'esperto risponde', E_(A.corso), false]]], 'Modifica corso'],
                ]],
                ['assist@test.it', 'assistente', [
                    [B_(A.corso), [A.corso, 'Corsi', [['Bacheca', B_(A.corso), true], ['L\'esperto risponde', E_(A.corso), false]]], 'la bacheca che legge, e dal 26/10 «L\'esperto risponde», come il suo tutor'],
                ]],
                ['admin@test.it', 'admin', [
                    ['/admin/courses/' + B.corso + '/edit', [B.corso, 'Corsi', [['Bacheca', B_(B.corso), false, '1']]], 'Modifica corso: la Bacheca, e la proposta di B da valutare; «L\'esperto risponde» no, ha «Domande»'],
                    ['/reports/courses/' + B.corso, [B.corso, 'Corsi', [['Bacheca', B_(B.corso), false, '1']]], 'il report del corso'],
                    ['/reports', null, 'i report: un elenco, nessun ramo'],
                ]],
            ];
            // Fuori dai corsi il numero delle proposte si vede sulla scheda del
            // corso che le aspetta, in «Corsi».
            for (const [email, chi, vede] of [['tutor1@test.it', 'tutor A', true], ['stud@test.it', 'studente', false]]) {
                const { ctx, page } = await entra(browser, email);
                await page.goto(BASE + '/');
                const scheda = await page.evaluate((id) => {
                    const c = document.querySelector('.course-card[data-corso="' + id + '"] .course-card-valutare');
                    return c ? c.textContent.trim() : null;
                }, A.corso);
                check(chi + ' → in «' + (vede ? 'Corsi' : 'I miei corsi') + '», sulla scheda del corso A: '
                    + (vede ? '«1 proposta da valutare nella bacheca»' : 'nessun avviso di proposte'),
                    vede ? scheda === '1 proposta da valutare nella bacheca' : scheda === null, [String(scheda)]);
                await ctx.close();
            }
            for (const [email, chi, prove] of CASI) {
                const { ctx, page } = await entra(browser, email);
                for (const [url, atteso, cosa] of prove) {
                    await page.goto(BASE + url);
                    const r = await ramo(page);
                    const senzaVociDiCorso = !r.fuori.includes('Bacheca') && !r.fuori.includes('L\'esperto');
                    let giusto;
                    if (atteso === null) {
                        giusto = r.ramo === null;
                    } else {
                        const [id, sotto, voci] = atteso;
                        giusto = r.ramo !== null && r.ramo.corso === '/courses/' + id && r.ramo.sotto === sotto
                            && JSON.stringify(r.ramo.voci) === JSON.stringify(voci.map((v) => [v[0], v[1], v[2], v[3] || '']));
                    }
                    check(chi + ' → ' + url + ': ' + cosa, giusto && senzaVociDiCorso, [JSON.stringify(r)]);
                }
                await ctx.close();
            }
            // Dopo un'azione sulla pagina del gruppo (18/10) si torna alla
            // pagina con il corso nell'indirizzo, e il ramo resta. «Ci sarò»
            // due volte, cosi' lo stato torna com'era.
            {
                const { ctx, page } = await entra(browser, 'stud@test.it');
                for (let volta = 0; volta < 2; volta++) {
                    await page.goto(BASE + G_(A.corso));
                    const azione = await page.getAttribute('form.gruppo-ci-saro', 'action');
                    await Promise.all([page.waitForNavigation(), page.click('form.gruppo-ci-saro button[type=submit]')]);
                    const r = await ramo(page);
                    const dove = new URL(page.url());
                    const attiva = r.ramo !== null ? r.ramo.voci.find((v) => v[2]) : null;
                    check('studente → «' + (volta === 0 ? 'Ci sarò' : 'Non posso più') + '» dalla pagina del gruppo aperta dal ramo: '
                        + 'si torna con ?corso= e «Il mio gruppo» evidenziata',
                        azione === '/gruppi/' + A.gruppo + '/ci-saro?corso=' + A.corso
                            && dove.pathname + dove.search === G_(A.corso) && attiva !== undefined && attiva !== null && attiva[0] === 'Il mio gruppo',
                        [String(azione), page.url(), JSON.stringify(r.ramo)]);
                }
                await ctx.close();
            }
        }

        // «Informazioni su Pistacchio LMS» (18/10): la tessera in Impostazioni,
        // la versione del file VERSION, il collegamento al repository.
        {
            const fs = require('fs');
            const versione = fs.readFileSync(path.join(__dirname, '..', 'VERSION'), 'utf8').trim();
            const { ctx, page } = await entra(browser, 'admin@test.it');
            await page.goto(BASE + '/admin/settings');
            const tessera = await page.evaluate(() => {
                const a = [...document.querySelectorAll('.settings-card')].pop();
                return a ? [a.getAttribute('href'), a.querySelector('.settings-card-title').textContent.trim()] : null;
            });
            check('admin → in Impostazioni, in fondo, la tessera «Informazioni su Pistacchio LMS»',
                tessera !== null && tessera[0] === '/admin/settings/informazioni' && tessera[1] === 'Informazioni su Pistacchio LMS', [JSON.stringify(tessera)]);
            await page.goto(BASE + '/admin/settings/informazioni');
            const info = await page.evaluate(() => ({
                titolo: (document.querySelector('h1') || {}).textContent,
                versione: (document.querySelector('.informazioni-versione') || {}).textContent,
                link: (document.querySelector('.informazioni-link a') || { getAttribute: () => null }).getAttribute('href'),
                scheda: (document.querySelector('.informazioni-link a') || { getAttribute: () => null }).getAttribute('target'),
            }));
            check('admin → la pagina dice «Versione ' + versione + '», letta dal file VERSION, e porta al repository in una scheda nuova',
                (info.titolo || '').trim() === 'Pistacchio LMS' && (info.versione || '').replace(/\s+/g, ' ').trim() === 'Versione ' + versione
                    && info.link === 'https://github.com/agilibaus/lms' && info.scheda === '_blank', [JSON.stringify(info)]);
            // I dati per l'assistenza (18/10): PHP (lo stesso del server),
            // database, data di installazione.
            const php = execFileSync('php', ['-r', 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;'], { encoding: 'utf8' }).trim();
            const dati = await page.$$eval('.informazioni-dato', (d) => d.map((x) => [
                x.querySelector('.informazioni-etichetta').textContent.trim(), x.querySelector('.informazioni-valore').textContent.trim()]));
            check('admin → sotto, PHP ' + php + ', il database con la sua versione e la data di installazione',
                dati.length === 3 && dati[0][0] === 'PHP' && dati[0][1] === php
                    && dati[1][0] === 'Database' && /^(MySQL|MariaDB) \d+\.\d+\.\d+$/.test(dati[1][1])
                    && dati[2][0] === 'Installata il' && /^\d{2}\/\d{2}\/\d{4}$/.test(dati[2][1]), [JSON.stringify(dati)]);
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

            // Dal 26/10 il quiz lo modifica solo l'admin (conflitto 1): la
            // via c'e' per lui, e non per il tutor ne' per lo studente.
            const staff = await entra(browser, 'admin@test.it');

            for (const [url, dove] of [
                ['/courses/' + A.corso, 'dalla pagina del corso'],
                ['/quizzes/' + A.quiz, 'dalla pagina del quiz'],
            ]) {
                const c = await viaDa(staff.page, url);
                check(
                    'admin → si arriva a modificare il quiz ' + dove,
                    c,
                    c ? [] : ['nessun collegamento a /quizzes/' + A.quiz + '/edit']
                );
            }

            await staff.ctx.close();

            // Controprova: al tutor e allo studente quella via non si mostra.
            // Senza, le due prove qui sopra passerebbero anche con il
            // collegamento stampato per tutti.
            for (const [email, chi] of [['tutor1@test.it', 'al tutor'], ['stud@test.it', 'allo studente']]) {
                const altro = await entra(browser, email);
                const visto = await viaDa(altro.page, '/courses/' + A.corso) || await viaDa(altro.page, '/quizzes/' + A.quiz);
                check(
                    'controprova: ' + chi + ' il collegamento non compare',
                    !visto,
                    visto ? ['vede la via per modificare il quiz'] : []
                );
                await altro.ctx.close();
            }
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

        // --- la Bacheca, dentro le pagine (17/10) -----------------------------
        //
        // La porta si prova sopra; qui che cosa mostra. I conteggi contano solo
        // le pubblicate; il racconto nascosto lo vedono chi l'ha scritto e chi
        // modera, non l'assistente; le proposte non pubblicate non finiscono
        // fra quelle da valutare; un `?corso=` di un altro corso non apre
        // quella bacheca.

        console.log('\n--- la Bacheca, dentro le pagine');

        {
            const testoDi = async (page, url) => { await page.goto(BASE + url); return page.content(); };
            const NASCOSTO = 'Un racconto che il tutor ha nascosto.';

            let s = await entra(browser, 'stud@test.it');
            let html = await testoDi(s.page, '/bacheca?corso=' + A.corso);
            // Nella prima categoria le pubblicate sono quattro, ma una sta nel
            // modulo chiuso da una data (25/10): lo studente ne conta tre.
            check('studente → i riquadri contano le sole pubblicate dei moduli aperti: 3 risorse, 1 risorsa, Nessuna risorsa (17/10, 25/10)',
                html.includes('3 risorse') && html.includes('1 risorsa') && html.includes('>Nessuna risorsa<') && !html.includes('Ancora nessuna'));
            check('studente → «Le mie risorse (1)» e «Condividi una risorsa»; niente «Nuova risorsa» né «Valuta»',
                html.includes('Le mie risorse (1)') && html.includes('Condividi una risorsa') && !html.includes('Nuova risorsa') && !html.includes('>Valuta<'));
            await s.ctx.close();
            s = await entra(browser, 'admin@test.it');
            html = await testoDi(s.page, '/bacheca?corso=' + A.corso);
            check('admin → conta anche la risorsa del modulo chiuso: 4 risorse (25/10)', html.includes('4 risorse'));
            check('admin → il collegamento alle categorie si chiama «Gestione categorie» (17/10)',
                html.includes('>Gestione categorie<') && !html.includes('Gestisci le categorie'));
            await s.ctx.close();
            s = await entra(browser, 'stud@test.it');
            html = await testoDi(s.page, '/bacheca/mie?corso=' + B.corso);
            check('studente → con il corso di B nell\'indirizzo resta nella sua bacheca',
                html.includes('conversazione A') && !html.includes('conversazione B'));
            html = await testoDi(s.page, '/bacheca/categorie/' + A.bacheca_categoria);
            check('studente → vede il proprio racconto nascosto, segnato come tale',
                html.includes(NASCOSTO) && html.includes('lo vedi solo tu'));
            check('studente → la proposta in attesa e la non pubblicata non sono nella categoria',
                !html.includes('compiti condivisi A') && !html.includes('non pubblicata A'));
            check('studente → nessun comando di chi gestisce', !html.includes('/modifica"') && !html.includes('Togli dalla bacheca'));
            html = await testoDi(s.page, '/bacheca/condividi?corso=' + A.corso);
            check('studente → «Le tue proposte» con i tre stati',
                html.includes('Le tue proposte') && html.includes('In attesa') && html.includes('Non pubblicata') && html.includes('Pubblicata'));
            check('studente → il suo modulo non ha il campo file', !html.includes('type="file"'));
            await s.ctx.close();

            s = await entra(browser, 'assist@test.it');
            html = await testoDi(s.page, '/bacheca/categorie/' + A.bacheca_categoria);
            check('assistente → legge, ma non vede il racconto nascosto né i comandi per moderare',
                html.includes('conversazione A') && !html.includes(NASCOSTO) && !html.includes('Rendi di nuovo visibile')
                && !html.includes('Togli dalla bacheca') && !html.includes('Racconta come'));
            await s.ctx.close();

            s = await entra(browser, 'tutor1@test.it');
            html = await testoDi(s.page, '/bacheca/categorie/' + A.bacheca_categoria);
            check('tutor A → vede il racconto nascosto del suo studente, con «Rendi di nuovo visibile»',
                html.includes(NASCOSTO) && html.includes('Rendi di nuovo visibile'));
            html = await testoDi(s.page, '/bacheca/proposte?corso=' + A.corso);
            check('tutor A → fra le proposte quella in attesa, non quella non pubblicata',
                html.includes('compiti condivisi A') && !html.includes('non pubblicata A'));
            html = await testoDi(s.page, '/bacheca?corso=' + A.corso);
            check('tutor A → «1 proposta da valutare» e il numero accanto a «Bacheca» nel ramo del corso',
                html.includes('1 proposta da valutare') && new RegExp('href="/bacheca\\?corso=' + A.corso + '"[^>]*>Bacheca\\s*<span class="nav-conteggio">1').test(html));
            // La Bacheca per modulo e per classe (25/10). Il modulo: una
            // risorsa del modulo chiuso da una data non si vede, non si tiene,
            // non si racconta e non si scarica, e lo studente non la propone
            // in quel modulo; lo staff la vede. La classe: i racconti e il
            // nome di chi ha proposto li vedono i compagni dei gruppi del
            // corso e chi li modera; uno studente di un'altra classe dello
            // stesso corso, il tutor di un gruppo non suo e l'assistente no.
            {
                const CHIUSA = 'Per il modulo che si apre fra trenta giorni A';
                const ALTRA = 'altra classe A';
                const RACCONTO_A = 'la terza domanda ha funzionato';
                const RACCONTO_ALTRA = 'lo leggono solo i suoi compagni';
                const cat = '/bacheca/categorie/' + A.bacheca_categoria;
                let t = await entra(browser, 'stud@test.it');
                let h = await testoDi(t.page, cat);
                check('studente A → la risorsa del modulo chiuso non c\'è; quella dell\'altra classe sì (25/10)',
                    !h.includes(CHIUSA) && h.includes(ALTRA));
                check('studente A → la risorsa dell\'altra classe è «Condivisa in bacheca», senza il nome di chi l\'ha proposta',
                    h.includes('Condivisa in bacheca') && !h.includes('Studente Altrogruppo'));
                check('studente A → legge il proprio racconto, non quello dell\'altra classe',
                    h.includes(RACCONTO_A) && !h.includes(RACCONTO_ALTRA));
                for (const [url, campi, cosa] of [
                    ['/bacheca/risorse/' + A.bacheca_risorsa_chiusa + '/tengo', [], 'tenersi la risorsa del modulo chiuso'],
                    ['/bacheca/risorse/' + A.bacheca_risorsa_chiusa + '/uso', [['body', 'x']], 'raccontarla'],
                ]) {
                    const r = await esitoPost(t.page, url, campi);
                    check('studente A → POST ' + url + ' (' + cosa + '): negato', r.esito === 'negato', [r.come]);
                }
                const scarica = await esitoGet(t.page, '/bacheca/risorse/' + A.bacheca_risorsa_chiusa + '/download');
                check('studente A → scaricare il file della risorsa del modulo chiuso: negato', scarica.esito === 'negato', [scarica.come]);
                h = await testoDi(t.page, '/bacheca/condividi?corso=' + A.corso);
                const scelta = (h.match(/name="module_id"[\s\S]*?<\/select>/) || [''])[0];
                check('studente A → proponendo, il modulo prima della categoria, solo fra i moduli aperti',
                    scelta !== '' && h.indexOf('name="module_id"') < h.indexOf('name="category_id"')
                    && scelta.includes('mondo A modulo') && !scelta.includes('modulo chiuso'), [scelta.replace(/\s+/g, ' ')]);
                await esitoPost(t.page, '/bacheca/corsi/' + A.corso + '/condividi', [['title', 'Proposta nel modulo chiuso A'], ['kind', 'scheda'],
                    ['module_id', String(A.modulo_chiuso)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'x']]);
                await esitoPost(t.page, '/bacheca/corsi/' + A.corso + '/condividi', [['title', 'Proposta senza modulo A'], ['kind', 'scheda'],
                    ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'x']]);
                // Si guarda «Le tue proposte»: il modulo, rifiutato, ripropone il
                // titolo nel campo, e lo conterrebbe comunque.
                h = await testoDi(t.page, '/bacheca/condividi?corso=' + A.corso);
                const mie = h.slice(h.indexOf('Le tue proposte'));
                check('studente A → una proposta nel modulo chiuso, o senza modulo, non nasce',
                    h.includes('Le tue proposte') && !mie.includes('Proposta nel modulo chiuso A') && !mie.includes('Proposta senza modulo A'));
                await t.ctx.close();

                t = await entra(browser, 'altrogruppo@test.it');
                h = await testoDi(t.page, cat);
                check('studente dell\'altra classe → legge il suo racconto, non quello dello studente A',
                    h.includes(RACCONTO_ALTRA) && !h.includes(RACCONTO_A));
                check('studente dell\'altra classe → la proposta dello studente A è «Condivisa in bacheca», senza nome',
                    h.includes('Ascoltare senza correggere A') && !h.includes('Proposta da Studente Prova'));
                await t.ctx.close();

                t = await entra(browser, 'tutor1@test.it');
                h = await testoDi(t.page, cat);
                check('tutor A → vede la risorsa del modulo chiuso, e i racconti e i nomi del suo gruppo',
                    h.includes(CHIUSA) && h.includes(RACCONTO_A) && h.includes('Proposta da Studente Prova'));
                check('tutor A → del gruppo non suo nessun racconto e nessun nome',
                    !h.includes(RACCONTO_ALTRA) && !h.includes('Studente Altrogruppo'));
                h = await testoDi(t.page, '/bacheca/nuova?corso=' + A.corso);
                check('tutor A → pubblicando sceglie fra tutti i moduli, anche quello chiuso', h.includes('mondo A modulo chiuso'));
                await t.ctx.close();

                t = await entra(browser, 'tutor3@test.it');
                h = await testoDi(t.page, cat);
                check('tutor dell\'altra classe → i racconti e i nomi della sua classe, non quelli del gruppo di A',
                    h.includes(RACCONTO_ALTRA) && h.includes('Proposta da Studente Altrogruppo')
                    && !h.includes(RACCONTO_A) && !h.includes('Proposta da Studente Prova'));
                await t.ctx.close();

                t = await entra(browser, 'assist@test.it');
                h = await testoDi(t.page, cat);
                check('assistente → nessun racconto e nessun nome di studente, come per foto e presentazioni',
                    !h.includes(RACCONTO_A) && !h.includes(RACCONTO_ALTRA) && !h.includes('Proposta da Studente'));
                await t.ctx.close();

                t = await entra(browser, 'admin@test.it');
                h = await testoDi(t.page, cat);
                check('admin → tutte le risorse, tutti i racconti, tutti i nomi',
                    h.includes(CHIUSA) && h.includes(RACCONTO_A) && h.includes(RACCONTO_ALTRA)
                    && h.includes('Proposta da Studente Prova') && h.includes('Proposta da Studente Altrogruppo'));
                await t.ctx.close();
            }

                        // Il file di una risorsa (17/10): fino a 3 MB, o meno se il
            // php.ini del server ne accetta meno. Un file appena oltre si
            // ferma con il motivo, e la risorsa non nasce; uno piccolo passa.
            // Il modulo dice il limite.
            {
                const carica = async (titolo, byte) => {
                    await s.page.goto(BASE + '/bacheca/nuova?corso=' + A.corso + '&categoria=' + A.bacheca_categoria);
                    const aiuto = (await s.page.textContent('#bacheca-file-aiuto')).replace(/\s+/g, ' ').trim();
                    await s.page.fill('#bacheca-titolo', titolo);
                    await s.page.selectOption('#bacheca-tipo', 'scheda');
                    await s.page.selectOption('#bacheca-modulo', String(A.modulo));
                    await s.page.fill('#bacheca-descrizione', 'Descrizione di permessi.js.');
                    const pdf = Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(byte - 9, 0x20)]);
                    await s.page.setInputFiles('#bacheca-file', { name: 'prova-limite.pdf', mimeType: 'application/pdf', buffer: pdf });
                    // Il controllo del browser si salta: qui si prova il server.
                    await s.page.evaluate(() => { document.querySelector('#bacheca-file').closest('form').noValidate = true; });
                    await Promise.all([s.page.waitForNavigation(), s.page.click('form.form button.btn-primary')]);
                    return { aiuto, html: await s.page.content(), url: s.page.url() };
                };
                // Il limite atteso, letto dallo stesso PHP del server.
                const atteso = execFileSync('php', ['-r', 'require "vendor/autoload.php";'
                    + ' echo App\\Core\\FileType::humanSize(min(3 * 1024 * 1024, App\\Core\\Upload::iniLimitBytes()));'],
                    { encoding: 'utf8', cwd: path.join(__dirname, '..') }).trim();
                const troppo = await carica('File troppo grande di permessi.js', 3 * 1024 * 1024 + 1024);
                check('tutor A → il modulo dice il limite dei file: fino a ' + atteso + ' (3 MB, o meno se il server ne accetta meno)',
                    troppo.aiuto.includes('fino a ' + atteso + '.'), [troppo.aiuto]);
                check('tutor A → un file di 3 MB e 1 kB non si carica: torna al modulo con il motivo',
                    troppo.url.includes('/bacheca/nuova') && /supera (la dimensione massima consentita|il limite del server)/.test(troppo.html),
                    [troppo.url]);
                // Nel browser, appena si sceglie il file (17/10): un file troppo
                // grande o di un tipo non ammesso lo dice subito, sotto il campo,
                // e il modulo non parte; uno giusto toglie l'avviso.
                await s.page.goto(BASE + '/bacheca/nuova?corso=' + A.corso);
                const scegli = async (nome, byte) => {
                    await s.page.setInputFiles('#bacheca-file', { name: nome, mimeType: 'application/octet-stream', buffer: Buffer.alloc(byte, 0x20) });
                    return s.page.evaluate(() => {
                        const c = document.querySelector('#bacheca-file');
                        const avviso = document.querySelector('#bacheca-file-errore') || { textContent: '(nessun avviso)', offsetHeight: 0 };
                        return { valido: c.validity.valid, avviso: avviso.textContent.trim(), visibile: avviso.offsetHeight > 0,
                            descritto: (c.getAttribute('aria-describedby') || '').includes('bacheca-file-errore') };
                    });
                };
                const grande = await scegli('grande.pdf', 4 * 1024 * 1024 + 300 * 1024);
                check('tutor A → scelto un file da 4,3 MB, lo dice subito: «pesa 4,3 MB», con il massimo, e il modulo non parte',
                    !grande.valido && grande.visibile && grande.descritto && grande.avviso.includes('pesa 4,3 MB')
                        && grande.avviso.includes('il massimo è ' + atteso), [JSON.stringify(grande)]);
                const audio = await scegli('podcast.mp3', 10 * 1024);
                check('tutor A → scelto un MP3, dice che quel tipo non si carica', !audio.valido && audio.visibile && /MP3/.test(audio.avviso),
                    [JSON.stringify(audio)]);
                const giusto = await scegli('scheda.pdf', 10 * 1024);
                check('tutor A → scelto un PDF piccolo, l\'avviso sparisce', giusto.valido && !giusto.visibile && giusto.avviso === '',
                    [JSON.stringify(giusto)]);

                // Il server, senza il browser: un MP3 non entra.
                await s.page.goto(BASE + '/bacheca/nuova?corso=' + A.corso);
                await s.page.evaluate(() => { const c = document.querySelector('#bacheca-file'); c.removeAttribute('accept'); });
                await s.page.fill('#bacheca-titolo', 'Audio di permessi.js');
                await s.page.selectOption('#bacheca-modulo', String(A.modulo));
                await s.page.fill('#bacheca-descrizione', 'Descrizione.');
                await s.page.setInputFiles('#bacheca-file', { name: 'podcast.mp3', mimeType: 'audio/mpeg', buffer: Buffer.alloc(10 * 1024, 0x20) });
                await s.page.evaluate(() => { const c = document.querySelector('#bacheca-file'); c.setCustomValidity(''); c.closest('form').noValidate = true; });
                await Promise.all([s.page.waitForNavigation(), s.page.click('form.form button.btn-primary')]);
                const htmlAudio = await s.page.content();
                check('tutor A → il server non accetta un MP3 nella bacheca (scavalcando il browser)',
                    s.page.url().includes('/bacheca/nuova') && htmlAudio.includes('Formato file non consentito'), [s.page.url()]);

                const piccolo = await carica('File piccolo di permessi.js', 200 * 1024);
                check('tutor A → un file di 200 kB si carica, e la risorsa è nella categoria',
                    piccolo.url.includes('/bacheca/categorie/' + A.bacheca_categoria) && piccolo.html.includes('File piccolo di permessi.js')
                        && piccolo.html.includes('prova-limite.pdf') && !piccolo.html.includes('File troppo grande di permessi.js'),
                    [piccolo.url]);
            }
            await s.ctx.close();

            s = await entra(browser, 'tutor2@test.it');
            html = await testoDi(s.page, '/bacheca/proposte?corso=' + B.corso);
            check('tutor B → fra le sue proposte non c\'è quella dello studente di A', !html.includes('compiti condivisi A'));
            await s.ctx.close();
        }

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
            // La Bacheca (17/10). Prima i rifiuti, poi le azioni che riescono:
            // il tutor di B non tocca la proposta dello studente di A prima che
            // il tutor di A la pubblichi.
            ['strano@test.it', 'studente B (bacheca)', [
                ['/bacheca/corsi/' + A.corso + '/condividi', [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'negato',
                    'proporre una risorsa in un corso a cui non è iscritto'],
                ['/bacheca/risorse/' + A.bacheca_risorsa + '/tengo', [], 'negato', 'tenersi una risorsa di un\'altra bacheca'],
                ['/bacheca/risorse/' + A.bacheca_risorsa + '/uso', [['body', 'x']], 'negato', 'raccontarla'],
            ]],
            ['stud@test.it', 'studente A (bacheca, rifiuti)', [
                ['/bacheca/risorse/' + A.bacheca_proposta + '/pubblica', [['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)]], 'negato',
                    'pubblicare la propria proposta'],
                ['/bacheca/risorse/' + A.bacheca_risorsa + '/togli', [], 'negato', 'togliere una risorsa dalla bacheca'],
                ['/bacheca/risorse/' + A.bacheca_risorsa + '/evidenza', [['si', '0']], 'negato', 'cambiare l\'evidenza'],
                ['/bacheca/usi/' + A.bacheca_uso_nascosto, [['azione', 'rimetti']], 'negato', 'rimettere il proprio racconto nascosto'],
                ['/bacheca/corsi/' + A.corso + '/nuova', [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'negato', 'pubblicare senza passare dal tutor'],
                ['/admin/courses/' + A.corso + '/bacheca/categorie', [['name', 'x'], ['color', '#4F7256']], 'negato', 'creare una categoria'],
                ['/admin/bacheca/categorie/' + A.bacheca_categoria_vuota + '/elimina', [], 'negato', 'eliminare una categoria'],
            ]],
            ['tutor2@test.it', 'tutor B (bacheca)', [
                ['/bacheca/risorse/' + A.bacheca_proposta + '/pubblica', [['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)]], 'negato',
                    'pubblicare la proposta di uno studente di un collega'],
                ['/bacheca/risorse/' + A.bacheca_proposta + '/scarta', [], 'negato', 'non pubblicarla'],
                ['/bacheca/risorse/' + A.bacheca_risorsa, [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'negato', 'correggere una risorsa della bacheca di un collega'],
                ['/bacheca/risorse/' + A.bacheca_risorsa + '/togli', [], 'negato', 'toglierla'],
                ['/bacheca/usi/' + A.bacheca_uso, [['azione', 'nascondi']], 'negato', 'nascondere il racconto di uno studente di un collega'],
                ['/bacheca/corsi/' + A.corso + '/nuova', [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'negato', 'pubblicare nella bacheca di un collega'],
                ['/admin/courses/' + B.corso + '/bacheca/categorie', [['name', 'x'], ['color', '#4F7256']], 'negato',
                    'creare una categoria: le crea l\'admin, anche nel corso suo'],
            ]],
            ['assist@test.it', 'assistente (bacheca)', [
                ['/bacheca/corsi/' + A.corso + '/nuova', [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'negato', 'pubblicare'],
                ['/bacheca/corsi/' + A.corso + '/condividi', [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'negato', 'proporre: propongono gli studenti'],
                ['/bacheca/risorse/' + A.bacheca_proposta + '/pubblica', [['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)]], 'negato', 'valutare una proposta'],
                ['/bacheca/usi/' + A.bacheca_uso, [['azione', 'nascondi']], 'negato', 'moderare un racconto'],
            ]],
            ['stud@test.it', 'studente A (bacheca)', [
                ['/bacheca/corsi/' + A.corso + '/condividi', [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'consentito', 'proporre una risorsa nel proprio corso'],
                ['/bacheca/risorse/' + A.bacheca_risorsa_digitale + '/tengo', [], 'consentito', 'tenersi una risorsa'],
                ['/bacheca/risorse/' + A.bacheca_risorsa_digitale + '/uso', [['body', 'Racconto di permessi.js.']], 'consentito',
                    'raccontare come l\'ha utilizzata'],
            ]],
            ['tutor1@test.it', 'tutor A (bacheca)', [
                ['/bacheca/usi/' + A.bacheca_uso_nascosto, [['azione', 'rimetti']], 'consentito', 'rimettere il racconto di un suo studente'],
                ['/bacheca/risorse/' + A.bacheca_proposta + '/pubblica', [['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria2)]], 'consentito',
                    'pubblicare la proposta di un suo studente, in un\'altra categoria'],
                ['/bacheca/corsi/' + A.corso + '/nuova', [['title', 'Proposta di permessi.js'], ['kind', 'scheda'], ['module_id', String(A.modulo)], ['category_id', String(A.bacheca_categoria)], ['url', ''], ['description', 'Descrizione di permessi.js.']], 'consentito', 'pubblicare nel corso dei suoi gruppi'],
                ['/admin/courses/' + A.corso + '/bacheca/categorie', [['name', 'x'], ['color', '#4F7256']], 'negato', 'creare una categoria'],
            ]],
            ['admin@test.it', 'admin (bacheca)', [
                // Un valore che non è un colore (17/10): la richiesta torna con
                // il motivo e la categoria non nasce (si controlla dopo). Un
                // giallo chiaro invece va bene: dal 17/10 ogni colore si può.
                ['/admin/courses/' + A.corso + '/bacheca/categorie', [['name', 'Categoria fucsia'], ['color', 'fucsia'], ['description', '']],
                    'consentito', 'chiedere una categoria con un colore che non è un colore (non nasce: si controlla dopo)'],
                ['/admin/courses/' + A.corso + '/bacheca/categorie', [['name', 'Categoria di permessi.js'], ['color', '#FFF59D'], ['description', '']],
                    'consentito', 'creare una categoria'],
                ['/admin/bacheca/categorie/' + A.bacheca_categoria + '/elimina', [], 'consentito',
                    'chiedere di eliminare una categoria piena (risponde con il motivo, e la categoria resta: si controlla dopo)'],
                ['/admin/bacheca/categorie/' + A.bacheca_categoria_vuota + '/elimina', [], 'consentito', 'eliminare una categoria vuota'],
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

        // La Bacheca (17/10): la proposta dello studente di A arriva al tutor
        // del suo gruppo, e non all'admin; le azioni qui sopra hanno lasciato
        // i segni che dovevano lasciare.
        {
            const fs = require('fs');
            const cartella = path.join(__dirname, '..', 'storage', 'mail');
            const arrivata = (chi) => (fs.existsSync(cartella) ? fs.readdirSync(cartella) : [])
                .filter((f) => f.includes(chi) && fs.statSync(path.join(cartella, f)).mtimeMs >= INIZIO_GIRO)
                .some((f) => {
                    const testo = fs.readFileSync(path.join(cartella, f), 'utf8');
                    return testo.includes('Subject: Nuova proposta per la bacheca') && testo.includes('Proposta di permessi.js');
                });
            check('la proposta per la bacheca arriva per email al tutor del gruppo', arrivata('tutor1'),
                ['nessuna email «Nuova proposta per la bacheca» al tutor in storage/mail']);
            check('e non all\'admin, quando il tutor c\'è', !arrivata('admin'), ['l\'admin l\'ha ricevuta']);

            const { ctx, page } = await entra(browser, 'admin@test.it');
            await page.goto(BASE + '/bacheca?corso=' + A.corso);
            let html = await page.content();
            check('bacheca → la categoria piena non si elimina, quella vuota sì, la nuova c\'è',
                html.includes('Comunicare con mio figlio') && !html.includes('Prendersi cura di sé') && html.includes('Categoria di permessi.js'));
            const nuova = await page.evaluate(() => {
                const t = [...document.querySelectorAll('.bacheca-riquadro')].find((x) => x.textContent.includes('Categoria di permessi.js'));
                if (!t) return null;
                const h = t.querySelector('.bacheca-riquadro-titolo');
                return [t.style.getPropertyValue('--cat'), t.style.getPropertyValue('--cat-soft'), t.style.getPropertyValue('--cat-titolo'),
                    getComputedStyle(h).color, getComputedStyle(t).borderTopColor];
            });
            // Un giallo chiaro (17/10, strada 2): il filo è giallo chiaro, il
            // titolo la stessa tinta scurita fino a leggersi.
            check('bacheca → la categoria nuova, giallo chiaro: filo nel colore scelto, titolo nella stessa tinta scurita; quella con un non-colore non c\'è',
                nuova !== null && nuova[0] === '#FFF59D' && nuova[1] === '#FFFEF4' && nuova[2] === '#7A764B'
                    && nuova[3] === 'rgb(122, 118, 75)' && nuova[4] === 'rgb(255, 245, 157)' && !html.includes('Categoria fucsia'), [JSON.stringify(nuova)]);
            check('bacheca → la proposta pubblicata è nella categoria scelta dal tutor: Vita digitale ha 2 risorse',
                /Vita digitale[\s\S]*?2 risorse/.test(html));
            await page.goto(BASE + '/bacheca/categorie/' + A.bacheca_categoria);
            html = await page.content();
            check('bacheca → il racconto rimesso dal tutor è di nuovo visibile, e la risorsa del tutor è pubblicata',
                html.includes('Un racconto che il tutor ha nascosto.') && !html.includes('Rendi di nuovo visibile')
                && html.includes('Proposta di permessi.js'));
            await ctx.close();
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
                // Dal 18/10 con il corso nell'indirizzo: sulla pagina del gruppo
                // resta il ramo del corso nella sidebar.
                check(chi + ' → pagina del suo corso: «Vai al tuo gruppo» porta al suo gruppo, con il corso',
                    link.length === 1 && link[0] === '/gruppi/' + gruppo + '?corso=' + corso, [JSON.stringify(link)]);
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
            '/reports/fermi',
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

        // --- i ruoli: nucleo fisso e cinque interruttori (26/10) ------------
        //
        // Ogni interruttore fa quello che dice, acceso e spento, anche
        // scrivendo gli indirizzi a mano. Si spengono dalla pagina Ruoli
        // dell'admin, cosi' si prova anche la pagina, e si riaccendono in
        // ogni caso alla fine. In fondo al giro perche' qui un membro si
        // aggiunge davvero a un gruppo.
        console.log('\n--- i ruoli: nucleo fisso e interruttori');

        const CHIAVI = ['ROLE_TUTOR_MEMBRI', 'ROLE_ASSISTENTE_REPORT', 'ROLE_ASSISTENTE_TENTATIVI', 'ROLE_ASSISTENTE_INCONTRI', 'ROLE_STUDENTE_PROPOSTE'];
        const amm = await entra(browser, 'admin@test.it');
        const imposta = async (accesi) => {
            const campi = CHIAVI.map((k) => ['ruoli[' + k + ']', accesi.includes(k) ? '1' : '0']);
            const r = await esitoPost(amm.page, '/admin/permissions', campi, '/admin/permissions');
            if (r.esito !== 'consentito') throw new Error('Ruoli non salvati: ' + r.come);
        };
        const vede = async (email, url) => {
            const { ctx, page } = await entra(browser, email);
            const r = await esitoGet(page, url);
            const html = r.esito === 'consentito' ? await page.content() : '';
            await ctx.close();
            return { esito: r.esito, html };
        };
        try {
            await amm.page.goto(BASE + '/admin/permissions');
            const caselle = await amm.page.$$eval('input[type="checkbox"][name^="ruoli["]', (c) => c.map((x) => [x.name, x.checked]));
            const testo = await amm.page.locator('.main-content').innerText();
            check('admin → la pagina Ruoli ha i cinque interruttori, tutti accesi, e niente matrice',
                caselle.length === 5 && caselle.every(([, c]) => c) && !testo.includes('role_permissions') && testo.includes('Non crea né elimina gruppi'),
                [JSON.stringify(caselle)]);

            // Nucleo del tutor: non crea, non elimina, non assegna corsi.
            {
                const { ctx, page } = await entra(browser, 'tutor1@test.it');
                const G = '/admin/groups/' + A.gruppo;
                for (const [url, campi] of [
                    ['/admin/groups', [['name', '[prova-permessi] gruppo del tutor']]],
                    [G + '/delete', []],
                    [G + '/courses', [['course_id', String(B.corso)]]],
                    [G + '/courses/' + A.corso + '/delete', []],
                ]) {
                    const r = await esitoPost(page, url, campi, G + '/edit');
                    check('tutor A → POST ' + url + ': negato', r.esito === 'negato', r.esito === 'negato' ? [] : ['ottenuto ' + r.come]);
                }
                const crea = await esitoGet(page, '/admin/groups/create');
                check('tutor A → /admin/groups/create: negato', crea.esito === 'negato', [crea.come]);
                await page.goto(BASE + '/admin/groups');
                const elenco = await page.content();
                check('tutor A → nei Gruppi niente «Nuovo gruppo» né «Elimina»', !elenco.includes('/admin/groups/create') && !elenco.includes('/delete"'), []);
                await page.goto(BASE + G + '/edit');
                const scelte = await page.$$eval('form[action$="/members"] option', (o) => o.map((x) => x.textContent));
                const scheda = await page.content();
                check('tutor A → fra chi aggiungere solo gli iscritti al corso senza gruppo: chi ha finito sì, l\'altra classe no',
                    scelte.some((x) => x.includes('finito@test.it')) && !scelte.some((x) => x.includes('altrogruppo@test.it')) && !scelte.some((x) => x.includes('compagno1@')),
                    [JSON.stringify(scelte)]);
                check('tutor A → nella scheda del gruppo niente «Assegna corso» né «Elimina gruppo»',
                    !scheda.includes('Assegna corso') && !scheda.includes('Elimina gruppo'), []);
                const fuori = await esitoPost(page, G + '/members', [['user_id', String(d.utenti.altroGruppo)]], G + '/edit');
                await page.goto(BASE + G + '/edit');
                check('tutor A → chi ha già un gruppo nel corso non si aggiunge, nemmeno a mano',
                    !(await page.content()).includes('altrogruppo@test.it'), [fuori.come]);
                await esitoPost(page, G + '/members', [['user_id', String(d.utenti.finito)]], G + '/edit');
                // Dalla scheda del suo assistente il tutor non lo mette nel
                // gruppo: le schede utente sono dell'admin (29/09) e anche i
                // gruppi da lì (26/10); per vederlo rosso vanno rotte tutte e
                // due. Un rifiuto può essere un rimando, quindi si guarda il gruppo.
                await esitoPost(page, '/admin/users/' + d.utenti.assistente + '/groups', [['group_id', String(A.gruppo)]], '/admin/users/' + d.utenti.assistente + '/edit');
                await page.goto(BASE + G + '/edit');
                check('tutor A → dalla scheda dell\'assistente non lo mette nel suo gruppo',
                    !(await page.$$eval('table.data-table td', (c) => c.some((x) => x.textContent.includes('assist@test.it')))), []);
                // Nella tabella dei membri: tolto dal gruppo, torna (giustamente)
                // fra i candidati nella tendina.
                const nellaTabella = async () => { await page.goto(BASE + G + '/edit'); return page.$$eval('table.data-table td', (c) => c.some((x) => x.textContent.includes('finito@test.it'))); };
                const dentro = await nellaTabella();
                await esitoPost(page, G + '/members/' + d.utenti.finito + '/delete', [], G + '/edit');
                const tolto = !(await nellaTabella());
                check('tutor A → con «Membri dei gruppi» acceso aggiunge un candidato e lo toglie', dentro && tolto, [JSON.stringify({ dentro, tolto })]);
                await ctx.close();
            }

            // Tutti spenti.
            await imposta([]);
            {
                const { ctx, page } = await entra(browser, 'tutor1@test.it');
                const G = '/admin/groups/' + A.gruppo;
                await page.goto(BASE + G + '/edit');
                const scheda = await page.content();
                check('spento «Membri dei gruppi» → nella scheda né aggiungi né rimuovi, e la frase lo dice',
                    !scheda.includes('action="' + G + '/members"') && !scheda.includes('/members/' + d.utenti.studenteA + '/delete')
                        && scheda.includes('I membri del gruppo li gestisce l’amministrazione'), []);
                const ag = await esitoPost(page, G + '/members', [['user_id', String(d.utenti.finito)]], G + '/edit');
                const rm = await esitoPost(page, G + '/members/' + d.utenti.studenteA + '/delete', [], G + '/edit');
                check('spento «Membri dei gruppi» → POST aggiungi e togli: negati', ag.esito === 'negato' && rm.esito === 'negato', [ag.come, rm.come]);
                await ctx.close();
            }
            {
                const { ctx, page } = await entra(browser, 'assist@test.it');
                for (const [url, perche] of [
                    ['/reports', 'i report'],
                    ['/reports/students/' + d.utenti.studenteA, 'il report dello studente'],
                    ['/reports/courses/' + A.corso + '/griglia', 'la Griglia'],
                    ['/reports/live/' + A.incontro, 'il report di un incontro'],
                    ['/lessons/' + A.lezione + '/fruizione', 'la fruizione'],
                    ['/certificates/' + A.certificato + '/download', 'il certificato'],
                    ['/attempts/' + A.tentativo, 'il tentativo'],
                    ['/live/' + A.incontro_imminente, 'l\'incontro'],
                ]) {
                    const r = await esitoGet(page, url);
                    check('spenti gli interruttori dell\'assistente → ' + url + ': negato', r.esito === 'negato', r.esito === 'negato' ? [] : [perche + ': ' + r.come]);
                }
                await page.goto(BASE + '/');
                const menu = await page.$$eval('.sidebar-nav a.nav-link', (a) => a.map((x) => x.getAttribute('href')));
                check('spenti → nel menu dell\'assistente né «Report» né «Sessioni live»', !menu.includes('/reports') && !menu.includes('/live'), [JSON.stringify(menu)]);
                await page.goto(BASE + '/agenda');
                check('spento «Incontri» → nell\'Agenda dell\'assistente nessun incontro', !(await page.content()).includes('/live/' + A.incontro_imminente), []);
                await ctx.close();
            }
            {
                const { ctx, page } = await entra(browser, 'stud@test.it');
                const c = await esitoGet(page, '/bacheca/condividi?corso=' + A.corso);
                check('spento «Proposte in Bacheca» → /bacheca/condividi: negato', c.esito === 'negato', [c.come]);
                const p = await esitoPost(page, '/bacheca/corsi/' + A.corso + '/condividi', [['title', 'x']], '/bacheca?corso=' + A.corso);
                const u = await esitoPost(page, '/bacheca/risorse/' + A.bacheca_risorsa + '/uso', [['body', 'Un racconto scritto a mano.']], '/bacheca?corso=' + A.corso);
                check('spento «Proposte in Bacheca» → proporre e raccontare a mano: negati', p.esito === 'negato' && u.esito === 'negato', [p.come, u.come]);
                await page.goto(BASE + '/bacheca?corso=' + A.corso);
                const bach = await page.content();
                await page.goto(BASE + '/bacheca/categorie/' + A.bacheca_categoria);
                const cat = await page.content();
                check('spento «Proposte in Bacheca» → niente «Condividi una risorsa» né «Racconta», e «Le mie risorse» resta',
                    !bach.includes('Condividi una risorsa') && bach.includes('Le mie risorse') && !cat.includes('/uso"'), []);
                await ctx.close();
            }

            // Report acceso, tentativi spenti; poi incontri acceso, report spento.
            await imposta(['ROLE_ASSISTENTE_REPORT']);
            {
                const r1 = await vede('assist@test.it', '/reports/students/' + d.utenti.studenteA);
                const r2 = await vede('assist@test.it', '/attempts/' + A.tentativo);
                check('«Report» acceso e «Tentativi» spento → il report sì, il tentativo no, e nel report nessun collegamento ai tentativi',
                    r1.esito === 'consentito' && r2.esito === 'negato' && !r1.html.includes('/attempts/'), [r1.esito, r2.esito]);
            }
            await imposta(['ROLE_ASSISTENTE_TENTATIVI']);
            {
                const r = await vede('assist@test.it', '/attempts/' + A.tentativo);
                check('«Tentativi» acceso ma «Report» spento → il tentativo no: vale solo con i report', r.esito === 'negato', [r.esito]);
            }
            await imposta(['ROLE_ASSISTENTE_INCONTRI']);
            {
                const r = await vede('assist@test.it', '/live/' + A.incontro_imminente);
                check('«Incontri» acceso e «Report» spento → l\'incontro sì, le presenze no',
                    r.esito === 'consentito' && !r.html.includes('>Presenze<'), [r.esito]);
            }
        } finally {
            await imposta(CHIAVI);
            await amm.ctx.close();
        }
        {
            const r = await vede('assist@test.it', '/attempts/' + A.tentativo);
            check('controprova: riaccesi, l\'assistente riapre il tentativo', r.esito === 'consentito', [r.esito]);
        }
    } finally {
        await browser.close();
    }

    console.log('\nTotale: ' + ok + ' superati, ' + fail + ' falliti');
    process.exit(fail === 0 ? 0 : 1);
})();
