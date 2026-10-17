/**
 * Il file di una risorsa della bacheca, controllato nel browser appena lo
 * si sceglie (17/10): se pesa troppo o non e' di un tipo ammesso lo dice
 * subito sotto il campo, e il modulo non parte, invece di scoprirlo dopo
 * averlo caricato. Il server controlla comunque, con le stesse regole: il
 * massimo e i tipi arrivano dal campo (`data-max-bytes`, `data-estensioni`).
 */
(function () {
    'use strict';

    var campo = document.getElementById('bacheca-file');
    var avviso = document.getElementById('bacheca-file-errore');

    if (campo === null || avviso === null) {
        return;
    }

    var massimo = parseInt(campo.getAttribute('data-max-bytes'), 10);
    var ammesse = (campo.getAttribute('data-estensioni') || '').split(',');

    // Come FileType::humanSize sul server: base 1024, la virgola, un
    // decimale sotto il 10.
    function misura(byte) {
        if (byte < 1024) {
            return byte + ' byte';
        }

        var unita = ['KB', 'MB', 'GB'];
        var valore = byte / 1024;
        var i = 0;

        while (valore >= 1024 && i < unita.length - 1) {
            valore /= 1024;
            i++;
        }

        return valore.toFixed(valore < 10 ? 1 : 0).replace('.', ',') + ' ' + unita[i];
    }

    campo.addEventListener('change', function () {
        var file = campo.files && campo.files[0];
        var messaggio = '';

        if (file) {
            var punto = file.name.lastIndexOf('.');
            var estensione = punto > 0 ? file.name.slice(punto + 1).toLowerCase() : '';

            if (ammesse.indexOf(estensione) === -1) {
                messaggio = (estensione === '' ? 'Questo file' : 'I file ' + estensione.toUpperCase())
                    + ' non si caricano nella bacheca: si possono caricare PDF, Word, PowerPoint, Excel o testo.';
            } else if (!isNaN(massimo) && file.size > massimo) {
                messaggio = 'Questo file pesa ' + misura(file.size) + ': il massimo è ' + misura(massimo) + '.';
            }
        }

        avviso.textContent = messaggio;
        campo.setCustomValidity(messaggio);
    });
})();
