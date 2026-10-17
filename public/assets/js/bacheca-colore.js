/**
 * La tavolozza continua dei colori delle categorie della bacheca (17/10,
 * Alessandro, strada 2).
 *
 * Il valore che parte con il modulo e' quello del selettore di colore del
 * browser (`input[type=color]`, name="color"). Senza questo script resta
 * l'unico comando, e basta da solo. Lo script disegna accanto una tavolozza
 * continua — tinta in orizzontale, chiaro e scuro in verticale — con un
 * cerchio da spostare con il mouse, con il dito o con le frecce, e un
 * cursore per l'intensita' (da grigio a vivace). Tutti e tre restano
 * allineati, e l'anteprima del riquadro segue.
 *
 * I colori sono calcolati in OKLCH, dove la luminosita' e' uniforme fra le
 * tinte: una riga della tavolozza e' chiara allo stesso modo dal rosso al
 * blu. Quelli che lo schermo non sa mostrare si riportano dentro abbassando
 * l'intensita', mai cambiando tinta.
 *
 * L'anteprima calcola il titolo come `Bacheca::colore()` sul server: la
 * tinta chiara e' l'89% di bianco (quella del tema per i colori del tema), e
 * il titolo e' il colore spinto verso il nero a passi del 2% finche' non si
 * legge (4,5:1).
 */
