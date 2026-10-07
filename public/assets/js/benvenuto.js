/*
 * Il benvenuto del tutor nella pagina del corso (07/10).
 *
 * Una cosa sola: quando l'audio arriva in fondo, lo dice al server, e dalla
 * visita successiva il benvenuto e' ridotto a una riga. Una volta per pagina.
 * Senza questo script il benvenuto funziona lo stesso, e si riduce dopo tre
 * visite: e' l'altra meta' della regola.
 */
(function () {
    'use strict';

    var audio = document.querySelector('audio[data-ascoltato]');

    if (!audio || !window.fetch) {
        return;
    }

    audio.addEventListener('ended', function () {
        var dati = new URLSearchParams();
        dati.append('_token', audio.getAttribute('data-token') || '');

        fetch(audio.getAttribute('data-ascoltato'), {
            method: 'POST',
            body: dati,
            credentials: 'same-origin',
        }).catch(function () {
            // Non riuscito: si ridurra' comunque dopo tre visite.
        });
    }, { once: true });
})();
