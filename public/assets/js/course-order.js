/**
 * Riordino delle schede corso trascinandole.
 *
 * Non usa il trascinamento nativo dell'HTML: le schede sono collegamenti, e
 * il browser li tratta come tali, avviando il proprio trascinamento del link
 * invece del nostro. Qui si seguono gli eventi del puntatore, che si
 * comportano allo stesso modo ovunque.
 *
 * Solo con il mouse: su un touch screen bloccare lo scorrimento della pagina
 * per permettere il trascinamento renderebbe la pagina difficile da leggere,
 * che e' quello che si fa piu' spesso.
 */
(function () {
    'use strict';

    var grid = document.querySelector('[data-riordinabile]');

    if (grid === null || typeof window.PointerEvent === 'undefined') {
        return;
    }

    var token = grid.getAttribute('data-csrf') || '';
    var dragged = null;
    var moved = false;
    var startX = 0;
    var startY = 0;

    function cards() {
        return [].slice.call(grid.querySelectorAll('[data-corso]'));
    }

    function ordineAttuale() {
        return cards().map(function (card) {
            return card.getAttribute('data-corso');
        }).join(',');
    }

    var ordineSalvato = ordineAttuale();

    function salva() {
        var adesso = ordineAttuale();

        if (adesso === ordineSalvato) {
            return;
        }

        ordineSalvato = adesso;

        var body = new URLSearchParams();
        body.set('ids', adesso);
        body.set('_token', token);

        fetch('/admin/courses/ordine', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).catch(function () {
            // Il salvataggio e' silenzioso: qui non si mostra niente.
        });
    }

    grid.addEventListener('pointerdown', function (event) {
        if (event.pointerType !== 'mouse' || event.button !== 0) {
            return;
        }

        var card = event.target.closest('[data-corso]');

        if (card === null) {
            return;
        }

        dragged = card;
        moved = false;
        startX = event.clientX;
        startY = event.clientY;
        // La cattura del puntatore si prende solo quando il trascinamento
        // comincia davvero: presa qui, il clic finirebbe alla griglia invece
        // che alla scheda, e un clic semplice non aprirebbe piu' il corso.
    });

    grid.addEventListener('pointermove', function (event) {
        if (dragged === null) {
            return;
        }

        // Soglia: un clic non perfettamente fermo non deve diventare un
        // trascinamento, altrimenti aprire un corso diventa difficile.
        if (!moved) {
            if (Math.abs(event.clientX - startX) < 6 && Math.abs(event.clientY - startY) < 6) {
                return;
            }

            moved = true;
            dragged.classList.add('is-dragging');
            grid.setPointerCapture(event.pointerId);
        }

        event.preventDefault();

        // La scheda sotto il puntatore: quella trascinata e' semitrasparente
        // ma sta ancora nel flusso, quindi si ignora.
        var sotto = document.elementFromPoint(event.clientX, event.clientY);
        var target = sotto === null ? null : sotto.closest('[data-corso]');

        if (target === null || target === dragged) {
            return;
        }

        var box = target.getBoundingClientRect();
        var dopo = (event.clientX - box.left) > box.width / 2;

        grid.insertBefore(dragged, dopo ? target.nextSibling : target);
    });

    function fine(event) {
        if (dragged === null) {
            return;
        }

        var eraTrascinata = moved;
        dragged.classList.remove('is-dragging');
        dragged = null;

        if (grid.hasPointerCapture && grid.hasPointerCapture(event.pointerId)) {
            grid.releasePointerCapture(event.pointerId);
        }

        if (eraTrascinata) {
            salva();

            // Il clic arriva dopo il rilascio: senza questo, finito il
            // trascinamento si aprirebbe il corso.
            grid.addEventListener('click', function blocca(e) {
                e.preventDefault();
                e.stopPropagation();
                grid.removeEventListener('click', blocca, true);
            }, true);
        }

        moved = false;
    }

    grid.addEventListener('pointerup', fine);
    grid.addEventListener('pointercancel', fine);

    // Il trascinamento nativo del link darebbe un'immagine fantasma e
    // confonderebbe il nostro.
    grid.addEventListener('dragstart', function (event) {
        event.preventDefault();
    });
}());
