/**
 * «Modifica modulo» (28/10): con «Verifica finale» accesa sparisce
 * «Questionario obbligatorio», che su una verifica non ha senso (dopo di lei
 * non c'e' nessun modulo da bloccare), e dal 30/10 anche l'obiettivo, che la
 * verifica non ha. Senza JavaScript restano, e il server salva comunque il
 * primo spento e il secondo vuoto.
 */
(function () {
    'use strict';

    var verifica = document.querySelector('[data-verifica-finale]');
    var obbligatorio = document.querySelector('[data-questionario-obbligatorio]');
    // Il campo dell'obiettivo (etichetta, contatore, area di testo) e il suo
    // «Tag HTML consentiti», che gli sta accanto. Niente riquadro attorno:
    // toglierebbe il campo dai 780 px delle aree di testo. E `display`, non
    // `hidden`: il campo e' un flex, e vincerebbe sull'attributo.
    var campo = document.getElementById('objective');
    var obiettivo = campo ? [campo.closest('.campo-contato'), document.getElementById('objective-aiuto')] : [];

    if (!verifica || !obbligatorio) {
        return;
    }

    function aggiorna() {
        obbligatorio.hidden = verifica.checked;

        obiettivo.forEach(function (parte) {
            if (parte) {
                parte.style.display = verifica.checked ? 'none' : '';
            }
        });
    }

    verifica.addEventListener('change', aggiorna);
    aggiorna();
})();
