/**
 * Contenuti H5P nella lezione (23/10, passo 3): avvia un riproduttore
 * h5p-standalone (MIT, `/assets/h5p/`) per ogni esercizio della pagina, in
 * un iframe come i questionari, e tiene il conto di quelli usati.
 *
 * LO SBLOCCO. «Segna come completata» resta disattivato finche' lo studente
 * non ha usato tutti gli esercizi (decisione del 23/10: una qualunque
 * interazione). Usato vuol dire: l'esercizio ha mandato un evento xAPI di
 * chi fa qualcosa (ha risposto, ha aperto una voce, ha girato una carta…),
 * oppure c'e' stato un clic o un Invio dentro l'esercizio, per i tipi che
 * non mandano eventi. Guardarlo o scorrerlo non basta.
 *
 * Come per il video, la regola sta nel browser: senza JavaScript, o se un
 * esercizio non si carica, il pulsante resta usabile. Uno studente bloccato
 * e' un danno vero. Gli esercizi usati si ricordano per la scheda
 * (sessionStorage): ricaricando la pagina non si ricomincia da capo.
 *
 * GLI ESERCIZI SI AVVIANO UNO DOPO L'ALTRO. Il riproduttore prepara ogni
 * esercizio in una variabile globale della pagina (`H5PIntegration`), che
 * l'iframe legge mentre si carica: avviandoli insieme, il secondo
 * sovrascriverebbe il primo prima che il suo iframe l'abbia letta.
 */
(function () {
    'use strict';

    var figure = Array.prototype.slice.call(document.querySelectorAll('[data-lezione-h5p]'));

    if (figure.length === 0 || !window.H5PStandalone) {
        return;
    }

    // Preso subito: dopo il primo esercizio la pagina non ha piu'
    // `window.H5PStandalone` (lo sovrascrive il riproduttore stesso).
    var Lettore = window.H5PStandalone.H5P;

    // Non «attempted»: diversi tipi (Drag the Words fra questi) lo mandano
    // appena l'esercizio compare, prima di qualunque gesto.
    var VERBI = ['interacted', 'answered', 'completed', 'progressed', 'passed', 'failed', 'scored', 'responded'];
    var NUMERI = ['', 'l’esercizio', 'i due esercizi', 'i tre esercizi', 'i quattro esercizi', 'i cinque esercizi',
        'i sei esercizi', 'i sette esercizi', 'gli otto esercizi', 'i nove esercizi', 'i dieci esercizi'];
    var chiave = 'pistacchio-h5p-usati:' + location.pathname;
    var usati = {};

    try {
        usati = JSON.parse(window.sessionStorage.getItem(chiave) || '{}') || {};
    } catch (e) {
        usati = {};
    }

    function percorso(f) {
        return f.getAttribute('data-percorso');
    }

    function aggiorna() {
        var fatti = figure.filter(function (f) { return usati[percorso(f)]; }).length;
        var tutti = fatti === figure.length;

        // La frase la compone lesson-completa.js, insieme a quella del video
        // se c'e' anche lui: «Guarda il video e fai l'esercizio…» (27/10).
        if (!window.LezioneCompleta) {
            return;
        }

        window.LezioneCompleta.manca(
            'esercizi',
            tutti ? null : 'fai ' + (NUMERI[figure.length] || ('i ' + figure.length + ' esercizi')),
            figure.length > 1 ? fatti + ' su ' + figure.length : ''
        );
    }

    function usato(f) {
        if (usati[percorso(f)]) {
            return;
        }

        usati[percorso(f)] = true;

        try {
            window.sessionStorage.setItem(chiave, JSON.stringify(usati));
        } catch (e) {
            // Senza sessionStorage si ricomincia ricaricando: pazienza.
        }

        aggiorna();
    }

    // Gli eventi xAPI arrivano tutti alla pagina: l'indirizzo dell'oggetto
    // (`xAPIObjectIRI`, qui sotto) dice di quale esercizio sono. Quello di un
    // sotto-contenuto (una domanda di un Question Set) comincia allo stesso
    // modo, e conta per il suo esercizio.
    function daEvento(evento) {
        var s = evento && evento.data && evento.data.statement;
        var verbo = s && s.verb ? String(s.verb.id || '').split('/').pop() : '';
        var oggetto = s && s.object ? String(s.object.id || '') : '';

        if (VERBI.indexOf(verbo) === -1) {
            return;
        }

        figure.forEach(function (f) {
            if (oggetto.indexOf(location.origin + percorso(f)) === 0) {
                usato(f);
            }
        });
    }

    // Per i tipi che non mandano eventi: un clic o un Invio dentro l'iframe.
    function ascoltaIframe(f, iframe) {
        function aggancia() {
            var doc;

            try {
                doc = iframe.contentDocument;
            } catch (e) {
                return;
            }

            if (!doc) {
                return;
            }

            doc.addEventListener('click', function () { usato(f); }, true);
            doc.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    usato(f);
                }
            }, true);
        }

        aggancia();
        iframe.addEventListener('load', aggancia);
    }

    function nota(f, testo) {
        var n = f.querySelector('.lezione-h5p-nota');

        if (n) {
            n.textContent = testo;
            n.classList.add('lezione-h5p-errore');
        }
    }

    function avvia(i) {
        if (i >= figure.length) {
            return Promise.resolve();
        }

        var f = figure[i];
        var contenitore = f.querySelector('.h5p-contenitore');

        return new Lettore(contenitore, {
            id: 'lezione-h5p-' + (i + 1),
            h5pJsonPath: percorso(f),
            xAPIObjectIRI: location.origin + percorso(f),
            frameJs: '/assets/h5p/frame.bundle.js',
            frameCss: '/assets/h5p/styles/h5p.css',
            customCss: ['/h5p/tema.css'],
            embedType: 'iframe',
            frame: false,
            copyright: false,
            export: false,
            icon: false,
            fullScreen: false
        }).then(function () {
            var iframe = contenitore.querySelector('iframe');

            if (!agganciato && window.H5P && window.H5P.externalDispatcher) {
                agganciato = true;
                window.H5P.externalDispatcher.on('xAPI', daEvento);
            }

            if (iframe) {
                iframe.setAttribute('title', f.getAttribute('data-titolo'));
                ascoltaIframe(f, iframe);
            }
        }).catch(function (errore) {
            if (window.console) {
                window.console.error('[H5P]', percorso(f), errore);
            }

            // Un esercizio che non si carica non blocca la lezione.
            nota(f, 'L’esercizio non si è potuto caricare. Ricarica la pagina; se si ripete, avvisa il tutor.');
            usato(f);
        }).then(function () {
            return avvia(i + 1);
        });
    }

    var agganciato = false;

    aggiorna();
    avvia(0);
})();
