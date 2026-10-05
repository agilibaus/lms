/**
 * Il contatore delle risposte aperte.
 *
 * Mostra quanti caratteri restano, scalando mentre si scrive. Toglie
 * attrito, non abilita niente: il limite lo fa rispettare `maxlength` nel
 * browser e il taglio in `QuizController` sul server, quindi senza
 * JavaScript la pagina continua a funzionare e il contatore resta fermo
 * sul numero di partenza — che a campo vuoto e' la verita'.
 *
 * NIENTE `aria-live`. Un contatore che si annuncia a ogni tasto e' il caso
 * da manuale di regione viva usata male: coprirebbe con la propria voce
 * quello che la persona sta scrivendo. Il collegamento e'
 * `aria-describedby`, che lo fa leggere quando si entra nel campo.
 *
 * I caratteri si contano come li conta il browser per `maxlength`, cioe'
 * in unita' UTF-16: su un'emoji il conto di JavaScript e quello di
 * `maxlength` restano d'accordo fra loro. In PHP il taglio e' per
 * caratteri veri, quindi non e' mai piu' severo di quello che la persona
 * ha visto.
 */
(function () {
    'use strict';

    var campi = document.querySelectorAll('.quiz-open-answer[aria-describedby]');

    for (var i = 0; i < campi.length; i++) {
        collega(campi[i]);
    }

    function collega(campo) {
        var contatore = document.getElementById(campo.getAttribute('aria-describedby'));

        if (contatore === null) {
            return;
        }

        var massimo = parseInt(contatore.getAttribute('data-max'), 10);

        if (isNaN(massimo) || massimo <= 0) {
            return;
        }

        function aggiorna() {
            var restano = massimo - campo.value.length;

            if (restano < 0) {
                restano = 0;
            }

            contatore.textContent = restano + (restano === 1 ? ' carattere rimasto' : ' caratteri rimasti');
            // Una classe, non un colore scritto qui: il foglio di stile
            // decide com'e' fatto «quasi pieno», e cambiando tavolozza
            // cambia con lui.
            contatore.classList.toggle('quiz-open-count-basso', restano <= 100);
        }

        // Anche all'avvio, non solo al primo tasto: il browser puo'
        // ripristinare il contenuto del campo tornando indietro di pagina,
        // e in quel caso il numero di partenza sarebbe sbagliato.
        campo.addEventListener('input', aggiorna);
        aggiorna();
    }
})();
