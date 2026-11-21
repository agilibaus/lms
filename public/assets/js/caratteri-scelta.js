/**
 * I due campi dei caratteri in Impostazioni › Aspetto.
 *
 * SCEGLIERE (21/11, Alessandro). Sono campi di testo con l'elenco del
 * catalogo (`datalist`), e il browser propone solo le voci che contengono
 * il testo gia' scritto: con «Comfortaa» nel campo, l'elenco aveva solo
 * «Comfortaa» e il carattere sembrava impossibile da cambiare. Quindi:
 *
 *   - entrando nel campo, il carattere scelto passa nel testo d'esempio e
 *     il campo si svuota: l'elenco torna completo, e si scrivono le prime
 *     lettere. Uscendo senza aver scelto, torna il carattere di prima.
 *     Invio con il campo vuoto salva il carattere di prima, non il vuoto;
 *   - «Torna al carattere di sistema» (applicazione) e «Torna ad Albert
 *     Sans» (pagine pubbliche) svuotano il campo davvero: con il campo
 *     vuoto vale il carattere predefinito, al prossimo «Salva». Compaiono
 *     solo quando c'e' un carattere scelto.
 *
 * L'ANTEPRIMA (21/11, Alessandro). Sotto ogni campo, una frase scritta nel
 * carattere che si sta scegliendo, subito e senza salvare. Un carattere
 * gia' sul server ha il suo `@font-face` nella pagina; uno nuovo lo scarica
 * il server (`/admin/settings/aspetto/anteprima-carattere`), mai il browser
 * da Google, e qui diventa un `FontFace`. Se il server non ci riesce,
 * l'anteprima lo dice.
 *
 * Senza JavaScript i campi restano campi di testo, il nome si cancella a
 * mano e l'anteprima mostra il carattere salvato.
 */
(function () {
    'use strict';

    var contenitore = document.querySelector('[data-caratteri-scaricati]');
    var scaricati = [];

    try {
        scaricati = JSON.parse(contenitore ? contenitore.getAttribute('data-caratteri-scaricati') : '[]') || [];
    } catch (e) {
        scaricati = [];
    }

    var catalogo = {};
    document.querySelectorAll('#catalogo-caratteri option').forEach(function (o) {
        catalogo[o.value] = true;
    });

    // Le anteprime gia' caricate in questa pagina: nome della famiglia => la
    // promessa del suo FontFace.
    var caricati = {};

    // Come `Theme::bloccoFont()` scrive il nome: solo lettere, cifre, spazi.
    function pulito(famiglia) {
        return famiglia.replace(/[^A-Za-z0-9 ]/g, '');
    }

    function caricaAnteprima(famiglia) {
        if (!caricati[famiglia]) {
            caricati[famiglia] = fetch('/admin/settings/aspetto/anteprima-carattere?famiglia=' + encodeURIComponent(famiglia), {
                credentials: 'same-origin'
            }).then(function (risposta) {
                if (!risposta.ok) {
                    throw new Error('anteprima ' + risposta.status);
                }
                return risposta.arrayBuffer();
            }).then(function (dati) {
                var nome = 'Anteprima ' + pulito(famiglia);
                var faccia = new FontFace(nome, dati);
                return faccia.load().then(function (f) {
                    document.fonts.add(f);
                    return '"' + nome + '"';
                });
            });

            // Un errore non resta in memoria: si potra' riprovare.
            caricati[famiglia].catch(function () {
                delete caricati[famiglia];
            });
        }

        return caricati[famiglia];
    }

    document.querySelectorAll('input[data-carattere]').forEach(function (campo) {
        var esempio = campo.placeholder;
        var prima = null;
        var comando = document.querySelector('[data-carattere-partenza="' + campo.id + '"]');
        var anteprima = document.querySelector('[data-anteprima="' + campo.id + '"]');
        var mostrata = null;
        var richiesta = 0;

        function aggiornaComando() {
            if (comando) {
                comando.hidden = campo.value.trim() === '' && prima === null;
            }
        }

        function aggiornaAnteprima() {
            if (!anteprima) {
                return;
            }

            // Mentre il campo e' vuoto per mostrare l'elenco, vale ancora il
            // carattere di prima.
            var famiglia = campo.value.trim() !== '' ? campo.value.trim() : (prima !== null ? prima : '');

            if (famiglia !== '' && !catalogo[famiglia]) {
                return; // un nome a meta': resta l'anteprima che c'era
            }

            if (famiglia === mostrata) {
                return;
            }

            mostrata = famiglia;
            var numero = ++richiesta;
            var nome = anteprima.querySelector('[data-anteprima-nome]');
            var testo = anteprima.querySelector('[data-anteprima-testo]');
            var avviso = anteprima.querySelector('[data-anteprima-avviso]');

            avviso.hidden = true;
            avviso.textContent = '';

            if (famiglia === '') {
                nome.textContent = anteprima.getAttribute('data-predefinito');
                testo.style.fontFamily = anteprima.getAttribute('data-pila-predefinita');
                return;
            }

            if (scaricati.indexOf(famiglia) !== -1) {
                nome.textContent = famiglia;
                testo.style.fontFamily = '"' + pulito(famiglia) + '", ' + anteprima.getAttribute('data-pila-predefinita');
                return;
            }

            nome.textContent = famiglia + ' (in caricamento…)';

            caricaAnteprima(famiglia).then(function (pila) {
                if (numero !== richiesta) {
                    return; // nel frattempo se n'e' scelto un altro
                }
                nome.textContent = famiglia;
                testo.style.fontFamily = pila + ', ' + anteprima.getAttribute('data-pila-predefinita');
            }, function () {
                if (numero !== richiesta) {
                    return;
                }
                nome.textContent = famiglia;
                avviso.textContent = 'Anteprima non disponibile: il server non è riuscito a scaricare il carattere.';
                avviso.hidden = false;
            });
        }

        function rimetti() {
            if (prima !== null && campo.value.trim() === '') {
                campo.value = prima;
            }
            prima = null;
            campo.placeholder = esempio;
            aggiornaComando();
            aggiornaAnteprima();
        }

        campo.addEventListener('focus', function () {
            if (campo.value.trim() === '') {
                return;
            }
            prima = campo.value;
            campo.placeholder = campo.value;
            campo.value = '';
        });

        campo.addEventListener('blur', rimetti);
        campo.addEventListener('input', function () {
            aggiornaComando();
            aggiornaAnteprima();
        });

        // Invio dentro il campo manda il modulo senza farlo uscire: senza
        // questo, il campo svuotato per mostrare l'elenco si salverebbe vuoto.
        if (campo.form) {
            campo.form.addEventListener('submit', rimetti);
        }

        if (comando) {
            comando.addEventListener('click', function () {
                prima = null;
                campo.value = '';
                campo.placeholder = esempio;
                aggiornaComando();
                aggiornaAnteprima();
                campo.focus();
            });
        }

        mostrata = campo.value.trim();
        aggiornaComando();
    });
})();