(function () {
    'use strict';

    var L_ALTO = 0.97;
    var L_BASSO = 0.22;
    var C_MAX = 0.26;

    // --- conversioni --------------------------------------------------

    function oklchInLineare(L, C, h) {
        var a = C * Math.cos(h * Math.PI / 180);
        var b = C * Math.sin(h * Math.PI / 180);
        var l = Math.pow(L + 0.3963377774 * a + 0.2158037573 * b, 3);
        var m = Math.pow(L - 0.1055613458 * a - 0.0638541728 * b, 3);
        var s = Math.pow(L - 0.0894841775 * a - 1.2914855480 * b, 3);

        return [
            4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
            -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
            -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s
        ];
    }

    function dentro(c) {
        return c[0] >= -0.0001 && c[0] <= 1.0001 && c[1] >= -0.0001 && c[1] <= 1.0001 && c[2] >= -0.0001 && c[2] <= 1.0001;
    }

    function codifica(x) {
        x = Math.min(1, Math.max(0, x));
        return Math.round((x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055) * 255);
    }

    function decodifica(v) {
        v /= 255;
        return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    }

    /** Il colore, con l'intensita' abbassata quanto serve a stare nello schermo. */
    function rgbDa(L, C, h) {
        var c = oklchInLineare(L, C, h);

        if (!dentro(c)) {
            var lo = 0;
            var hi = C;

            for (var i = 0; i < 14; i++) {
                var mezzo = (lo + hi) / 2;

                if (dentro(oklchInLineare(L, mezzo, h))) {
                    lo = mezzo;
                } else {
                    hi = mezzo;
                }
            }

            c = oklchInLineare(L, lo, h);
        }

        return [codifica(c[0]), codifica(c[1]), codifica(c[2])];
    }

    function oklchDa(rgb) {
        var r = decodifica(rgb[0]);
        var g = decodifica(rgb[1]);
        var b = decodifica(rgb[2]);
        var l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
        var m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
        var s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
        var L = 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s;
        var A = 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s;
        var B = 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s;
        var h = Math.atan2(B, A) * 180 / Math.PI;

        return { L: L, C: Math.sqrt(A * A + B * B), h: h < 0 ? h + 360 : h };
    }

    function hex(rgb) {
        return '#' + rgb.map(function (v) { return (v < 16 ? '0' : '') + v.toString(16); }).join('').toUpperCase();
    }

    function rgbDaHex(valore) {
        return [1, 3, 5].map(function (i) { return parseInt(valore.slice(i, i + 2), 16); });
    }

    // --- come Theme::mescolaVerso e Bacheca::colore sul server --------

    function mescola(da, a, quanto) {
        return da.map(function (v, i) { return Math.max(0, Math.min(255, Math.round(v + (a[i] - v) * quanto))); });
    }

    function luminanza(rgb) {
        return 0.2126 * decodifica(rgb[0]) + 0.7152 * decodifica(rgb[1]) + 0.0722 * decodifica(rgb[2]);
    }

    function contrasto(x, y) {
        var a = luminanza(x);
        var b = luminanza(y);

        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }

    function aspetto(rgb, temi) {
        var codice = hex(rgb);
        var fondo = temi[codice] ? rgbDaHex(temi[codice]) : mescola(rgb, [255, 255, 255], 0.89);
        var titolo = [0, 0, 0];

        for (var passo = 0; passo <= 50; passo++) {
            var prova = mescola(rgb, [0, 0, 0], passo * 0.02);

            if (contrasto(prova, fondo) >= 4.5) {
                titolo = prova;
                break;
            }
        }

        return { primary: codice, soft: hex(fondo), titolo: hex(titolo) };
    }

    // --- una tavolozza ------------------------------------------------

    function prepara(contenitore) {
        var area = contenitore.querySelector('.bacheca-colore-area');
        var tela = area.querySelector('canvas');
        var cursore = area.querySelector('.bacheca-colore-cursore');
        var intensita = contenitore.querySelector('.bacheca-colore-intensita input');
        var selettore = contenitore.querySelector('input[type="color"]');
        var campione = contenitore.querySelector('.bacheca-colore-campione');
        var riquadro = contenitore.querySelector('.bacheca-colore-anteprima-riquadro');
        var temi = {};

        try {
            temi = JSON.parse(contenitore.getAttribute('data-temi') || '{}');
        } catch (e) {
            temi = {};
        }

        var stato = { h: 0, y: 0, i: 0.7 };
        var disegnataCon = null;

        function L(y) {
            return L_ALTO - (L_ALTO - L_BASSO) * y;
        }

        function disegna() {
            if (disegnataCon === stato.i) {
                return;
            }

            var W = tela.width;
            var H = tela.height;
            var g = tela.getContext('2d');
            var img = g.createImageData(W, H);

            for (var x = 0; x < W; x++) {
                var h = x / W * 360;

                for (var y = 0; y < H; y++) {
                    var c = rgbDa(L(y / (H - 1)), stato.i * C_MAX, h);
                    var k = (y * W + x) * 4;
                    img.data[k] = c[0];
                    img.data[k + 1] = c[1];
                    img.data[k + 2] = c[2];
                    img.data[k + 3] = 255;
                }
            }

            g.putImageData(img, 0, 0);
            disegnataCon = stato.i;
        }

        function mostra(rgb) {
            var a = aspetto(rgb, temi);
            cursore.style.left = (stato.h / 360 * 100) + '%';
            cursore.style.top = (stato.y * 100) + '%';
            cursore.style.background = a.primary;
            campione.style.background = a.primary;
            riquadro.style.setProperty('--cat', a.primary);
            riquadro.style.setProperty('--cat-soft', a.soft);
            riquadro.style.setProperty('--cat-titolo', a.titolo);
            area.setAttribute('aria-valuenow', String(Math.round(stato.h) % 360));
            area.setAttribute('aria-valuetext', 'tinta ' + Math.round(stato.h) + ' gradi, ' + Math.round((1 - stato.y) * 100)
                + ' per cento di luce, colore ' + a.primary);
        }

        /** Dalla posizione al colore, che va nel selettore e quindi nel modulo. */
        function scegli() {
            var rgb = rgbDa(L(stato.y), stato.i * C_MAX, stato.h);
            selettore.value = hex(rgb).toLowerCase();
            mostra(rgb);
        }

        /** Dal colore del selettore alla posizione (all'apertura, o scelto dal browser). */
        function leggi() {
            var rgb = rgbDaHex(selettore.value.toUpperCase());
            var o = oklchDa(rgb);
            stato.h = o.h;
            stato.y = Math.min(1, Math.max(0, (L_ALTO - o.L) / (L_ALTO - L_BASSO)));
            stato.i = Math.min(1, o.C / C_MAX);
            intensita.value = String(Math.round(stato.i * 100));

            // La tavolozza si disegna quando si apre: con molte categorie,
            // disegnarle tutte al caricamento rallenterebbe la pagina.
            if (contenitore.open) {
                disegna();
            }

            mostra(rgb);
        }

        function daPuntatore(e) {
            var r = area.getBoundingClientRect();
            stato.h = Math.min(359.9, Math.max(0, (e.clientX - r.left) / r.width * 360));
            stato.y = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height));
            scegli();
        }

        area.addEventListener('pointerdown', function (e) {
            area.setPointerCapture(e.pointerId);
            area.focus();
            daPuntatore(e);
        });

        area.addEventListener('pointermove', function (e) {
            if (area.hasPointerCapture(e.pointerId)) {
                daPuntatore(e);
            }
        });

        area.addEventListener('keydown', function (e) {
            var passoH = e.shiftKey ? 30 : 5;
            var passoY = e.shiftKey ? 0.1 : 0.02;
            var mossa = {
                ArrowLeft: function () { stato.h = (stato.h - passoH + 360) % 360; },
                ArrowRight: function () { stato.h = (stato.h + passoH) % 360; },
                ArrowUp: function () { stato.y = Math.max(0, stato.y - passoY); },
                ArrowDown: function () { stato.y = Math.min(1, stato.y + passoY); }
            }[e.key];

            if (mossa) {
                e.preventDefault();
                mossa();
                scegli();
            }
        });

        intensita.addEventListener('input', function () {
            stato.i = Math.min(1, Math.max(0, parseInt(intensita.value, 10) / 100));
            disegna();
            scegli();
        });

        selettore.addEventListener('input', leggi);

        contenitore.addEventListener('toggle', function () {
            if (contenitore.open) {
                leggi();
            }
        });

        contenitore.classList.add('pronta');
        leggi();
    }

    document.querySelectorAll('.bacheca-colore').forEach(function (c) {
        if (!c.classList.contains('pronta')) {
            prepara(c);
        }
    });

    // Esc e un clic fuori chiudono la tavolozza aperta.
    document.addEventListener('keydown', function (e) {
        var aperta = e.key === 'Escape' && e.target.closest ? e.target.closest('.bacheca-colore[open]') : null;

        if (aperta !== null) {
            aperta.open = false;
            aperta.querySelector('summary').focus();
        }
    });

    document.addEventListener('pointerdown', function (e) {
        document.querySelectorAll('.bacheca-colore[open]').forEach(function (c) {
            if (!c.contains(e.target)) {
                c.open = false;
            }
        });
    });
})();
