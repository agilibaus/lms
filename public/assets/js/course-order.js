/**
 * Riordino delle schede corso con il trascinamento.
 *
 * È un'aggiunta, non l'unico modo: le frecce sulla scheda restano sempre, e
 * sono la via che funziona senza JavaScript, da tastiera e su un touch screen,
 * dove trascinare in una griglia è scomodo. Qui si migliora l'esperienza di
 * chi ha un mouse, senza togliere niente agli altri.
 *
 * L'ordine nuovo si manda al server senza ricaricare la pagina: dopo un
 * trascinamento le schede sono già al loro posto, e un ricaricamento le
 * farebbe sobbalzare sotto le mani di chi sta lavorando.
 */
(function () {
    'use strict';

    var grid = document.querySelector('[data-riordinabile]');

    if (grid === null) {
        return;
    }

    var token = grid.getAttribute('data-csrf') || '';
    var cards = [].slice.call(grid.querySelectorAll('[data-corso]'));
    var dragged = null;

    // La maniglia dice che si può trascinare: senza, nessuno lo scoprirebbe.
    [].forEach.call(grid.querySelectorAll('.course-drag-handle'), function (handle) {
        handle.hidden = false;
    });

    function salva() {
        var ids = [].slice.call(grid.querySelectorAll('[data-corso]')).map(function (card) {
            return card.getAttribute('data-corso');
        });

        var body = new URLSearchParams();
        body.set('ids', ids.join(','));
        body.set('_token', token);

        fetch('/admin/courses/ordine', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('risposta ' + response.status);
            }

            avvisa('Ordine salvato.');
        }).catch(function () {
            // Non si finge che sia andata: chi ha spostato le schede deve
            // sapere che ricaricando le ritroverà com'erano.
            avvisa('Ordine non salvato: ricarica la pagina e riprova.', true);
        });
    }

    var notice = null;

    function avvisa(testo, errore) {
        if (notice === null) {
            notice = document.createElement('p');
            notice.className = 'order-notice';
            notice.setAttribute('role', 'status');
            grid.parentNode.insertBefore(notice, grid);
        }

        notice.textContent = testo;
        notice.classList.toggle('order-notice-error', errore === true);
    }

    cards.forEach(function (card) {
        card.setAttribute('draggable', 'true');

        card.addEventListener('dragstart', function (event) {
            dragged = card;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            // Firefox non avvia il trascinamento senza dati impostati.
            event.dataTransfer.setData('text/plain', card.getAttribute('data-corso'));
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('is-dragging');

            if (dragged !== null) {
                dragged = null;
                salva();
            }
        });

        card.addEventListener('dragover', function (event) {
            if (dragged === null || dragged === card) {
                return;
            }

            // Serve a permettere il rilascio.
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            // Prima o dopo, secondo da che parte si sta entrando: così la
            // scheda trascinata segue il puntatore invece di saltare.
            var box = card.getBoundingClientRect();
            var dopo = (event.clientX - box.left) > box.width / 2;

            grid.insertBefore(dragged, dopo ? card.nextSibling : card);
        });

        card.addEventListener('drop', function (event) {
            event.preventDefault();
        });

        // Un clic sulla scheda apre il corso: dopo un trascinamento no.
        card.addEventListener('click', function (event) {
            if (card.classList.contains('is-dragging')) {
                event.preventDefault();
            }
        });
    });
}());
