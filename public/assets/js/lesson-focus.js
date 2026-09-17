/**
 * Modalità senza distrazioni per il video della lezione.
 *
 * La sceglie lo studente, non si attiva da sola, e il pulsante per tornare
 * alla pagina normale resta sempre visibile in alto: nessuna scomparsa
 * automatica dopo qualche secondo, che costringerebbe a muovere il mouse per
 * capire come si esce.
 *
 * Il riquadro del video non viene mai spostato nell'albero del documento:
 * cambiano solo le classi. Un iframe spostato si ricarica, e chi è a metà
 * lezione ricomincerebbe da capo.
 */
(function () {
    'use strict';

    var wrapper = document.getElementById('lesson-video');

    if (wrapper === null) {
        return;
    }

    var enter = wrapper.querySelector('[data-focus-enter]');
    var exit = wrapper.querySelector('[data-focus-exit]');

    if (enter === null || exit === null) {
        return;
    }

    // I pulsanti stanno nell'HTML nascosti: senza JavaScript la pagina resta
    // quella di prima, invece di mostrare comandi che non fanno niente.
    //
    // Se davanti al video c'è ancora la copertina, il player è nascosto e
    // allargarlo non mostrerebbe niente: il pulsante compare quando il video
    // parte. La classe la mette lesson-video.js, caricato prima di questo.
    if (wrapper.classList.contains('has-facade')) {
        wrapper.addEventListener('lezione:video-avviato', function () {
            enter.hidden = false;
        }, { once: true });
    } else {
        enter.hidden = false;
    }

    function open() {
        wrapper.classList.add('is-focus');
        document.body.classList.add('has-focus-video');
        exit.hidden = false;
        enter.hidden = true;
        exit.focus();
        document.addEventListener('keydown', onKey);
    }

    function close() {
        wrapper.classList.remove('is-focus');
        document.body.classList.remove('has-focus-video');
        exit.hidden = true;
        enter.hidden = false;
        enter.focus();
        document.removeEventListener('keydown', onKey);
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    enter.addEventListener('click', open);
    exit.addEventListener('click', close);
}());
