/**
 * Fruizione del video: salva da dove riprendere e quali parti sono state
 * guardate.
 *
 * ATTENZIONE AI NOMI. Questo file NON è `player.js`. `player.js` è la
 * libreria di Embedly che Bunny usa nel suo iframe, e sta in
 * `/assets/vendor/playerjs/`. Questo è il pezzo nostro, che quella libreria
 * la usa.
 *
 * COSA MANDA E PERCHÉ DUE COSE. La posizione serve alla ripresa, anche
 * cambiando dispositivo — è la ragione per cui sta in tabella e non nel
 * browser. Gli intervalli servono al rendiconto: dicono quali parti del video
 * sono state viste, e quindi quanta lezione è stata guardata davvero. Un
 * contatore unico non basterebbe, perché riguardando dieci volte lo stesso
 * minuto direbbe cinquanta minuti di una lezione da venticinque.
 *
 * QUANDO SCRIVE. Alla pausa, alla fine, alla chiusura della scheda e ogni
 * sessanta secondi. Il periodico non è un lusso: chi chiude male la scheda
 * non manda niente, e qui una sessione persa è un dato che manca a un
 * rendiconto.
 *
 * LA REGOLA CHE TIENE INSIEME TUTTO: il peggio che può succedere è il
 * comportamento di prima. Script non caricato, libreria assente, API muta,
 * rete che non risponde, JavaScript spento — in ogni caso il video si guarda
 * come si è sempre guardato. Niente qui dentro può impedire a uno studente di
 * vedere la lezione. È anche la ragione per cui questo file si può consegnare
 * pur non essendo collaudabile contro un iframe vero di Bunny dal container
 * in cui è stato scritto.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var box = document.getElementById('lesson-video');

    if (script === null || script === undefined || box === null) {
        return;
    }

    var lessonId = parseInt(script.getAttribute('data-lezione'), 10);
    var token = script.getAttribute('data-token');

    if (!lessonId || !token) {
        return;
    }

    // Durata dichiarata sulla lezione: ripiego finché il player non dice la
    // sua, che è più attendibile perché la legge dal file.
    var duration = parseInt(script.getAttribute('data-durata'), 10) || 0;

    var ENDPOINT = '/lessons/' + lessonId + '/fruizione';
    var OGNI_MS = 60000;

    // Sotto i due secondi è rumore, non lezione guardata: un timeupdate
    // isolato o un trascinamento della barra. Stessa soglia del server.
    var MIN_INTERVALLO = 2;

    // Distanza oltre la quale si considera che qualcuno abbia saltato invece
    // di guardare: chiude l'intervallo corrente e ne apre un altro.
    var SALTO = 4;

    var pending = [];     // intervalli non ancora mandati
    var apertoDa = null;  // inizio dell'intervallo in corso
    var ultimo = null;    // ultimo secondo visto
    var posizione = 0;
    var inCorso = false;  // c'è già una richiesta in volo

    // --- raccolta -----------------------------------------------------

    function chiudi() {
        if (apertoDa === null || ultimo === null) {
            apertoDa = null;
            return;
        }

        if (ultimo - apertoDa >= MIN_INTERVALLO) {
            pending.push([apertoDa, ultimo]);
        }

        apertoDa = null;
    }

    /**
     * Chiamata a ogni `timeupdate`: circa quattro volte al secondo. Non
     * manda niente, accumula soltanto — è il timer a decidere quando
     * scrivere.
     */
    function avanza(secondo) {
        if (typeof secondo !== 'number' || !isFinite(secondo) || secondo < 0) {
            return;
        }

        posizione = Math.floor(secondo);

        if (apertoDa === null) {
            apertoDa = posizione;
            ultimo = posizione;
            return;
        }

        // Salto avanti o indietro: l'intervallo di prima finisce qui e ne
        // comincia uno nuovo. Senza questo, saltare dal minuto 2 al minuto
        // 20 registrerebbe diciotto minuti che nessuno ha guardato.
        if (Math.abs(posizione - ultimo) > SALTO) {
            chiudi();
            apertoDa = posizione;
        }

        ultimo = posizione;
    }

    // --- invio --------------------------------------------------------

    /**
     * URLSearchParams e non JSON: così il corpo parte come
     * `application/x-www-form-urlencoded` e PHP lo trova in `$_POST`, sia
     * da `fetch` sia da `sendBeacon`. Con un corpo JSON `$_POST` resterebbe
     * vuoto e il server non vedrebbe nemmeno il token CSRF.
     */
    function corpo(intervalli) {
        var dati = new URLSearchParams();
        dati.append('_token', token);
        dati.append('posizione', String(posizione));
        dati.append('durata', String(duration));
        dati.append('intervalli', JSON.stringify(intervalli));

        return dati;
    }

    function salva() {
        chiudi();

        if (pending.length === 0 && posizione === 0) {
            return;
        }

        if (inCorso) {
            return;
        }

        var daMandare = pending;
        pending = [];
        inCorso = true;

        fetch(ENDPOINT, {
            method: 'POST',
            body: corpo(daMandare),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (risposta) {
            inCorso = false;

            // Rete o server hanno detto di no: gli intervalli tornano in
            // coda e ripartono col prossimo giro. Perderli in silenzio
            // vorrebbe dire un rendiconto che manca di pezzi senza che
            // nessuno se ne accorga.
            if (!risposta.ok && risposta.status !== 204) {
                pending = daMandare.concat(pending);
            }
        }).catch(function () {
            inCorso = false;
            pending = daMandare.concat(pending);
        });

        // L'intervallo in corso riprende da dove si è chiuso: la pausa è
        // solo per mandare, non per smettere di guardare.
        if (ultimo !== null) {
            apertoDa = ultimo;
        }
    }

    /**
     * Alla chiusura della scheda `fetch` non fa in tempo: il browser
     * interrompe la richiesta appena la pagina se ne va. `sendBeacon` è
     * nata per questo — la consegna la porta a termine il browser, dopo.
     */
    function salvaAllUscita() {
        chiudi();

        if (pending.length === 0) {
            return;
        }

        if (typeof navigator.sendBeacon !== 'function') {
            salva();
            return;
        }

        if (navigator.sendBeacon(ENDPOINT, corpo(pending))) {
            pending = [];
        }
    }

    // --- attacco al player ---------------------------------------------

    var video = box.querySelector('video');
    var iframe = box.querySelector('iframe');

    function ascoltaVideoLocale(el) {
        el.addEventListener('timeupdate', function () {
            avanza(el.currentTime);
        });

        el.addEventListener('loadedmetadata', function () {
            if (isFinite(el.duration) && el.duration > 0) {
                duration = Math.round(el.duration);
            }
        });

        el.addEventListener('pause', salva);
        el.addEventListener('ended', function () {
            avanza(el.currentTime);
            salva();
        });
    }

    function ascoltaIframe(el) {
        // Senza la libreria non si parla con l'iframe. Non è un errore da
        // segnalare: è il caso "il peggio è il comportamento di prima".
        if (typeof window.playerjs === 'undefined' || !window.playerjs.Player) {
            return;
        }

        var player;

        try {
            player = new window.playerjs.Player(el);
        } catch (e) {
            return;
        }

        player.on('ready', function () {
            player.on('timeupdate', function (stato) {
                if (stato && typeof stato.seconds === 'number') {
                    avanza(stato.seconds);
                }

                if (stato && typeof stato.duration === 'number' && stato.duration > 0) {
                    duration = Math.round(stato.duration);
                }
            });

            player.on('pause', salva);
            player.on('ended', salva);
        });
    }

    if (video !== null) {
        ascoltaVideoLocale(video);
    } else if (iframe !== null) {
        // L'iframe dei provider esterni nasce senza src e lo riceve quando
        // si preme la copertina: prima di allora non c'è niente con cui
        // parlare. Ci si attacca appena il video parte.
        if (iframe.hasAttribute('data-src')) {
            box.addEventListener('lezione:video-avviato', function () {
                ascoltaIframe(iframe);
            });
        } else {
            ascoltaIframe(iframe);
        }
    }

    // --- quando si scrive ------------------------------------------------

    window.setInterval(salva, OGNI_MS);

    // `visibilitychange` con stato "hidden" è l'unico segnale affidabile su
    // telefono: lì `beforeunload` spesso non arriva mai, perché la scheda
    // viene messa da parte invece che chiusa.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            salvaAllUscita();
        }
    });

    window.addEventListener('pagehide', salvaAllUscita);
}());
