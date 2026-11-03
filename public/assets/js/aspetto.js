/**
 * «Aspetto» al telefono (30/10, Alessandro, A): le sezioni partono chiuse,
 * e si aprono una alla volta con un tocco sul titolo. Al computer restano
 * aperte, come prima. Senza JavaScript restano aperte anche al telefono.
 */
(function () {
    'use strict';

    if (!window.matchMedia('(max-width: 40rem)').matches) {
        return;
    }

    document.querySelectorAll('details.aspetto-sezione').forEach(function (d) {
        d.open = false;
    });
})();
