/**
 * «Nuovo questionario» (30/10): la prima domanda serve solo a un
 * questionario a domande. Scegliendo «Contenuto H5P» il riquadro sparisce
 * e si spegne, cosi' non si invia e il testo obbligatorio non ferma il
 * caricamento del file. Senza JavaScript resta visibile; nella pagina della
 * verifica finale la scelta non c'e' e il riquadro resta sempre.
 */
(function () {
    'use strict';

    var riquadro = document.querySelector('[data-prima-domanda]');
    var scelte = document.querySelectorAll('input[name="kind"]');

    if (!riquadro || scelte.length === 0) {
        return;
    }

    function aggiorna() {
        var h5p = document.querySelector('input[name="kind"][value="h5p"]');
        var spento = !!h5p && h5p.checked;
        riquadro.hidden = spento;
        riquadro.disabled = spento;
    }

    scelte.forEach(function (s) { s.addEventListener('change', aggiorna); });
    aggiorna();
})();
