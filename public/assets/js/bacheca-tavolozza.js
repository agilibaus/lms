/**
 * La tavolozza dei colori delle categorie della bacheca (17/10).
 *
 * Senza questo script la tavolozza funziona lo stesso: e' un `details` con
 * 64 pulsanti di scelta, si apre, si sceglie e si salva. Lo script toglie
 * attrito: il pulsante mostra subito il colore e il nome scelti, la
 * tavolozza si chiude dopo il clic, con Esc e toccando fuori. Con le frecce
 * si passa da un colore all'altro senza chiuderla.
 */
(function () {
    'use strict';

    if (window.bachecaTavolozzaPronta) {
        return;
    }

    window.bachecaTavolozzaPronta = true;

    document.addEventListener('change', function (e) {
        var scelta = e.target;

        if (!scelta.matches || !scelta.matches('.bacheca-tavolozza input[type="radio"]')) {
            return;
        }

        var tavolozza = scelta.closest('.bacheca-tavolozza');
        tavolozza.querySelector('.bacheca-tavolozza-campione').style.background = scelta.value;
        tavolozza.querySelector('.bacheca-tavolozza-nome').textContent = scelta.getAttribute('aria-label');
        tavolozza.querySelector('.bacheca-tavolozza-scelto').textContent = scelta.getAttribute('aria-label') + ' di 8';
    });

    // Chiude dopo un clic o un tocco su un colore, non dopo le frecce.
    document.addEventListener('pointerup', function (e) {
        var quadro = e.target.closest ? e.target.closest('.bacheca-tavolozza-q') : null;

        if (quadro !== null) {
            var tavolozza = quadro.closest('.bacheca-tavolozza');
            setTimeout(function () {
                tavolozza.open = false;
                tavolozza.querySelector('summary').focus();
            }, 0);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }

        var tavolozza = e.target.closest ? e.target.closest('.bacheca-tavolozza[open]') : null;

        if (tavolozza !== null) {
            tavolozza.open = false;
            tavolozza.querySelector('summary').focus();
        }
    });

    document.addEventListener('click', function (e) {
        document.querySelectorAll('.bacheca-tavolozza[open]').forEach(function (t) {
            if (!t.contains(e.target)) {
                t.open = false;
            }
        });
    });
})();
