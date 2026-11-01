/**
 * «Ordina per» nelle schede delle tabelle (30/10, Alessandro, forma A).
 *
 * Sotto i 50rem di spazio ogni tabella `.tabella-schede` diventa schede, e
 * le intestazioni ordinabili erano una fila di pulsanti sopra le schede che
 * sembravano campi da compilare. Qui diventano una sola tendina, «Ordina
 * per: …», con le stesse voci e gli stessi indirizzi delle intestazioni.
 * La tendina si vede solo in modalita' schede (CSS): finche' si vede, i
 * collegamenti delle intestazioni, fuori dalla vista, escono anche dal giro
 * del tasto Tab; sopra i 50rem tornano come prima. Senza JavaScript restano
 * le intestazioni come comandi, come prima.
 */
(function () {
    'use strict';

    function testo(a) {
        var copia = a.cloneNode(true);
        copia.querySelectorAll('.sr-only, [aria-hidden="true"]').forEach(function (e) { e.remove(); });
        return copia.textContent.replace(/[▲▼↑↓]/g, '').replace(/\s+/g, ' ').trim();
    }

    var menu = [];

    document.querySelectorAll('.tabella-schede .data-table').forEach(function (tabella) {
        var voci = tabella.querySelectorAll('thead a.ordina');

        if (voci.length === 0) {
            return;
        }

        var attiva = tabella.querySelector('thead a.ordina-attiva');
        var tendina = document.createElement('details');
        tendina.className = 'dropdown schede-ordina';

        var pulsante = document.createElement('summary');
        pulsante.className = 'btn btn-secondary';
        pulsante.textContent = attiva ? 'Ordina per: ' + testo(attiva).toLowerCase() : 'Ordina per';
        tendina.appendChild(pulsante);

        var elenco = document.createElement('div');
        elenco.className = 'dropdown-menu';
        voci.forEach(function (a) {
            var voce = document.createElement('a');
            voce.href = a.getAttribute('href');
            voce.textContent = testo(a);
            if (a === attiva) {
                voce.setAttribute('aria-current', 'true');
            }
            elenco.appendChild(voce);
        });
        tendina.appendChild(elenco);

        tabella.parentNode.insertBefore(tendina, tabella);
        menu.push({ tendina: tendina, voci: voci });
    });

    function aggiorna() {
        menu.forEach(function (m) {
            var schede = m.tendina.getClientRects().length > 0;
            m.voci.forEach(function (a) {
                if (schede) {
                    a.setAttribute('tabindex', '-1');
                } else {
                    a.removeAttribute('tabindex');
                }
            });
        });
    }

    aggiorna();

    if (menu.length > 0 && 'ResizeObserver' in window) {
        new ResizeObserver(aggiorna).observe(document.documentElement);
    }
    window.addEventListener('resize', aggiorna);
})();
