/**
 * Riordino delle schede corso trascinandole.
 *
 * Non usa il trascinamento nativo dell'HTML: le schede sono collegamenti, e
 * il browser avvia il proprio trascinamento del link invece del nostro. Qui
 * si seguono gli eventi del puntatore.
 *
 * Solo con il mouse: su un touch screen bloccare lo scorrimento della pagina
 * per permettere il trascinamento renderebbe scomodo leggere, che e' quello
 * che si fa piu' spesso.
 */
(function () {
    'use strict';

    var grid = document.querySelector('[data-riordinabile]');

    if (grid === null || typeof window.PointerEvent === 'undefined') {
        return;
    }

    var token = grid.getAttribute('data-csrf') || '';

    /** Durata dello scivolamento delle altre schede. */
    var DURATA = 160;

    var animazioniRidotte = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var dragged = null;
    var moved = false;
    var startX = 0;
    var startY = 0;

    // Dove si e' afferrata la scheda, rispetto al suo angolo: serve a tenerla
    // sotto il puntatore nello stesso punto in cui e' stata presa.
    var grabX = 0;
    var grabY = 0;

    // Spostamento attuale della scheda rispetto al posto che occupa nel
    // flusso. Si tiene qui perche' e' l'unico modo di risalire alla posizione
    // "vera" dopo che il trasform l'ha spostata.
    var tx = 0;
    var ty = 0;

    var lastX = 0;
    var lastY = 0;

    // Quante schede stanno ancora scivolando. Finche' e' maggiore di zero non
    // si scambia niente: a meta' volo la posizione misurata di una scheda e'
    // quella dell'animazione, non quella vera, e il confronto con il confine
    // darebbe scambi incoerenti che si annullano subito dopo.
    var inMovimento = 0;

    function cards() {
        return [].slice.call(grid.querySelectorAll('[data-corso]'));
    }

    function ordineAttuale() {
        return cards().map(function (card) {
            return card.getAttribute('data-corso');
        }).join(',');
    }

    var ordineSalvato = ordineAttuale();

    /**
     * Rimette la scheda sotto il puntatore.
     *
     * La posizione di partenza si ricava ogni volta da dove la scheda si
     * trova adesso, meno lo spostamento che le abbiamo dato: cosi' dopo uno
     * scambio il conto e' gia' giusto, senza tenere il segno di niente. La
     * versione precedente aggiornava un'origine memorizzata e, perdendo per
     * strada lo spostamento accumulato, faceva rimbalzare la scheda.
     */
    function segui() {
        var box = dragged.getBoundingClientRect();
        var sinistra = box.left - tx;
        var alto = box.top - ty;

        tx = lastX - grabX - sinistra;
        ty = lastY - grabY - alto;

        dragged.style.transform = 'translate(' + tx + 'px, ' + ty + 'px)';
    }

    /**
     * Sposta la scheda trascinata prima dell'elemento indicato, facendo
     * scivolare le altre invece di farle saltare: si misura dove sono prima,
     * si cambia l'ordine, si misura dove sono finite, e ognuna viene
     * riportata otticamente indietro e lasciata scorrere.
     */
    function sposta(riferimento) {
        var elenco = cards();
        var prima = elenco.map(function (card) {
            return card.getBoundingClientRect();
        });

        grid.insertBefore(dragged, riferimento);
        segui();

        if (animazioniRidotte) {
            return;
        }

        elenco.forEach(function (card, indice) {
            if (card === dragged) {
                return;
            }

            var adesso = card.getBoundingClientRect();
            var dx = prima[indice].left - adesso.left;
            var dy = prima[indice].top - adesso.top;

            if (dx === 0 && dy === 0) {
                return;
            }

            card.style.transition = 'none';
            card.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';
            inMovimento++;

            // Due fotogrammi: con uno solo il browser accorpa partenza e
            // arrivo, e l'animazione non si vede.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    card.style.transition = 'transform ' + DURATA + 'ms ease';
                    card.style.transform = '';
                });
            });

            window.setTimeout(function () {
                inMovimento--;
                card.style.transition = '';
            }, DURATA + 20);
        });
    }

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
            // Salvataggio silenzioso: qui non si mostra niente.
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
        lastX = event.clientX;
        lastY = event.clientY;
        tx = 0;
        ty = 0;
    });

    grid.addEventListener('pointermove', function (event) {
        if (dragged === null) {
            return;
        }

        lastX = event.clientX;
        lastY = event.clientY;

        // Soglia: un clic non perfettamente fermo non deve diventare un
        // trascinamento, altrimenti aprire un corso diventa difficile.
        if (!moved) {
            if (Math.abs(lastX - startX) < 6 && Math.abs(lastY - startY) < 6) {
                return;
            }

            moved = true;

            var box = dragged.getBoundingClientRect();
            grabX = startX - box.left;
            grabY = startY - box.top;

            dragged.classList.add('is-dragging');
            // La cattura si prende ora e non alla pressione: presa prima, il
            // clic finirebbe alla griglia e un clic semplice non aprirebbe
            // piu' il corso.
            grid.setPointerCapture(event.pointerId);
        }

        event.preventDefault();
        segui();

        if (inMovimento > 0) {
            return;
        }

        // La scheda trascinata e' trasparente al puntatore (vedi il CSS),
        // quindi qui sotto si trova sempre una delle altre.
        var sotto = document.elementFromPoint(lastX, lastY);
        var target = sotto === null ? null : sotto.closest('[data-corso]');

        if (target === null || target === dragged) {
            return;
        }

        var box = target.getBoundingClientRect();
        var dopo = (lastX - box.left) > box.width / 2;
        var riferimento = dopo ? target.nextSibling : target;

        // Gia' al suo posto: senza questo controllo si riscriverebbe lo stesso
        // ordine a ogni movimento del mouse, con un'animazione a ogni giro.
        if (riferimento === dragged || (riferimento === null && dragged === grid.lastElementChild)) {
            return;
        }

        sposta(riferimento);
    });

    function fine(event) {
        if (dragged === null) {
            return;
        }

        var eraTrascinata = moved;
        var card = dragged;

        dragged = null;
        moved = false;
        card.classList.remove('is-dragging');

        if (eraTrascinata && !animazioniRidotte) {
            // Torna al suo posto scivolando, invece di scattarci.
            card.style.transition = 'transform ' + DURATA + 'ms ease';
        }

        card.style.transform = '';
        tx = 0;
        ty = 0;

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
    }

    grid.addEventListener('pointerup', fine);
    grid.addEventListener('pointercancel', fine);

    // Il trascinamento nativo del link darebbe un'immagine fantasma e
    // confonderebbe il nostro.
    grid.addEventListener('dragstart', function (event) {
        event.preventDefault();
    });
}());
