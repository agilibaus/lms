/**
 * «Messaggi email» al computer (30/10, Alessandro): aprendo una voce,
 * l'elenco a sinistra scorre fino a lei, cosi' la voce aperta si vede.
 *
 * All'apertura l'elenco comincia sotto il titolo della pagina, e il suo
 * `max-height` (lo schermo meno 2 rem, pensato per quando resta fermo in
 * alto) lo faceva finire oltre il fondo dello schermo: una voce poteva stare
 * dentro l'elenco ma fuori dallo schermo (1536×864, 31/10). Ora l'elenco e'
 * alto al massimo lo spazio che ha davvero, dalla sua cima al fondo dello
 * schermo, ricalcolato quando la pagina scorre o la finestra cambia; e la
 * voce aperta finisce a meta' di quello spazio. La pagina non si muove. Al
 * telefono l'elenco non e' fermo in alto, e non succede niente.
 */
(function () {
    'use strict';

    var elenco = document.querySelector('.email-elenco');

    if (!elenco || window.getComputedStyle(elenco).position !== 'sticky') {
        return;
    }

    var margine = 16;

    function adatta() {
        var cima = Math.max(elenco.getBoundingClientRect().top, 0);
        elenco.style.maxHeight = Math.max(160, window.innerHeight - cima - margine) + 'px';
    }

    adatta();
    window.addEventListener('scroll', adatta, { passive: true });
    window.addEventListener('resize', adatta);

    var aperta = elenco.querySelector('a[aria-current="page"]');

    if (!aperta || elenco.scrollHeight <= elenco.clientHeight) {
        return;
    }

    // La posizione della voce dentro l'elenco, dalle posizioni sullo schermo.
    var cima = aperta.getBoundingClientRect().top - elenco.getBoundingClientRect().top + elenco.scrollTop;
    var fondo = cima + aperta.offsetHeight;

    if (cima < elenco.scrollTop || fondo > elenco.scrollTop + elenco.clientHeight) {
        elenco.scrollTop = Math.max(0, cima - (elenco.clientHeight - aperta.offsetHeight) / 2);
    }
})();
