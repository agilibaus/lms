/**
 * Lezioni di solo video: il pulsante "Segna come completata" si sblocca
 * quando lo studente avvia il video.
 *
 * Nell'HTML il pulsante è abilitato, e lo disabilita questo script: se il
 * JavaScript non gira, o se qualcosa qui dentro fallisce, lo studente può
 * comunque concludere la lezione. Uno studente bloccato scrive a chi
 * amministra; uno che segna senza aver premuto play è solo un fastidio.
 *
 * L'avvio non si chiede al player. Con i video sul nostro server basta
 * l'evento "play" dell'elemento <video>; con Bunny e Cloudflare, che stanno
 * dentro un iframe di un altro sito, il video non viene nemmeno caricato
 * finché non si preme il pulsante di avvio nostro — quindi quel clic è il
 * segnale, e non serve parlare il protocollo di nessuno.
 *
 * Il ricordo sta in sessionStorage: ricaricando la pagina il pulsante resta
 * sbloccato, ma la cosa non segue lo studente su un altro dispositivo e non
 * finisce sul server, dove non varrebbe granché.
 */
(function () {
    'use strict';

    var box = document.getElementById('lesson-video');

    if (box === null || !box.hasAttribute('data-attende-avvio')) {
        return;
    }

    var button = document.querySelector('[data-completa]');
    var notice = document.querySelector('[data-avviso-avvio]');

    if (button === null) {
        return;
    }

    var key = 'lezione-avviata:' + (box.getAttribute('data-lesson') || '');

    function ricordato() {
        try {
            return window.sessionStorage.getItem(key) === '1';
        } catch (e) {
            // Navigazione privata o cookie di terze parti bloccati: si
            // prosegue senza memoria, non è un motivo per bloccare nessuno.
            return false;
        }
    }

    function ricorda() {
        try {
            window.sessionStorage.setItem(key, '1');
        } catch (e) {
            // Vedi sopra: il pulsante si sblocca comunque, per questa visita.
        }
    }

    function sblocca() {
        button.disabled = false;

        if (notice !== null) {
            notice.hidden = true;
        }
    }

    function blocca() {
        button.disabled = true;

        if (notice !== null) {
            notice.hidden = false;
        }
    }

    if (ricordato()) {
        return;
    }

    blocca();

    // --- video sul nostro server -------------------------------------
    var video = box.querySelector('video');

    if (video !== null) {
        video.addEventListener('play', function () {
            ricorda();
            sblocca();
        });
    }

    // --- Bunny e Cloudflare: copertina con pulsante di avvio ----------
    var start = box.querySelector('[data-avvio]');
    var startButton = box.querySelector('[data-avvia]');
    var iframe = box.querySelector('iframe[data-src]');

    if (start !== null && startButton !== null && iframe !== null) {
        startButton.addEventListener('click', function () {
            iframe.setAttribute('src', iframe.getAttribute('data-src'));
            iframe.removeAttribute('data-src');
            start.hidden = true;
            ricorda();
            sblocca();
        });
    }
}());
