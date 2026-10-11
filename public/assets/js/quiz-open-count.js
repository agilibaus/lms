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
 *
 * I campi con `data-conta="testo"` (obiettivo e descrizione del corso,
 * obiettivo del modulo, 11/10) accettano qualche tag HTML e il limite conta
 * solo il testo visibile: niente `maxlength`, che conterebbe anche i tag.
 * Il conto e' lo stesso di `TestoBreve::caratteri()` sul server — via i tag,
 * le entita' lette come un carattere, un a capo per uno, caratteri veri e
 * non unita' UTF-16 — e oltre il limite il contatore dice quanti caratteri
 * sono in piu': il campo non ferma la scrittura, e a salvare ci pensa il
 * server, che rifiuta e ripropone il testo.
 */
(function () {
    'use strict';

    var campi = document.querySelectorAll('.quiz-open-answer[aria-describedby]');

    for (var i = 0; i < campi.length; i++) {
        collega(campi[i]);
    }

    function collega(campo) {
        // Una pagina puo' caricare lo script piu' di una volta (la modifica
        // del corso: la descrizione e il benvenuto del tutor stanno in due
        // parti separate, e ciascuna lo porta con se'). Ogni campo si
        // collega una volta sola.
        if (campo.hasAttribute('data-contato')) {
            return;
        }

        campo.setAttribute('data-contato', '');

        // Il contatore e' il **primo** degli id in `aria-describedby`: il
        // campo della presentazione nel profilo ha anche il testo di aiuto,
        // e la lista intera non e' un id.
        var contatore = document.getElementById(campo.getAttribute('aria-describedby').split(/\s+/)[0]);

        if (contatore === null) {
            return;
        }

        var massimo = parseInt(contatore.getAttribute('data-max'), 10);

        if (isNaN(massimo) || massimo <= 0) {
            return;
        }

        var soloTesto = campo.getAttribute('data-conta') === 'testo';
        var lettore = document.createElement('textarea');

        // «1.200», come lo scrive il server: `toLocaleString('it-IT')` non
        // separa le migliaia sotto i 10.000 e scriverebbe «1200».
        function migliaia(n) {
            return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function visibili(valore) {
            // Come in PHP: un tag comincia con una lettera dopo «<» o «</».
            lettore.innerHTML = valore.replace(/\r\n/g, '\n').replace(/<\/?[a-zA-Z][^>]*>/g, '')
                .replace(/</g, '&lt;');
            return Array.from(lettore.value).length;
        }

        function aggiorna() {
            var restano = massimo - (soloTesto ? visibili(campo.value) : campo.value.length);

            if (restano < 0 && soloTesto) {
                var oltre = -restano;
                contatore.textContent = migliaia(oltre) + (oltre === 1 ? ' carattere in più' : ' caratteri in più');
                contatore.classList.add('quiz-open-count-basso');

                return;
            }

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
