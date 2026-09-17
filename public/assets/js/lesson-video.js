/**
 * Video della lezione: copertina di avvio e, dove previsto, sblocco del
 * pulsante "Segna come completata".
 *
 * Due cose distinte, di proposito. La copertina è presentazione e vale per
 * ogni video, qualunque sia il provider: senza, una lezione con video su
 * Bunny e una con video caricato avrebbero un aspetto diverso per una ragione
 * tecnica che allo studente non dice niente. Lo sblocco è una regola
 * didattica e riguarda solo le lezioni che non hanno nient'altro da leggere:
 * il riquadro lo dichiara con l'attributo data-attende-avvio.
 *
 * La copertina è nascosta nell'HTML e la scopre questo script: senza
 * JavaScript resta invisibile e il player si comporta come ha sempre fatto,
 * invece di lasciare davanti un'immagine che nessuno può togliere.
 */
(function () {
    'use strict';

    var box = document.getElementById('lesson-video');

    if (box === null) {
        return;
    }

    var facade = box.querySelector('[data-avvio]');
    var startButton = box.querySelector('[data-avvia]');
    var embed = box.querySelector('.video-embed');
    var video = box.querySelector('video');
    var iframe = box.querySelector('iframe[data-src]');

    // --- sblocco del pulsante (solo dove richiesto) -------------------

    var waits = box.hasAttribute('data-attende-avvio');
    var button = document.querySelector('[data-completa]');
    var notice = document.querySelector('[data-avviso-avvio]');
    var key = 'lezione-avviata:' + (box.getAttribute('data-lesson') || '');

    function remembered() {
        try {
            return window.sessionStorage.getItem(key) === '1';
        } catch (e) {
            // Navigazione privata o archiviazione bloccata: si prosegue senza
            // memoria, non è un motivo per bloccare nessuno.
            return false;
        }
    }

    function remember() {
        try {
            window.sessionStorage.setItem(key, '1');
        } catch (e) {
            // Vedi sopra: il pulsante si sblocca comunque per questa visita.
        }
    }

    function unlock() {
        if (button !== null) {
            button.disabled = false;
        }

        if (notice !== null) {
            notice.hidden = true;
        }
    }

    if (waits && button !== null && !remembered()) {
        button.disabled = true;

        if (notice !== null) {
            notice.hidden = false;
        }
    }

    function started() {
        remember();
        unlock();
    }

    // --- copertina ---------------------------------------------------

    function reveal() {
        facade.hidden = false;
        box.classList.add('has-facade');
    }

    function play() {
        facade.hidden = true;
        box.classList.remove('has-facade');

        if (iframe !== null) {
            // L'avvio automatico serve perché il clic sulla copertina valga
            // anche come clic sul play: altrimenti sarebbero due.
            iframe.setAttribute('src', iframe.getAttribute('data-src'));
            iframe.removeAttribute('data-src');
        } else if (video !== null) {
            var attempt = video.play();

            // Se il browser rifiuta l'avvio automatico resta il player con i
            // suoi comandi, già visibile: non si perde niente.
            if (attempt !== undefined && attempt !== null && typeof attempt.catch === 'function') {
                attempt.catch(function () {});
            }
        }

        started();
    }

    if (facade !== null && startButton !== null && embed !== null && (iframe !== null || video !== null)) {
        reveal();
        startButton.addEventListener('click', play);
    }

    // Il video sul nostro server può essere avviato anche dai comandi del
    // player, quando la copertina è già stata tolta.
    if (video !== null) {
        video.addEventListener('play', started);
    }
}());
