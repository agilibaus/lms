/**
 * I fumetti con una spiegazione breve (26/11, Alessandro): la causale di un
 * pagamento non riuscito in «Pagamenti ricevuti». Il fumetto si apre e si
 * chiude con il foglio di stile (mouse sopra, o Tab sull'etichetta); qui
 * solo quello che il foglio di stile non sa fare: **Esc lo chiude** senza
 * spostare il mouse ne' il punto in cui si e' con la tastiera, come
 * chiedono le WCAG (1.4.13). Torna apribile appena il mouse esce o si va
 * altrove con Tab.
 */
(function () {
    'use strict';

    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.suggerimento:hover, .suggerimento:focus-within').forEach(function (s) {
            s.classList.add('suggerimento-chiuso');
        });
    });

    function riapri(evento) {
        var s = evento.target.closest && evento.target.closest('.suggerimento');

        if (s && !s.contains(evento.relatedTarget)) {
            s.classList.remove('suggerimento-chiuso');
        }
    }

    document.addEventListener('mouseout', riapri);
    document.addEventListener('focusout', riapri);
})();
