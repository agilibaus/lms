/**
 * «Annulla» che rimette i valori salvati (14/10, Alessandro).
 *
 * Nelle pagine con piu' moduli separati (Messaggi email, il profilo, la
 * modifica del corso...) e nei pannelli che si aprono dentro una pagina,
 * «Annulla» e' un `<button type="reset">`: il browser rimette nei campi i
 * valori con cui la pagina e' arrivata, cioe' quelli salvati, e si resta
 * dove si e'. Funziona anche senza JavaScript. Questo script fa le tre cose
 * che il browser da solo non fa:
 *
 *   - **i contatori** dei caratteri si aggiornano: si muovono con l'evento
 *     `input`, che un reset non manda;
 *   - **l'editor della lezione** torna al testo salvato: TinyMCE tiene il
 *     testo per conto suo, e il reset del campo nascosto non lo tocca;
 *   - **il pannello si chiude**, se «Annulla» sta in un `<details>` (la
 *     risposta al giro del cerchio, la modifica di una domanda): e' un
 *     pulsante con `data-annulla-chiude`.
 *
 * Nelle pagine con un modulo solo «Annulla» e' un collegamento che esce
 * senza salvare, e qui non c'entra.
 */
(function () {
    'use strict';

    document.addEventListener('reset', function (evento) {
        var modulo = evento.target;

        // Dopo il reset, non durante: i valori tornano a posto alla fine
        // dell'evento, e prima i campi avrebbero ancora quelli vecchi.
        setTimeout(function () {
            if (window.tinymce) {
                window.tinymce.get().forEach(function (editor) {
                    if (modulo.contains(editor.getElement())) {
                        editor.resetContent();
                    }
                });
            }

            var campi = modulo.querySelectorAll('textarea, input');

            for (var i = 0; i < campi.length; i++) {
                campi[i].dispatchEvent(new Event('input', { bubbles: true }));
            }
        }, 0);
    }, true);

    document.addEventListener('click', function (evento) {
        var pulsante = evento.target.closest('[data-annulla-chiude]');

        if (pulsante === null) {
            return;
        }

        var pannello = pulsante.closest('details');

        if (pannello !== null) {
            pannello.open = false;

            var comando = pannello.querySelector(':scope > summary');

            if (comando !== null) {
                comando.focus();
            }
        }
    });
})();
