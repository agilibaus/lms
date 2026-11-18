/*
 * Il modulo di registrazione e l'informativa privacy (18/11, Alessandro).
 *
 * L'informativa si apre nella stessa scheda: chi torna al modulo con il
 * pulsante della barra (o con «indietro») non deve trovarlo vuoto. Prima di
 * uscire si salvano nome, cognome ed email nella memoria della scheda
 * (sessionStorage), al ritorno si rimettono. **Mai le password.** Si
 * cancellano appena il modulo e' inviato, e comunque chiudendo la scheda.
 *
 * Senza JavaScript, o con la memoria del browser bloccata, il modulo
 * funziona come prima: si riscrivono i campi, niente di piu'.
 */
(function () {
    'use strict';

    var CHIAVE = 'pistacchio.registrazione';
    var CAMPI = ['first_name', 'last_name', 'email'];

    var modulo = document.querySelector('form[action="/register"]');
    var link = document.querySelector('[data-privacy-registrazione]');

    if (!modulo || !link) {
        return;
    }

    function leggi() {
        try {
            return JSON.parse(window.sessionStorage.getItem(CHIAVE) || 'null');
        } catch (e) {
            return null;
        }
    }

    function scrivi(valori) {
        try {
            window.sessionStorage.setItem(CHIAVE, JSON.stringify(valori));
        } catch (e) {
            // Memoria non disponibile: si va all'informativa lo stesso.
        }
    }

    function togli() {
        try {
            window.sessionStorage.removeItem(CHIAVE);
        } catch (e) {
            // Niente da togliere.
        }
    }

    // Al ritorno: solo nei campi vuoti, per non coprire cio' che il server
    // ha rimesso dopo un errore.
    var salvati = leggi();

    if (salvati) {
        CAMPI.forEach(function (nome) {
            var campo = modulo.elements[nome];

            if (campo && campo.value === '' && typeof salvati[nome] === 'string') {
                campo.value = salvati[nome];
            }
        });
    }

    link.addEventListener('click', function () {
        var valori = {};

        CAMPI.forEach(function (nome) {
            var campo = modulo.elements[nome];
            valori[nome] = campo ? campo.value : '';
        });

        scrivi(valori);
    });

    modulo.addEventListener('submit', togli);
})();
