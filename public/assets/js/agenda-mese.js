/**
 * L'agenda del mese al telefono (30/10, Alessandro, C): sotto il calendario
 * piccolo si vede solo il giorno scelto. All'avvio quello dell'indirizzo
 * (#giorno-…), altrimenti oggi, altrimenti il primo giorno con impegni;
 * toccando un giorno del calendario si passa al suo, senza ricaricare.
 * Senza JavaScript si vedono tutti i giorni, uno sotto l'altro, e i giorni
 * del calendario portano al loro riquadro. Sul computer niente di questo si
 * vede: c'e' la griglia.
 */
(function () {
    'use strict';

    var mese = document.querySelector('.agenda-mese');
    var giorni = document.querySelectorAll('.agenda-giorno');
    var link = document.querySelectorAll('.agenda-mini-giorno');

    if (!mese || giorni.length === 0) {
        return;
    }

    function scegli(id) {
        giorni.forEach(function (g) {
            g.hidden = g.id !== id;
        });
        link.forEach(function (a) {
            var scelto = a.getAttribute('href') === '#' + id;
            a.classList.toggle('scelto', scelto);
            if (scelto) {
                a.setAttribute('aria-current', 'date');
            } else {
                a.removeAttribute('aria-current');
            }
        });
    }

    var richiesto = location.hash.slice(1);
    var iniziale = richiesto !== '' && document.getElementById(richiesto) && document.getElementById(richiesto).classList.contains('agenda-giorno')
        ? richiesto
        : (document.querySelector('.agenda-giorno.oggi') || giorni[0]).id;

    mese.setAttribute('data-scelta', '');
    scegli(iniziale);

    link.forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var id = a.getAttribute('href').slice(1);
            scegli(id);
            history.replaceState(null, '', '#' + id);
        });
    });
})();
