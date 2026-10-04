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

    public const PREDEFINITA = 'verde';

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
        if (self::paletteCorrente() === self::PREDEFINITA
            && self::coloreValido((string) Settings::get(self::KEY_PRIMARY, '')) === null) {
            return '';
        }

        $v = self::valori();

        $righe = [
            '--color-primary: ' . $v['primary'],
            '--color-primary-hover: ' . $v['hover'],
            '--color-primary-soft: ' . $v['soft'],
            '--color-auth-bg: ' . $v['auth_bg'],
            '--color-auth-orb-sage: ' . self::rgbaValido($v['orb_a']),
            '--color-auth-orb-terracotta: ' . self::rgbaValido($v['orb_b']),
            '--color-scene-title: ' . $v['scene_h'],
            '--color-scene-text: ' . $v['scene_p'],
        ];

        return ':root{' . implode(';', $righe) . '}';
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
