/**
 * I due campi dei caratteri in Impostazioni › Aspetto (21/11, Alessandro).
 *
 * Sono campi di testo con l'elenco del catalogo (`datalist`), e il browser
 * propone solo le voci che contengono il testo gia' scritto: con
 * «Comfortaa» nel campo, l'elenco aveva solo «Comfortaa» e il carattere
 * sembrava impossibile da cambiare. Quindi:
 *
 *   - entrando nel campo, il carattere scelto passa nel testo d'esempio e
 *     il campo si svuota: l'elenco torna completo, e si scrivono le prime
 *     lettere. Uscendo senza aver scelto, torna il carattere di prima.
 *     Invio con il campo vuoto salva il carattere di prima, non il vuoto;
 *   - «Torna al carattere di sistema» (applicazione) e «Torna ad Albert
 *     Sans» (pagine pubbliche) svuotano il campo davvero: con il campo
 *     vuoto vale il carattere predefinito, al prossimo «Salva». Compaiono
 *     solo quando c'e' un carattere scelto.
 *
 * Senza JavaScript i campi restano quelli di prima: si cancella il nome a
 * mano, e il comando non c'e'.
 */
(function () {
    'use strict';

    var campi = document.querySelectorAll('input[data-carattere]');

    campi.forEach(function (campo) {
        var esempio = campo.placeholder;
        var prima = null;
        var comando = document.querySelector('[data-carattere-partenza="' + campo.id + '"]');

        function aggiornaComando() {
            if (comando) {
                comando.hidden = campo.value.trim() === '' && prima === null;
            }
        }

        function rimetti() {
            if (prima !== null && campo.value.trim() === '') {
                campo.value = prima;
            }
            prima = null;
            campo.placeholder = esempio;
            aggiornaComando();
        }

        campo.addEventListener('focus', function () {
            if (campo.value.trim() === '') {
                return;
            }
            prima = campo.value;
            campo.placeholder = campo.value;
            campo.value = '';
        });

        campo.addEventListener('blur', rimetti);
        campo.addEventListener('input', aggiornaComando);

        // Invio dentro il campo manda il modulo senza farlo uscire: senza
        // questo, il campo svuotato per mostrare l'elenco si salverebbe vuoto.
        if (campo.form) {
            campo.form.addEventListener('submit', rimetti);
        }

        if (comando) {
            comando.addEventListener('click', function () {
                prima = null;
                campo.value = '';
                campo.placeholder = esempio;
                aggiornaComando();
                campo.focus();
            });
        }

        aggiornaComando();
    });
})();
