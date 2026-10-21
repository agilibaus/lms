/**
 * Questionario H5P (22/10): avvia il riproduttore h5p-standalone (MIT,
 * `/assets/h5p/`) nell'iframe e manda a Pistacchio il punteggio a ogni
 * «Verifica».
 *
 * IL PUNTEGGIO. H5P comunica alla pagina quello che succede nell'esercizio
 * con eventi xAPI. Contano solo «answered» e «completed» con un punteggio,
 * e solo quelli dell'esercizio principale: in un Question Set ogni domanda
 * manda il proprio «answered» (con l'identificativo del sotto-contenuto), e
 * contarli sarebbe un tentativo per domanda. Alcuni tipi mandano
 * «answered» e «completed» insieme per la stessa verifica: due eventi a
 * meno di un secondo e mezzo l'uno dall'altro sono un tentativo solo.
 *
 * Il punteggio lo calcola il browser, come ovunque si usi H5P: il server
 * controlla che i numeri abbiano senso (`App\Core\H5P::punteggio`), non
 * che siano veri. Va bene per le verifiche formative.
 */
(function () {
    'use strict';

    var contenitore = document.getElementById('h5p-contenitore');
    var esito = document.getElementById('h5p-esito');

    if (!contenitore || !window.H5PStandalone) {
        return;
    }

    var dati = contenitore.dataset;
    var ultimo = 0;

    function scrivi(testo, classe) {
        esito.textContent = testo;
        esito.className = 'h5p-esito' + (classe ? ' ' + classe : '');
    }

    function salva(raw, max) {
        var corpo = new FormData();
        corpo.append(dati.campo, dati.token);
        corpo.append('raw', String(raw));
        corpo.append('max', String(max));
        scrivi('Salvataggio del tentativo…');

        fetch(dati.salva, { method: 'POST', body: corpo, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.anteprima) {
                    scrivi(j.messaggio + ' (' + raw + ' su ' + max + ')', 'h5p-esito-anteprima');
                } else if (j.salvato) {
                    scrivi(j.messaggio, j.superato ? 'h5p-esito-superato' : 'h5p-esito-non-superato');
                } else {
                    scrivi(j.errore || 'Il tentativo non è stato salvato.', 'h5p-esito-errore');
                }
            })
            .catch(function () {
                scrivi('Il tentativo non è stato salvato: controlla la connessione e premi di nuovo la verifica.', 'h5p-esito-errore');
            });
    }

    new window.H5PStandalone.H5P(contenitore, {
        h5pJsonPath: dati.percorso,
        frameJs: '/assets/h5p/frame.bundle.js',
        frameCss: '/assets/h5p/styles/h5p.css',
        customCss: ['/h5p/tema.css'],
        embedType: 'iframe',
        frame: false,
        copyright: false,
        export: false,
        icon: false,
        // Lo schermo intero serve a Interactive Video e Course Presentation;
        // sugli esercizi provati copriva il titolo sul telefono.
        fullScreen: false
    }).then(function () {
        // Il titolo dell'iframe: e' quello che un lettore di schermo
        // annuncia entrando nell'esercizio.
        var iframe = contenitore.querySelector('iframe');

        if (iframe) {
            iframe.setAttribute('title', dati.titolo);
            iframe.setAttribute('allowfullscreen', '');
        }

        window.H5P.externalDispatcher.on('xAPI', function (evento) {
            var s = evento && evento.data && evento.data.statement;

            if (!s || !s.verb || !s.result || !s.result.score) {
                return;
            }

            var verbo = String(s.verb.id || '').split('/').pop();

            if (verbo !== 'answered' && verbo !== 'completed') {
                return;
            }

            var estensioni = (s.object && s.object.definition && s.object.definition.extensions) || {};

            if (estensioni['http://h5p.org/x-api/h5p-subContentId']) {
                return;
            }

            var adesso = Date.now();

            if (adesso - ultimo < 1500) {
                return;
            }

            ultimo = adesso;
            salva(s.result.score.raw, s.result.score.max);
        });
    }).catch(function () {
        scrivi('L’esercizio non si è potuto caricare. Ricarica la pagina; se si ripete, avvisa il tutor.', 'h5p-esito-errore');
    });
})();
