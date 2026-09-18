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

    // Durata dello scorrimento delle altre schede. Chi ha chiesto meno
    // animazioni nelle impostazioni del sistema non ne vede nessuna.
    var DURATA = 160;
    var animazioniRidotte = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var offsetX = 0;
    var offsetY = 0;

    /** Porta la scheda trascinata sotto il puntatore. */
    function segui() {
        dragged.style.transform = 'translate(' + offsetX + 'px, ' + offsetY + 'px)';
    }

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

        offsetX = event.clientX - startX;
        offsetY = event.clientY - startY;
        segui();

        // La scheda sotto il puntatore: quella trascinata e' semitrasparente
        // ma sta ancora nel flusso, quindi si ignora.
        var sotto = document.elementFromPoint(event.clientX, event.clientY);
        var target = sotto === null ? null : sotto.closest('[data-corso]');

        if (target === null || target === dragged) {
            return;
        }

        var box = target.getBoundingClientRect();
        var dopo = (event.clientX - box.left) > box.width / 2;

        sposta(dopo ? target.nextSibling : target);
    });

    /**
     * Sposta la scheda trascinata prima dell'elemento indicato, facendo
     * scorrere le altre invece di farle saltare.
     *
     * Si misura dove sono prima, si cambia l'ordine, si misura dove sono
     * finite: ogni scheda viene riportata otticamente al punto di partenza e
     * poi lasciata scivolare a zero. La scheda trascinata e' l'eccezione —
     * quella deve restare sotto il puntatore, non scorrere.
     */
    function sposta(riferimento) {
        var elenco = cards();
        var prima = elenco.map(function (card) {
            return card.getBoundingClientRect();
        });
        var primaTrascinata = dragged.getBoundingClientRect();

        grid.insertBefore(dragged, riferimento);

        // La scheda trascinata ha cambiato posto nel flusso: si sposta
        // l'origine del calcolo, cosi' visivamente non si muove di un pixel.
        var dopoTrascinata = dragged.getBoundingClientRect();
        startX += dopoTrascinata.left - primaTrascinata.left + offsetX;
        startY += dopoTrascinata.top - primaTrascinata.top + offsetY;
        offsetX = 0;
        offsetY = 0;
        segui();

        if (animazioniRidotte) {
            return;
        }

        elenco.forEach(function (card, indice) {
            if (card === dragged) {
                return;
            }

            var dx = prima[indice].left - card.getBoundingClientRect().left;
            var dy = prima[indice].top - card.getBoundingClientRect().top;

            if (dx === 0 && dy === 0) {
                return;
            }

            card.style.transition = 'none';
            card.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';

            // Due fotogrammi: il primo applica la posizione di partenza, il
            // secondo fa partire lo scorrimento. Con uno solo il browser
            // accorpa le due cose e l'animazione non si vede.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    card.style.transition = 'transform ' + DURATA + 'ms ease';
                    card.style.transform = '';
                });
            });
        });
    }

    function fine(event) {
        if (dragged === null) {
            return;
        }

        var eraTrascinata = moved;
        dragged.classList.remove('is-dragging');

        // Torna al suo posto scivolando, invece di scattarci.
        if (eraTrascinata && !animazioniRidotte) {
            dragged.style.transition = 'transform ' + DURATA + 'ms ease';
        }

        dragged.style.transform = '';
        offsetX = 0;
        offsetY = 0;
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
