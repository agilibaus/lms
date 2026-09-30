/**
 * Tendine `details.dropdown`.
 *
 * La tendina funziona da sola: `details`/`summary` si apre e si chiude senza
 * JavaScript e si usa da tastiera. Questo script aggiunge le due cose che
 * l'HTML non fa — chiusura con Esc e con un clic fuori — e nient'altro.
 * Toglie, non abilita: senza JavaScript resta aperta finche' non la si
 * richiude con un clic sul pulsante, che e' scomodo ma non blocca nessuno
 * (pistacchio-lms.md Sezione 4).
 *
 * Gli ascoltatori stanno sul documento, non sulle singole tendine: cosi'
 * valgono anche per quelle che dovessero comparire in altre pagine.
 */
(function () {
    'use strict';

    function chiudiTutte(tranne) {
        var tendine = document.querySelectorAll('details.dropdown[open]');

        for (var i = 0; i < tendine.length; i++) {
            if (tendine[i] !== tranne) {
                tendine[i].open = false;
            }
        }
    }

    // Un clic fuori chiude. Il clic dentro no, altrimenti si chiuderebbe
    // prima che il collegamento faccia il suo mestiere.
    document.addEventListener('click', function (evento) {
        var dentro = evento.target instanceof Element
            ? evento.target.closest('details.dropdown')
            : null;

        chiudiTutte(dentro);
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape') {
            return;
        }

        var aperta = document.querySelector('details.dropdown[open]');

        if (aperta === null) {
            return;
        }

        aperta.open = false;

        // Il fuoco torna al pulsante che l'aveva aperta: chiudendo con Esc,
        // senza questo, si ritroverebbe in cima alla pagina.
        var comando = aperta.querySelector('summary');

        if (comando !== null) {
            comando.focus();
        }
    });
})();
