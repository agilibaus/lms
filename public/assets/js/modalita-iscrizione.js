/*
 * Modulo del corso: i campi del pagamento (prezzo, link, identificativo) si
 * vedono solo con «A pagamento» scelto (06/11). Senza JavaScript restano
 * visibili, e con un'altra modalita' il server non li legge.
 */
(function () {
    var scelta = document.getElementById('enrollment_mode');
    var campi = document.getElementById('campi-pagamento');

    if (!scelta || !campi) {
        return;
    }

    function aggiorna() {
        campi.hidden = scelta.value !== 'paid';
    }

    scelta.addEventListener('change', aggiorna);
    // Dopo «Annulla» (un reset) il valore torna quello salvato.
    var modulo = scelta.form;
    if (modulo) {
        modulo.addEventListener('reset', function () {
            setTimeout(aggiorna, 0);
        });
    }
    aggiorna();
}());
