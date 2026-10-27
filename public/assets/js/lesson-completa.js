/**
 * «Segna come completata» (27/10): il pulsante e la frase sotto, per le due
 * regole che lo tengono spento. Ciascuna sta nel suo file e dice qui che
 * cosa manca: il video da guardare (lesson-video.js, basta avviarlo) e gli
 * esercizi H5P da fare (h5p-lezione.js, una qualunque interazione). Questo
 * file mette insieme la frase, una sola anche quando mancano tutte e due:
 *
 *   «Guarda il video e fai i due esercizi per poter segnare la lezione come
 *    completata (1 su 2).»
 *
 * Senza questo file, o senza JavaScript, il pulsante resta usabile: le due
 * regole stanno nel browser apposta, perche' uno studente bloccato da un
 * video che non si carica e' un danno vero.
 */
(function () {
    'use strict';

    var ORDINE = ['video', 'esercizi'];
    var manca = {};

    function aggiorna() {
        var pulsante = document.querySelector('[data-completa]');
        var avviso = document.querySelector('[data-avviso-completa]');
        var parti = [];
        var conto = '';

        ORDINE.forEach(function (chiave) {
            if (manca[chiave]) {
                parti.push(manca[chiave].testo);
                conto = manca[chiave].conto || conto;
            }
        });

        if (pulsante) {
            pulsante.disabled = parti.length > 0;
        }

        if (!avviso) {
            return;
        }

        avviso.hidden = parti.length === 0;

        if (parti.length > 0) {
            var frase = parti.join(' e ');
            avviso.textContent = frase.charAt(0).toUpperCase() + frase.slice(1)
                + ' per poter segnare la lezione come completata'
                + (conto ? ' (' + conto + ')' : '') + '.';
        }
    }

    window.LezioneCompleta = {
        /**
         * @param {string} chiave  'video' o 'esercizi'
         * @param {?string} testo  che cosa manca («guarda il video»), o null se niente
         * @param {string=} conto  «1 su 2», in fondo alla frase
         */
        manca: function (chiave, testo, conto) {
            if (testo) {
                manca[chiave] = { testo: testo, conto: conto || '' };
            } else {
                delete manca[chiave];
            }

            aggiorna();
        }
    };
})();
