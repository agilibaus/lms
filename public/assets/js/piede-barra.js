/*
 * Il piede della barra laterale (19/11, Alessandro): nome ed «Esci» su una
 * riga sola se ci stanno, altrimenti «Esci» sotto il nome.
 *
 * Con il solo foglio di stile non si puo': un elemento che va a capo da
 * solo finisce a destra, non sotto il nome. Qui si prova la riga sola
 * (classe `una-riga`) e la si tiene se il nome si legge intero e «Esci»
 * resta dentro il piede; lo spazio di almeno 16 px fra i due lo da' il
 * foglio di stile. Si ridecide quando cambia la larghezza del piede
 * (finestra, barra che si apre al telefono) e quando il carattere ha finito
 * di caricarsi. Senza questo script restano le due righe.
 */
(function () {
    'use strict';

    var piede = document.querySelector('.sidebar-footer');

    if (!piede) {
        return;
    }

    var nome = piede.querySelector('.user-name');
    var esci = piede.querySelector('form');

    function decidi() {
        if (!nome || !esci || piede.clientWidth === 0) {
            return;
        }

        piede.classList.add('una-riga');

        var dentro = piede.getBoundingClientRect();
        var stile = getComputedStyle(piede);
        var destra = dentro.right - parseFloat(stile.paddingRight) - parseFloat(stile.borderRightWidth);
        var ciSta = nome.scrollWidth <= nome.clientWidth && esci.getBoundingClientRect().right <= destra + 0.5;

        if (!ciSta) {
            piede.classList.remove('una-riga');
        }
    }

    var larghezza = -1;

    if ('ResizeObserver' in window) {
        new ResizeObserver(function () {
            // Solo quando cambia la larghezza: l'altezza la cambia decidi().
            if (piede.clientWidth !== larghezza) {
                larghezza = piede.clientWidth;
                decidi();
            }
        }).observe(piede);
    } else {
        window.addEventListener('resize', decidi);
    }

    decidi();

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(decidi);
    }
})();
