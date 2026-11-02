/**
 * «Tre parole» (30/10, Alessandro): scegliendo il gruppo o il modulo la
 * pagina si ricarica da sola, con la scelta nell'indirizzo. Senza
 * JavaScript c'e' «Mostra».
 */
(function () {
    'use strict';

    var modulo = document.querySelector('[data-tp-scelte]');

    if (!modulo) {
        return;
    }

    modulo.classList.add('tp-da-sola');
    modulo.querySelectorAll('select').forEach(function (s) {
        s.addEventListener('change', function () {
            modulo.submit();
        });
    });
})();
