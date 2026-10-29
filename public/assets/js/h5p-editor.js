/**
 * Contenuti H5P nell'editor della lezione (23/10, passo 3).
 *
 * Il pulsante «Esercizio H5P» della barra elenca i contenuti caricati per la
 * lezione e mette quello scelto nel punto del cursore, come segnaposto:
 * `<div data-h5p="12">Esercizio interattivo: stop.h5p (Drag the Words)</div>`.
 * Nella lezione, al suo posto, c'e' l'esercizio (App\Core\LezioneH5P).
 *
 * Nell'editor il segnaposto e' un blocco intero: si sposta, si copia e si
 * cancella, ma non ci si scrive dentro. Il testo lo riscrive questo script a
 * ogni apertura, dall'elenco attuale: dopo una sostituzione dice il file
 * nuovo, e dopo un'eliminazione dice che non mostra piu' niente.
 *
 * Chiamato da `setup` di TinyMCE in `lessons/form.php`, con l'elenco.
 */
(function () {
    'use strict';

    var ATTRIBUTO = 'data-h5p';
    var ELIMINATO = 'Contenuto H5P eliminato: questo segnaposto non mostra niente nella lezione. Puoi cancellarlo.';

    window.PistacchioH5PEditor = function (editor, contenuti) {
        var perId = {};

        contenuti.forEach(function (c) {
            perId[String(c.id)] = c;
        });

        function etichetta(id) {
            return perId[id] ? perId[id].etichetta : ELIMINATO;
        }

        function testo(valore) {
            var nodo = new window.tinymce.html.Node('#text', 3);
            nodo.value = valore;

            return nodo;
        }

        editor.on('PreInit', function () {
            // Entrando nell'editor: blocco non modificabile, con il testo di oggi.
            editor.parser.addAttributeFilter(ATTRIBUTO, function (nodi) {
                nodi.forEach(function (nodo) {
                    if (nodo.name !== 'div') {
                        return;
                    }

                    var id = nodo.attr(ATTRIBUTO);
                    nodo.attr('contenteditable', 'false');
                    nodo.attr('class', 'h5p-segnaposto' + (perId[id] ? '' : ' h5p-segnaposto-eliminato'));
                    nodo.empty().append(testo(etichetta(id)));
                });
            });

            // Uscendo: solo l'attributo e il testo, come li vuole il
            // sanificatore (che comunque toglierebbe il resto).
            editor.serializer.addAttributeFilter(ATTRIBUTO, function (nodi) {
                nodi.forEach(function (nodo) {
                    nodo.attr('contenteditable', null);
                    nodo.attr('class', null);
                });
            });
        });

        editor.ui.registry.addMenuButton('h5p', {
            text: 'Esercizio H5P',
            tooltip: 'Metti nel testo un contenuto H5P della lezione',
            fetch: function (risposta) {
                if (contenuti.length === 0) {
                    risposta([{
                        type: 'menuitem',
                        text: 'Nessun contenuto: aggiungilo in «Contenuti interattivi H5P» e salva',
                        enabled: false
                    }]);

                    return;
                }

                risposta(contenuti.map(function (c) {
                    return {
                        type: 'menuitem',
                        text: c.voce,
                        onAction: function () {
                            var div = editor.dom.create('div', {}, '');
                            div.setAttribute(ATTRIBUTO, String(c.id));
                            div.textContent = c.etichetta;
                            editor.insertContent(div.outerHTML);
                        }
                    };
                }));
            }
        });
    };

    // «Sostituisci»: il file scelto si dice sotto, perche' il campo non si
    // vede e la sostituzione avviene solo al «Salva».
    document.addEventListener('change', function (evento) {
        var campo = evento.target;

        if (!campo || !campo.classList || !campo.classList.contains('h5p-lezione-file')) {
            return;
        }

        var riga = campo.closest('li');
        var scelto = riga && riga.querySelector('[data-h5p-scelto]');
        var etichetta = riga && riga.querySelector('.h5p-lezione-sostituisci');
        var nome = campo.files && campo.files[0] ? campo.files[0].name : '';

        if (!scelto) {
            return;
        }

        scelto.hidden = nome === '';
        scelto.textContent = nome === '' ? '' : 'Al «Salva» sarà sostituito con «' + nome + '», nello stesso punto del testo.';

        if (etichetta && etichetta.firstChild) {
            etichetta.firstChild.nodeValue = nome === '' ? 'Sostituisci' : 'Cambia file';
        }
    });

    // «Elimina» di un contenuto: un modulo a parte, che ricarica la pagina.
    // Lo si dice prima, e se il testo e' stato cambiato si avvisa che le
    // modifiche andrebbero perse.
    document.addEventListener('submit', function (evento) {
        var modulo = evento.target;

        if (!modulo || !modulo.hasAttribute || !modulo.hasAttribute('data-h5p-elimina')) {
            return;
        }

        var editor = window.tinymce && window.tinymce.activeEditor;
        var cambiato = editor && editor.isDirty();
        var domanda = 'Togliere «' + modulo.getAttribute('data-h5p-elimina') + '» dalla lezione? I suoi file restano sul server.'
            + (cambiato ? '\n\nHai modifiche non salvate nel testo: andranno perse. Annulla e premi prima «Salva».' : '');

        if (!window.confirm(domanda)) {
            evento.preventDefault();
        }
    }, true);
})();
