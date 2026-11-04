/**
 * «Messaggi email» al computer (30/10, Alessandro): aprendo una voce in
 * fondo all'elenco, l'elenco a sinistra scorre fino a lei, cosi' la voce
 * aperta si vede. Scorre solo l'elenco, non la pagina, e solo se la voce
 * e' fuori vista. Al telefono l'elenco non scorre per conto suo, e non
 * succede niente.
 */
(function () {
    'use strict';

    var elenco = document.querySelector('.email-elenco');
    var aperta = elenco ? elenco.querySelector('a[aria-current="page"]') : null;

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
