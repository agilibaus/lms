<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Le tavolozze di colori, e il colore principale scelto dall'admin.
 *
 * COME FUNZIONA, in una riga: `style.css` non viene mai toccato. I colori
 * sono gia' variabili CSS (`--color-primary`, `--color-surface`...), e qui si
 * calcola un blocco `:root { ... }` che le pagine stampano **dopo** il foglio
 * di stile. L'ultima dichiarazione vince, quindi i valori dell'admin
 * sostituiscono quelli di partenza senza che una patch futura vada in
 * conflitto su `style.css` (§8.5: mai scrivere li' dentro).
 *
 * PERCHE' TAVOLOZZE PRONTE E NON UN COLORE PER VARIABILE. Una tavolozza non
 * e' un colore: sono sette valori che devono reggersi fra loro. Il verde
 * principale deve staccare sul bianco **e** sul crema delle pagine pubbliche,
 * il bianco deve leggersi sopra il verde, il verde scuro deve leggersi sopra
 * la sua stessa tinta chiara. Offrire sette campi liberi vuol dire offrire
 * combinazioni illeggibili. Le quattro tavolozze qui sotto sono state
 * verificate su **undici coppie** ciascuna prima di essere offerte, e il test
 * `tests/tema_test.php` rifa quel conto a ogni esecuzione.
 *
 * L'UNICA ECCEZIONE e' il colore principale, che e' quello che si vuole
 * cambiare piu' spesso — di solito per avvicinarlo a un marchio. Li' il
 * controllo si fa al salvataggio: un valore che non si legge viene rifiutato
 * con il motivo, non salvato e poi subito.
 */
class Theme
{
    /** Chiave della tavolozza scelta. */
    public const KEY_PALETTE = 'THEME_PALETTE';

    /** Colore principale che scavalca quello della tavolozza, se impostato. */
    public const KEY_PRIMARY = 'THEME_PRIMARY';

    /** Arrotondamento degli angoli: una delle chiavi di RAGGI. */
    public const KEY_RADIUS = 'THEME_RADIUS';

    /** Dimensione del testo: una delle chiavi di TESTO. */
    public const KEY_TEXT_SIZE = 'THEME_TEXT_SIZE';

    /** Colore del testo che scavalca quello di fabbrica, se impostato. */
    public const KEY_TEXT_COLOR = 'THEME_TEXT_COLOR';

    /** Misura del titolo e del testo della presentazione (aspetto affiancato). */
    public const KEY_SCENE_SIZE = 'THEME_SCENE_SIZE';

    /** Carattere delle pagine pubbliche: famiglia del catalogo, o vuoto. */
    public const KEY_FONT_AUTH = 'THEME_FONT_AUTH';

    /** Carattere dell'applicazione: famiglia del catalogo, o vuoto. */
    public const KEY_FONT_APP = 'THEME_FONT_APP';

    public const PREDEFINITA = 'verde';
    public const RAGGIO_PREDEFINITO = 'normale';
    public const MISURA_TESTO_PREDEFINITA = 'normale';

    public const MISURA_SCENA_PREDEFINITA = 'normale';

    /**
     * Misura del titolo e del testo della presentazione, nell'aspetto
     * affiancato.
     *
     * **Si regola per conto proprio**: ingrandire il titolo non ingrandisce
     * il resto dell'interfaccia. Quella generale scala tutto perche'
     * riguarda la leggibilita'; qui le due righe sono un elemento grafico, e
     * ingrandirle e' una scelta di presentazione. Senza questa misura, per
     * avere un titolo piu' grande bisognava ingrandire anche i menu e i
     * report.
     *
     * Attenzione pero' al verso opposto: i valori sono in `rem`, quindi se
     * si alza **anche** la dimensione generale del testo questi crescono
     * insieme a tutto il resto. E' voluto — un titolo rimasto indietro
     * dentro un'interfaccia cresciuta sarebbe sbagliato — ma vuol dire che
     * le due scelte si sommano.
     *
     * Due valori per livello, come per l'arrotondamento: il titolo e il
     * testo sotto devono crescere insieme, o il rapporto fra i due si rompe.
     *
     * @var array<string, array{nome: string, titolo: string, testo: string}>
     */
    public const MISURE_SCENA = [
        'piccola' => ['nome' => 'Piccola', 'titolo' => '1.5rem', 'testo' => '0.95rem'],
        'normale' => ['nome' => 'Normale', 'titolo' => '1.85rem', 'testo' => '1rem'],
        'grande' => ['nome' => 'Grande', 'titolo' => '2.3rem', 'testo' => '1.1rem'],
        'molto-grande' => ['nome' => 'Molto grande', 'titolo' => '2.9rem', 'testo' => '1.2rem'],
    ];

    /**
     * Arrotondamento degli angoli. Due valori per livello, non uno: nel
     * foglio di stile il raggio piccolo veste i campi e i pulsanti, quello
     * grande i riquadri, e il rapporto fra i due e' quello che fa sembrare
     * la pagina disegnata invece che assemblata. Un campo numerico libero
     * lascerebbe scegliere 2 e 40.
     *
     * @var array<string, array{nome: string, sm: string, md: string}>
     */
    public const RAGGI = [
        'squadrato' => ['nome' => 'Squadrato', 'sm' => '0px', 'md' => '0px'],
        'leggero' => ['nome' => 'Leggero', 'sm' => '3px', 'md' => '5px'],
        'normale' => ['nome' => 'Normale', 'sm' => '6px', 'md' => '10px'],
        'morbido' => ['nome' => 'Morbido', 'sm' => '10px', 'md' => '16px'],
    ];

    /**
     * Dimensione del testo.
     *
     * **Scala tutta l'interfaccia, non solo le lettere.** Nel foglio di stile
     * quasi ogni misura e' in `rem`, cioe' relativa a questo valore: alzandolo
     * crescono insieme testo, riempimenti e spazi, e le proporzioni restano
     * quelle. E' il motivo per cui non c'e' un "ingrandisci solo il testo":
     * lascerebbe il testo grande dentro riquadri rimasti piccoli.
     *
     * Non si scende sotto i 15 px. Piu' in basso si entra in un territorio
     * che nessuno ha chiesto e che peggiora la lettura per tutti.
     *
     * @var array<string, array{nome: string, px: string}>
     */
    public const MISURE_TESTO = [
        'compatto' => ['nome' => 'Compatto — 15px', 'px' => '15px'],
        'normale' => ['nome' => 'Normale — 16px', 'px' => '16px'],
        'comodo' => ['nome' => 'Comodo — 17px', 'px' => '17px'],
        'grande' => ['nome' => 'Grande — 18px', 'px' => '18px'],
    ];

    /**
     * Contrasto minimo fra testo e sfondo: 4,5:1 e' il livello AA delle WCAG
     * per il testo normale.
     */
    public const MINIMO = 4.5;

    /**
     * Le tavolozze. Ogni voce porta i valori che cambiano; tutto il resto —
     * sfondo, superficie, bordo, testo — resta quello del foglio di stile,
     * perche' sono le quattro cose che devono restare uguali perche' la
     * piattaforma continui a sembrare la stessa.
     *
     * `scene_h` e `scene_p` sono i due toni della sezione di presentazione
     * delle pagine pubbliche. Erano scritti a mano nel foglio di stile: con
     * una tavolozza diversa sarebbero rimasti verdi in mezzo a tutto il
     * resto, ed e' il genere di dettaglio che fa sembrare il lavoro fatto a
     * meta'.
     *
     * @var array<string, array{nome: string, primary: string, hover: string,
     *                          soft: string, auth_bg: string, orb_a: string,
     *                          orb_b: string, scene_h: string, scene_p: string}>
     */
    public const TAVOLOZZE = [
        'verde' => [
            'nome' => 'Verde pistacchio',
            'primary' => '#4F7256',
            'hover' => '#47694F',
            'soft' => '#EAF1EC',
            'auth_bg' => '#F6F1EB',
            'orb_a' => 'rgba(122, 140, 110, 0.08)',
            'orb_b' => 'rgba(217, 166, 121, 0.10)',
            'scene_h' => '#2F3A2C',
            'scene_p' => '#5F6B59',
        ],
        'ardesia' => [
            'nome' => 'Blu ardesia',
            'primary' => '#3F6079',
            'hover' => '#39566D',
            'soft' => '#E9EEF2',
            'auth_bg' => '#EEF2F5',
            'orb_a' => 'rgba(63, 96, 121, 0.08)',
            'orb_b' => 'rgba(121, 145, 166, 0.12)',
            'scene_h' => '#27333C',
            'scene_p' => '#55636E',
        ],
        'terracotta' => [
            'nome' => 'Terracotta',
            'primary' => '#9B4F2F',
            'hover' => '#8C472A',
            'soft' => '#F6ECE7',
            'auth_bg' => '#F8F0EA',
            'orb_a' => 'rgba(155, 79, 47, 0.07)',
            'orb_b' => 'rgba(217, 166, 121, 0.12)',
            'scene_h' => '#412620',
            'scene_p' => '#6F5349',
        ],
        'prugna' => [
            'nome' => 'Prugna',
            'primary' => '#6B4A77',
            'hover' => '#61436C',
            'soft' => '#F0EAF3',
            'auth_bg' => '#F4EEF6',
            'orb_a' => 'rgba(107, 74, 119, 0.07)',
            'orb_b' => 'rgba(160, 130, 170, 0.12)',
            'scene_h' => '#33243A',
            'scene_p' => '#60536A',
        ],
    ];

    /** Colori di riferimento su cui si misurano i contrasti. */
    public const SFONDO_PAGINA = '#FAFAF9';
    public const SUPERFICIE = '#FFFFFF';
    public const TESTO = '#1C1C1A';
    public const TESTO_SPENTO = '#78716C';

    /**
     * La famiglia scelta per una delle due zone, o null.
     *
     * Torna null anche quando la famiglia e' impostata ma **il file non
     * c'e'**: sarebbe il caso di un carattere scaricato e poi cancellato a
     * mano da `storage/`, e chiedere a ogni pagina un file inesistente
     * aggiunge un 404 per visitatore senza cambiare niente di quello che si
     * vede. Meglio tornare al carattere di partenza in silenzio.
     */
    public static function fontScelto(string $chiave): ?string
    {
        $famiglia = trim((string) Settings::get($chiave, ''));

        if ($famiglia === '' || !FontLibrary::presente($famiglia)) {
            return null;
        }

        return $famiglia;
    }

    /**
     * I blocchi `@font-face` dei caratteri scelti, pronti da stampare.
     *
     * `font-display: swap` disegna subito il testo con il carattere di
     * sistema e lo sostituisce appena il file e' pronto. Il comportamento
     * predefinito del browser e' invece lasciarlo **invisibile** fino a tre
     * secondi: su una pagina di accesso, un modulo senza etichette.
     */
    public static function bloccoFont(): string
    {
        $css = '';
        $viste = [];

        foreach ([self::KEY_FONT_AUTH, self::KEY_FONT_APP] as $chiave) {
            $famiglia = self::fontScelto($chiave);

            if ($famiglia === null || isset($viste[$famiglia])) {
                continue;
            }

            $viste[$famiglia] = true;

            // Il nome finisce dentro `font-family: "..."`. Le virgolette e
            // tutto cio' che non sia una lettera, una cifra o uno spazio si
            // tolgono: il nome arriva dal catalogo, ma un catalogo e' un
            // file, e un file si puo' modificare.
            $nome = (string) preg_replace('/[^A-Za-z0-9 ]/', '', $famiglia);

            $css .= '@font-face{font-family:"' . $nome . '";'
                . 'src:url("' . FontLibrary::url($famiglia) . '") format("woff2");'
                . 'font-weight:100 900;font-style:normal;font-display:swap}';
        }

        return $css;
    }

    public static function raggioCorrente(): string
    {
        $scelta = (string) Settings::get(self::KEY_RADIUS, self::RAGGIO_PREDEFINITO);

        return isset(self::RAGGI[$scelta]) ? $scelta : self::RAGGIO_PREDEFINITO;
    }

    public static function misuraScenaCorrente(): string
    {
        $scelta = (string) Settings::get(self::KEY_SCENE_SIZE, self::MISURA_SCENA_PREDEFINITA);

        return isset(self::MISURE_SCENA[$scelta]) ? $scelta : self::MISURA_SCENA_PREDEFINITA;
    }

    public static function misuraTestoCorrente(): string
    {
        $scelta = (string) Settings::get(self::KEY_TEXT_SIZE, self::MISURA_TESTO_PREDEFINITA);

        return isset(self::MISURE_TESTO[$scelta]) ? $scelta : self::MISURA_TESTO_PREDEFINITA;
    }

    /**
     * Il colore del testo in vigore, e il suo grigio spento.
     *
     * Il secondo non si chiede all'admin: e' il primo schiarito verso lo
     * sfondo finche' resta leggibile. Chiederlo vorrebbe dire due campi che
     * devono stare in rapporto fra loro, cioe' due modi di sbagliare invece
     * di uno.
     *
     * @return array{0: string, 1: string} testo, testo spento
     */
    public static function coloriTesto(): array
    {
        $scelto = self::coloreValido((string) Settings::get(self::KEY_TEXT_COLOR, ''));

        if ($scelto === null) {
            return [self::TESTO, self::TESTO_SPENTO];
        }

        return [$scelto, self::spegni($scelto)];
    }

    /**
     * Schiarisce un colore di testo verso lo sfondo, fermandosi all'ultimo
     * passo che resta leggibile.
     *
     * Si prova per gradi invece di calcolare il punto esatto: la formula del
     * contrasto non si inverte in modo semplice, e venti passi da 5% sono
     * istantanei. Se nessun passo tiene — testo gia' al limite — si
     * restituisce il colore di partenza: meglio un grigio spento identico al
     * testo che uno che non si legge.
     */
    public static function spegni(string $hex): string
    {
        $colore = self::coloreValido($hex) ?? self::TESTO;
        $ultimoBuono = $colore;

        for ($passo = 1; $passo <= 20; $passo++) {
            $candidato = self::mescolaVerso($colore, self::SFONDO_PAGINA, $passo * 0.05);

            if (self::contrasto($candidato, self::SUPERFICIE) < self::MINIMO
                || self::contrasto($candidato, self::SFONDO_PAGINA) < self::MINIMO) {
                break;
            }

            $ultimoBuono = $candidato;
        }

        return $ultimoBuono;
    }

    public static function paletteCorrente(): string
    {
        $scelta = (string) Settings::get(self::KEY_PALETTE, self::PREDEFINITA);

        // Una chiave non prevista — riga scritta a mano in tabella, o
        // tavolozza tolta in futuro — non deve lasciare la piattaforma senza
        // colori: si torna alla predefinita.
        return isset(self::TAVOLOZZE[$scelta]) ? $scelta : self::PREDEFINITA;
    }

    /**
     * I valori in vigore: la tavolozza scelta, con il colore principale
     * dell'admin al posto del suo se c'e'.
     *
     * @return array<string, string>
     */
    public static function valori(): array
    {
        $tavolozza = self::TAVOLOZZE[self::paletteCorrente()];
        $principale = self::coloreValido((string) Settings::get(self::KEY_PRIMARY, ''));

        if ($principale !== null) {
            $tavolozza['primary'] = $principale;
            $tavolozza['hover'] = self::scurisci($principale, 0.12);
            $tavolozza['soft'] = self::schiarisci($principale, 0.90);
        }

        return $tavolozza;
    }

    /**
     * Il blocco `:root` da stampare dopo il foglio di stile.
     *
     * Torna stringa vuota quando non c'e' niente da cambiare: la tavolozza
     * predefinita senza colore personalizzato e' gia' quella di `style.css`,
     * e stampare un blocco identico a quello che sovrascrive e' solo peso in
     * piu' su ogni pagina.
     *
     * **Ogni valore passa da `coloreValido()` o e' una costante di questo
     * file.** Un colore arriva dal database, e il database lo scrive una
     * pagina: senza quel filtro, un valore come `#fff; } body { display:none`
     * uscirebbe dentro un tag `<style>` e sarebbe CSS eseguito. E' la stessa
     * ragione per cui l'HTML dell'editor passa dal sanificatore.
     */
    public static function blocco(): string
    {
        if (!self::qualcosaDaCambiare()) {
            return '';
        }

        $v = self::valori();
        $raggio = self::RAGGI[self::raggioCorrente()];
        [$testo, $spento] = self::coloriTesto();

        $righe = [
            '--color-primary: ' . $v['primary'],
            '--color-primary-hover: ' . $v['hover'],
            '--color-primary-soft: ' . $v['soft'],
            '--color-auth-bg: ' . $v['auth_bg'],
            '--color-auth-orb-sage: ' . self::rgbaValido($v['orb_a']),
            '--color-auth-orb-terracotta: ' . self::rgbaValido($v['orb_b']),
            '--color-scene-title: ' . $v['scene_h'],
            '--color-scene-text: ' . $v['scene_p'],
            '--radius-sm: ' . self::misuraValida($raggio['sm']),
            '--radius-md: ' . self::misuraValida($raggio['md']),
            '--color-text: ' . $testo,
            '--color-text-muted: ' . $spento,
        ];

        $scena = self::MISURE_SCENA[self::misuraScenaCorrente()];
        $righe[] = '--scene-title-size: ' . self::misuraRemValida($scena['titolo']);
        $righe[] = '--scene-text-size: ' . self::misuraRemValida($scena['testo']);

        // I due caratteri. `--font-auth` e' gia' usato dalle pagine
        // pubbliche (0078) e vale Albert Sans se nessuno sceglie altro;
        // `--font-sans` e' quello di tutto il resto.
        $pubbliche = self::fontScelto(self::KEY_FONT_AUTH);
        $interno = self::fontScelto(self::KEY_FONT_APP);

        if ($pubbliche !== null) {
            $righe[] = '--font-auth: ' . self::pila($pubbliche);
        }

        if ($interno !== null) {
            $righe[] = '--font-sans: ' . self::pila($interno);
        }

        $css = ':root{' . implode(';', $righe) . '}';

        // La dimensione del testo non e' una variabile ma la misura di
        // riferimento dell'intera pagina: nel foglio di stile quasi tutto e'
        // in `rem`, quindi cambiandola crescono insieme testo, riempimenti e
        // spazi. Sta su `html` e non su `:root` per leggibilita' — sono lo
        // stesso elemento — e si stampa solo se diversa da quella di
        // fabbrica, che e' gia' il valore predefinito del browser.
        $misura = self::misuraTestoCorrente();

        if ($misura !== self::MISURA_TESTO_PREDEFINITA) {
            $css .= 'html{font-size:' . self::misuraValida(self::MISURE_TESTO[$misura]['px']) . '}';
        }

        return $css;
    }

    /**
     * C'e' davvero qualcosa che si discosta dai valori di fabbrica?
     *
     * Serve a non stampare su ogni pagina un blocco identico a quello che
     * sovrascrive. Le cinque scelte si guardano tutte: basta che una sia
     * diversa.
     */
    private static function qualcosaDaCambiare(): bool
    {
        return self::paletteCorrente() !== self::PREDEFINITA
            || self::coloreValido((string) Settings::get(self::KEY_PRIMARY, '')) !== null
            || self::coloreValido((string) Settings::get(self::KEY_TEXT_COLOR, '')) !== null
            || self::raggioCorrente() !== self::RAGGIO_PREDEFINITO
            || self::misuraTestoCorrente() !== self::MISURA_TESTO_PREDEFINITA
            || self::misuraScenaCorrente() !== self::MISURA_SCENA_PREDEFINITA
            || self::fontScelto(self::KEY_FONT_AUTH) !== null
            || self::fontScelto(self::KEY_FONT_APP) !== null;
    }

    /**
     * Una misura in pixel scritta da noi, non dall'admin — ma si controlla
     * comunque, per la stessa ragione dei colori: finisce in un tag
     * `<style>`, e un elenco chiuso vale finche' nessuno lo allarga
     * distrattamente.
     */
    private static function misuraValida(string $valore): string
    {
        return preg_match('/^\d{1,3}px$/', $valore) === 1 ? $valore : '0px';
    }

    /**
     * La famiglia piu' il suo ripiego generico: `"Lora", serif`.
     *
     * Il generico non e' decorazione. Mentre il file si scarica, e se un
     * giorno sparisse, il testo si disegna con qualcosa della stessa
     * natura — un serif al posto di un serif — invece che con qualcosa di
     * diverso.
     */
    private static function pila(string $famiglia): string
    {
        $nome = (string) preg_replace('/[^A-Za-z0-9 ]/', '', $famiglia);
        $generico = FontLibrary::generico($famiglia);
        $generico = in_array($generico, ['sans-serif', 'serif', 'monospace', 'cursive'], true)
            ? $generico
            : 'sans-serif';

        return '"' . $nome . '", ' . $generico;
    }

    /** Come sopra, per le misure in rem. */
    private static function misuraRemValida(string $valore): string
    {
        return preg_match('/^\d{1,2}(\.\d{1,2})?rem$/', $valore) === 1 ? $valore : '1rem';
    }

    // ---------------------------------------------------------------
    // Colori: validazione e contrasto
    // ---------------------------------------------------------------

    /**
     * Un colore accettabile, o null. Solo `#rgb` e `#rrggbb`: tutto il resto
     * non entra nel foglio di stile.
     */
    public static function coloreValido(string $valore): ?string
    {
        $valore = strtoupper(trim($valore));

        if (preg_match('/^#[0-9A-F]{3}$/', $valore) === 1) {
            // Forma corta espansa subito: cosi' tutto il resto del file
            // lavora su un formato solo.
            return '#' . $valore[1] . $valore[1] . $valore[2] . $valore[2] . $valore[3] . $valore[3];
        }

        return preg_match('/^#[0-9A-F]{6}$/', $valore) === 1 ? $valore : null;
    }

    /** Un `rgba(...)` scritto da noi, non dall'admin: si controlla comunque. */
    private static function rgbaValido(string $valore): string
    {
        return preg_match('/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*[01](\.\d+)?\s*\)$/', $valore) === 1
            ? $valore
            : 'rgba(0, 0, 0, 0)';
    }

    /**
     * Rapporto di contrasto fra due colori, secondo la formula delle WCAG.
     * Va da 1 (identici) a 21 (nero su bianco).
     */
    public static function contrasto(string $a, string $b): float
    {
        $la = self::luminanza($a);
        $lb = self::luminanza($b);
        $chiaro = max($la, $lb);
        $scuro = min($la, $lb);

        return ($chiaro + 0.05) / ($scuro + 0.05);
    }

    private static function luminanza(string $hex): float
    {
        $hex = self::coloreValido($hex) ?? '#000000';
        $canali = [];

        foreach ([1, 3, 5] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            // La formula delle WCAG: i canali non si sommano grezzi, ma dopo
            // la correzione di gamma.
            $canali[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $canali[0] + 0.7152 * $canali[1] + 0.0722 * $canali[2];
    }

    /**
     * Il colore principale proposto e' usabile? Torna null se va bene, o il
     * motivo per cui no.
     *
     * Tre prove, che sono i tre posti dove quel colore compare davvero: come
     * testo su bianco (collegamenti, titoli), come sfondo di un pulsante con
     * scritta bianca, e come testo sullo sfondo delle pagine.
     */
    public static function perche(string $hex): ?string
    {
        $colore = self::coloreValido($hex);

        if ($colore === null) {
            return 'Il colore va scritto come #rrggbb, per esempio #4F7256.';
        }

        $prove = [
            'come testo su fondo bianco' => self::contrasto($colore, self::SUPERFICIE),
            'per la scritta bianca dei pulsanti' => self::contrasto('#FFFFFF', $colore),
            'come testo sullo sfondo delle pagine' => self::contrasto($colore, self::SFONDO_PAGINA),
        ];

        foreach ($prove as $dove => $rapporto) {
            if ($rapporto < self::MINIMO) {
                return sprintf(
                    'Contrasto insufficiente %s: %.2f:1, mentre ne servono almeno %.1f:1. '
                    . 'Un colore più scuro risolve.',
                    $dove,
                    $rapporto,
                    self::MINIMO
                );
            }
        }

        return null;
    }

    /**
     * Il colore del testo proposto e' usabile? Torna null se va bene, o il
     * motivo per cui no.
     *
     * Il testo sta su piu' fondi: il bianco dei riquadri, il grigio
     * chiarissimo delle pagine, e la tinta tenue di **ogni** tavolozza —
     * quest'ultima perche' l'admin puo' cambiare tavolozza dopo aver scelto
     * il colore, e un colore che va bene solo con la tavolozza di oggi
     * diventerebbe illeggibile domani senza che nessuno glielo dica.
     */
    public static function percheTesto(string $hex): ?string
    {
        $colore = self::coloreValido($hex);

        if ($colore === null) {
            return 'Il colore va scritto come #rrggbb, per esempio #1C1C1A.';
        }

        $prove = [
            'sui riquadri bianchi' => self::contrasto($colore, self::SUPERFICIE),
            'sullo sfondo delle pagine' => self::contrasto($colore, self::SFONDO_PAGINA),
        ];

        foreach (self::TAVOLOZZE as $tavolozza) {
            $prove['sulla tinta tenue della tavolozza ' . $tavolozza['nome']]
                = self::contrasto($colore, $tavolozza['soft']);
        }

        foreach ($prove as $dove => $rapporto) {
            if ($rapporto < self::MINIMO) {
                return sprintf(
                    'Contrasto insufficiente %s: %.2f:1, mentre ne servono almeno %.1f:1. '
                    . 'Un colore più scuro risolve.',
                    $dove,
                    $rapporto,
                    self::MINIMO
                );
            }
        }

        return null;
    }

    /**
     * Variante piu' scura, per lo stato "sopra" dei pulsanti e dei
     * collegamenti. Si scurisce in modo proporzionale e non sottraendo un
     * valore fisso: su un colore gia' scuro una sottrazione fissa lo
     * porterebbe a nero.
     */
    public static function scurisci(string $hex, float $quanto): string
    {
        return self::mescola($hex, 1 - $quanto);
    }

    /**
     * Tinta chiarissima per gli sfondi tenui (`--color-primary-soft`):
     * il colore mescolato al bianco.
     */
    public static function schiarisci(string $hex, float $versoIlBianco): string
    {
        $colore = self::coloreValido($hex) ?? '#000000';
        $out = '#';

        foreach ([1, 3, 5] as $i) {
            $c = (int) hexdec(substr($colore, $i, 2));
            $out .= str_pad(
                strtoupper(dechex((int) round($c + (255 - $c) * $versoIlBianco))),
                2,
                '0',
                STR_PAD_LEFT
            );
        }

        return $out;
    }

    /**
     * Un colore spostato verso un altro, di `$quanto` (0 = fermo, 1 = arrivato).
     */
    public static function mescolaVerso(string $da, string $a, float $quanto): string
    {
        $x = self::coloreValido($da) ?? '#000000';
        $y = self::coloreValido($a) ?? '#FFFFFF';
        $out = '#';

        foreach ([1, 3, 5] as $i) {
            $cx = (int) hexdec(substr($x, $i, 2));
            $cy = (int) hexdec(substr($y, $i, 2));
            $c = (int) round($cx + ($cy - $cx) * $quanto);
            $out .= str_pad(strtoupper(dechex(max(0, min(255, $c)))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    private static function mescola(string $hex, float $fattore): string
    {
        $colore = self::coloreValido($hex) ?? '#000000';
        $out = '#';

        foreach ([1, 3, 5] as $i) {
            $c = (int) round(hexdec(substr($colore, $i, 2)) * $fattore);
            $out .= str_pad(strtoupper(dechex(max(0, min(255, $c)))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }
}
