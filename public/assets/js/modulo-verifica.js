/**
 * «Nuovo modulo» e «Modifica modulo» (28/10): con «Verifica finale» accesa
 * sparisce «Questionario obbligatorio», che su una verifica non ha senso
 * (dopo di lei non c'e' nessun modulo da bloccare). Senza JavaScript
 * restano tutte e due, e il server salva comunque il secondo spento.
 */
(function () {
    'use strict';

    var verifica = document.querySelector('[data-verifica-finale]');
    var obbligatorio = document.querySelector('[data-questionario-obbligatorio]');

    if (!verifica || !obbligatorio) {
        return;
    }

    function aggiorna() {
        obbligatorio.hidden = verifica.checked;
    }

    verifica.addEventListener('change', aggiorna);
    aggiorna();
})();
