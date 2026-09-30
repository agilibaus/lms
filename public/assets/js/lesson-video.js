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
    // Possono essere due: "Riprendi da 12:04" e "Guarda da capo". Ciascuno
    // porta in data-da il secondo da cui far partire il video.
    var startButtons = box.querySelectorAll('[data-avvia]');
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
        // Riprendere dal minuto 7 conta come avvio: chi torna su una lezione
        // gia' cominciata non deve rifarla da capo per sbloccare il pulsante.
        remember();
        unlock();

        // Lo sa anche chi si occupa della modalità senza distrazioni: prima
        // dell'avvio il player è nascosto dietro la copertina, e allargarlo
        // darebbe una pagina nera attorno a un riquadro che non c'è.
        box.dispatchEvent(new CustomEvent('lezione:video-avviato'));
    }

    // --- copertina ---------------------------------------------------

    function reveal() {
        facade.hidden = false;
        box.classList.add('has-facade');
    }

    function play(from) {
        var start = Math.max(0, parseInt(from, 10) || 0);

        facade.hidden = true;
        box.classList.remove('has-facade');

        if (iframe !== null) {
            var src = iframe.getAttribute('data-src');

            // Il punto di partenza si passa nell'indirizzo con "t=", cioè
            // prima che il player esista: è il modo che non dipende
            // dall'API. Se poi l'API risponde, lesson-tracking.js la userà
            // per i salti successivi; se non risponde, la ripresa funziona
            // lo stesso — è la regola di questo lavoro, il peggio che può
            // succedere è il comportamento di prima.
            if (start > 0) {
                src += (src.indexOf('?') === -1 ? '?' : '&') + 't=' + start;
            }

            // L'avvio automatico serve perché il clic sulla copertina valga
            // anche come clic sul play: altrimenti sarebbero due.
            iframe.setAttribute('src', src);
            iframe.removeAttribute('data-src');
        } else if (video !== null) {
            if (start > 0) {
                try {
                    video.currentTime = start;
                } catch (e) {
                    // Metadati non ancora pronti: riparte da capo, che è il
                    // comportamento di prima.
                }
            }

            var attempt = video.play();

            // Se il browser rifiuta l'avvio automatico resta il player con i
            // suoi comandi, già visibile: non si perde niente.
            if (attempt !== undefined && attempt !== null && typeof attempt.catch === 'function') {
                attempt.catch(function () {});
            }
        }

        started();
    }

    if (facade !== null && startButtons.length > 0 && embed !== null && (iframe !== null || video !== null)) {
        reveal();

        Array.prototype.forEach.call(startButtons, function (btn) {
            btn.addEventListener('click', function () {
                play(btn.getAttribute('data-da'));
            });
        });
    }

    // Il video sul nostro server può essere avviato anche dai comandi del
    // player, quando la copertina è già stata tolta.
    if (video !== null) {
        video.addEventListener('play', started);
    }
}());
