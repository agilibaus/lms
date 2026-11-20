/**
 * La barra delle righe selezionate nell'elenco Utenti (20/11, Alessandro,
 * variante B del mockup).
 *
 * Senza questo script la pagina funziona lo stesso: le caselle stanno in un
 * modulo, e la barra con «Modifica», «Elimina» e «Annulla» resta ferma in
 * fondo alla tabella. Lo script fa quattro cose:
 *
 *   - la barra **compare in fondo allo schermo solo quando c'e' almeno una
 *     riga selezionata** (`con-selezione` sul modulo, `data-js` per lo stile
 *     galleggiante);
 *   - **il conto**: «1 utente selezionato», «3 utenti selezionati», letto
 *     anche dai lettori di schermo (`aria-live`);
 *   - **«Modifica» solo con una riga**: con piu' righe non avrebbe senso, e
 *     «Elimina» diventa «Elimina 3 utenti»;
 *   - **«Seleziona tutti»** nell'intestazione, che senza script resta
 *     nascosto, con lo stato a meta' quando le righe sono solo alcune.
 *
 * «Annulla» e' un `<button type="reset">`: toglie la selezione anche senza
 * script; qui si aggiorna la barra dopo il reset.
 */
(function () {
    'use strict';

    var modulo = document.querySelector('[data-selezione]');

    if (!modulo) {
        return;
    }

    var caselle = Array.prototype.slice.call(modulo.querySelectorAll('tbody input[type="checkbox"][name="ids[]"]'));
    var tutti = modulo.querySelector('[data-seleziona-tutti]');
    var conto = modulo.querySelector('[data-conto]');
    var modifica = modulo.querySelector('[data-modifica]');
    var eliminaTesto = modulo.querySelector('[data-elimina-testo]');

    modulo.setAttribute('data-js', '');

    var tuttiEtichetta = modulo.querySelector('[data-seleziona-tutti-etichetta]');

    if (tuttiEtichetta) {
        tuttiEtichetta.hidden = false;
    }

    function aggiorna() {
        var scelte = caselle.filter(function (c) { return c.checked; }).length;

        modulo.classList.toggle('con-selezione', scelte > 0);
        conto.textContent = scelte === 1 ? '1 utente selezionato' : scelte + ' utenti selezionati';
        modifica.hidden = scelte !== 1;
        eliminaTesto.textContent = scelte > 1 ? 'Elimina ' + scelte + ' utenti' : 'Elimina';

        if (tutti) {
            tutti.checked = scelte > 0 && scelte === caselle.length;
            tutti.indeterminate = scelte > 0 && scelte < caselle.length;
        }
    }

    modulo.addEventListener('change', function (evento) {
        if (evento.target === tutti) {
            caselle.forEach(function (c) { c.checked = tutti.checked; });
        }

        aggiorna();
    });

    modulo.addEventListener('reset', function () {
        // I valori tornano a posto alla fine dell'evento, non durante.
        setTimeout(aggiorna, 0);
    });

    // Il browser puo' ricordare le caselle spuntate tornando indietro.
    aggiorna();
})();
