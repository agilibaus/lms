/**
 * Il test di carico di Pistacchio LMS (06/11): studenti che entrano, guardano
 * i loro corsi, aprono lezioni e questionario, l'Agenda e il catalogo, ed
 * escono, con le pause di una persona vera. Istruzioni in LEGGIMI.md.
 *
 *   k6 run -e BASE=https://il-tuo-dominio -e PASSWORD=... tests/carico/studente.js
 *   k6 run -e BASE=... -e PASSWORD=... -e PICCOLO=1 tests/carico/studente.js   (5 utenti, 3 minuti)
 *
 * Le soglie vengono dal conto del 06/11 (35 processi PHP, 150 collegati,
 * 30 pagine al secondo nei momenti di punta):
 *   - limite:    il 95% delle pagine entro 1,15 secondi;
 *   - obiettivo: il 95% delle pagine entro 390 ms (margine di tre volte).
 * A fine prova k6 dice quali soglie sono rispettate.
 *
 * Solo le richieste a Pistacchio, cioe' il lavoro di PHP: fogli di stile,
 * caratteri e immagini li serve il server web senza PHP, e i video li serve
 * Bunny. Ma chi guarda un video di Bunny manda a Pistacchio la fruizione
 * (`lesson-tracking.js`): ogni 60 secondi mentre il video va. Lo script fa
 * lo stesso nella prima lezione con un video di Bunny (06/11).
 */
import http from 'k6/http';
import { check, sleep, fail } from 'k6';

const BASE = (__ENV.BASE || '').replace(/\/+$/, '');
const PASSWORD = __ENV.PASSWORD || '';
const UTENTI = parseInt(__ENV.UTENTI || '500', 10);
// La pausa media fra una pagina e l'altra, in secondi: 10, fra 5 e 15.
const PAUSA = parseFloat(__ENV.PAUSA || '10');
// La fruizione dei video: ogni quanti secondi il lettore scrive, 60 come
// `lesson-tracking.js`. Lo studente guarda da 2 a 5 giri.
const VIDEO_OGNI = parseFloat(__ENV.VIDEO_OGNI || '60');

if (!BASE || !PASSWORD) {
    fail('Mancano BASE e PASSWORD: k6 run -e BASE=https://... -e PASSWORD=... tests/carico/studente.js');
}

const PICCOLO = {
    piccolo: { executor: 'constant-vus', vus: 5, duration: __ENV.DURATA || '3m' },
};
const PIENO = {
    pieno: {
        executor: 'ramping-vus',
        startVUs: 0,
        stages: [
            { duration: '5m', target: 150 },   // salita
            { duration: '20m', target: 150 },  // 150 collegati
            { duration: '2m', target: 0 },     // discesa
        ],
        gracefulRampDown: '30s',
    },
};

export const options = {
    scenarios: __ENV.PICCOLO ? PICCOLO : PIENO,
    thresholds: {
        // Ogni pagina ha il tag «pagina»: le soglie valgono per tutte insieme.
        'http_req_duration{pagina:si}': [
            { threshold: 'p(95)<1150', abortOnFail: false },  // limite
            { threshold: 'p(95)<390', abortOnFail: false },   // obiettivo
        ],
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
    },
};

function pausa() {
    sleep(PAUSA * (0.5 + Math.random()));
}

function token(corpo) {
    const m = /name="_token"\s+value="([^"]+)"/.exec(corpo || '');
    return m ? m[1] : '';
}

function vai(percorso, nome) {
    const r = http.get(BASE + percorso, { tags: { name: nome, pagina: 'si' } });
    check(r, { [nome + ': 200']: (x) => x.status === 200 });
    return r;
}

/**
 * Guarda il video di Bunny della lezione, se c'e': come il lettore vero, una
 * richiesta di fruizione ogni minuto con l'intervallo visto. Torna true se
 * l'ha guardato.
 */
function guarda(lezione, corpo) {
    const script = /<script[^>]+lesson-tracking\.js"[^>]*>/.exec(corpo || '');
    if (!script) return false;
    const tok = (/data-token="([^"]+)"/.exec(script[0]) || [])[1] || '';
    const durata = parseInt((/data-durata="(\d+)"/.exec(script[0]) || [])[1] || '0', 10) || 600;
    const giri = 2 + Math.floor(Math.random() * 4);
    let posizione = 0;
    for (let g = 1; g <= giri && posizione < durata; g++) {
        sleep(VIDEO_OGNI);
        const da = posizione;
        posizione = Math.min(durata, posizione + Math.round(VIDEO_OGNI));
        const r = http.post(BASE + '/lessons/' + lezione + '/fruizione',
            { _token: tok, posizione: String(posizione), durata: String(durata), intervalli: JSON.stringify([[da, posizione]]) },
            { headers: { 'X-Requested-With': 'fetch' }, tags: { name: 'video: fruizione', pagina: 'si' } });
        check(r, { 'video: fruizione registrata': (x) => x.status === 200 || x.status === 204 });
    }
    return true;
}

function primi(corpo, re, quanti) {
    const visti = [];
    let m;
    while ((m = re.exec(corpo || '')) !== null && visti.length < quanti) {
        if (!visti.includes(m[1])) visti.push(m[1]);
    }
    return visti;
}

export default function () {
    const n = 1 + Math.floor(Math.random() * UTENTI);
    const email = 'carico-' + String(n).padStart(3, '0') + '@carico.invalid';
    const jar = http.cookieJar();
    jar.clear(BASE);

    // Accesso.
    const login = vai('/login', 'accesso: pagina');
    const dentro = http.post(BASE + '/login', { email: email, password: PASSWORD, _token: token(login.body) },
        { tags: { name: 'accesso: invio', pagina: 'si' } });
    const ok = check(dentro, { 'accesso riuscito': (x) => x.status === 200 && !x.url.includes('/login') });
    if (!ok) {
        pausa();
        return;
    }
    let ultima = dentro;
    pausa();

    // I miei corsi, il corso, due lezioni, il questionario.
    const corsi = primi(ultima.body, /href="\/courses\/(\d+)"/g, 3);
    if (corsi.length > 0) {
        const corso = vai('/courses/' + corsi[0], 'corso');
        ultima = corso;
        pausa();
        let video = false;
        for (const l of primi(corso.body, /href="\/lessons\/(\d+)"/g, 2)) {
            ultima = vai('/lessons/' + l, 'lezione');
            // Il primo video di Bunny lo guarda per qualche minuto.
            video = video || guarda(l, ultima.body);
            pausa();
        }
        for (const q of primi(corso.body, /href="\/quizzes\/(\d+)"/g, 1)) {
            ultima = vai('/quizzes/' + q, 'questionario');
            pausa();
        }
    }

    ultima = vai('/agenda', 'agenda');
    pausa();
    ultima = vai('/catalogo', 'esplora corsi');
    pausa();
    ultima = vai('/', 'i miei corsi');

    // Uscita.
    http.post(BASE + '/logout', { _token: token(ultima.body) }, { tags: { name: 'uscita', pagina: 'si' } });
    jar.clear(BASE);
    pausa();
}
