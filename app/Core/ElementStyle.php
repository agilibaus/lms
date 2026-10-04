<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ritocchi di tipografia su singoli elementi delle pagine pubbliche.
 *
 * PERCHE' UN MECCANISMO E NON ALTRI COMANDI. Fino alla 0084 ogni richiesta
 * — i colori, l'arrotondamento, la misura del testo, la misura della
 * presentazione — era diventata un comando suo. Funziona per quattro, non
 * per quaranta: la pagina delle impostazioni cresce finche' non si legge
 * piu', ed e' esattamente il problema che Elena ha segnalato il 04/10
 * chiedendo come togliere il grassetto a un titolo.
 *
 * Qui l'impianto cambia. C'e' un **elenco di elementi** (`ELEMENTI`) e un
 * **elenco di proprieta'** (`PROPRIETA`), e ogni elemento si regola con le
 * stesse proprieta'. Aggiungere domani "il titolo della lezione" costa una
 * riga in `ELEMENTI`, non un riquadro nuovo nel pannello.
 *
 * COSA RENDE QUESTO DIVERSO DAL «CSS LIBERO», che §8.5 ha escluso:
 *
 *   - **i selettori li scriviamo noi** e stanno qui dentro: dal pannello non
 *     si sceglie *dove* applicare qualcosa, solo *che cosa* applicare a un
 *     elemento che abbiamo gia' nominato;
 *   - **i valori vengono da elenchi chiusi**: non si scrive `font-weight`,
 *     si sceglie "Normale" da una tendina. Un valore che non e' nell'elenco
 *     viene ignorato, non stampato;
 *   - **nessuna proprieta' puo' nascondere niente**. Niente `display`,
 *     niente `visibility`, niente `position`: e' la trappola di §5 — un
 *     campo nascosto resta inviato — e qui non deve poter rientrare da una
 *     tendina.
 *
 * Il prezzo e' quello che §8.5 chiamava **un impegno**: questi selettori
 * diventano un contratto. Chi rinomina `.scene-claim h2` deve aggiornare
 * anche questa tabella, o un ritocco salvato smettera' di avere effetto
 * restando salvato — il difetto peggiore, perche' non si vede.
 */
class ElementStyle
{
    /** Tutti i ritocchi stanno in una chiave sola, come JSON. */
    public const KEY = 'THEME_ELEMENTI';

    /**
     * Gli elementi che si possono ritoccare.
     *
     * `solo` dice in quale aspetto l'elemento esiste: mostrarne uno che
     * nella struttura scelta non c'e' vorrebbe dire comandi che non fanno
     * niente, e chi li prova pensa che siano rotti.
     *
     * @var array<string, array{nome: string, descrizione: string, selettore: string, solo: ?string}>
     */
    public const ELEMENTI = [
        'scene_titolo' => [
            'nome' => 'Titolo della presentazione',
            'descrizione' => 'Le righe grandi a sinistra, «Impara con calma…».',
            'selettore' => '.scene-claim h2',
            'solo' => AuthLayout::AFFIANCATO,
        ],
        'scene_testo' => [
            'nome' => 'Testo della presentazione',
            'descrizione' => 'Il paragrafo sotto al titolo.',
            'selettore' => '.scene-claim p',
            'solo' => AuthLayout::AFFIANCATO,
        ],
        'marchio' => [
            'nome' => 'Nome della piattaforma',
            'descrizione' => '«Pistacchio LMS» accanto al simbolo, in alto a sinistra.',
            'selettore' => '.scene-brand',
            'solo' => AuthLayout::AFFIANCATO,
        ],
        'titolo_riquadro' => [
            'nome' => 'Titolo del modulo',
            'descrizione' => '«Accedi», «Registrati»: il titolo sopra ai campi.',
            'selettore' => '.auth-panel-title, .auth-title',
            'solo' => null,
        ],
        'sottotitolo_riquadro' => [
            'nome' => 'Riga sotto al titolo',
            'descrizione' => '«Accedi alla piattaforma corsi».',
            'selettore' => '.auth-subtitle',
            'solo' => null,
        ],
        'pulsante' => [
            'nome' => 'Pulsante principale',
            'descrizione' => 'Il pulsante verde in fondo al modulo.',
            'selettore' => '.auth-form .btn',
            'solo' => null,
        ],
    ];

    /**
     * Le proprieta', con i valori ammessi.
     *
     * Il valore vuoto vuol dire «come adesso» e non stampa niente: cosi' una
     * pagina che nessuno ha toccato resta identica, e il blocco CSS contiene
     * solo quello che e' stato davvero cambiato.
     *
     * @var array<string, array{nome: string, css: string, valori: array<string, string>}>
     */
    public const PROPRIETA = [
        'peso' => [
            'nome' => 'Peso',
            'css' => 'font-weight',
            'valori' => [
                '' => 'Come adesso',
                '300' => 'Leggero',
                '400' => 'Normale',
                '500' => 'Medio',
                '600' => 'Semigrassetto',
                '700' => 'Grassetto',
                '800' => 'Nero',
            ],
        ],
        'stile' => [
            'nome' => 'Stile',
            'css' => 'font-style',
            'valori' => ['' => 'Come adesso', 'normal' => 'Dritto', 'italic' => 'Corsivo'],
        ],
        'trasformazione' => [
            'nome' => 'Lettere',
            'css' => 'text-transform',
            'valori' => [
                '' => 'Come adesso',
                'none' => 'Come scritte',
                'uppercase' => 'TUTTE MAIUSCOLE',
                'lowercase' => 'tutte minuscole',
            ],
        ],
        'spaziatura' => [
            'nome' => 'Spaziatura fra le lettere',
            'css' => 'letter-spacing',
            'valori' => [
                '' => 'Come adesso',
                '-0.02em' => 'Stretta',
                '0' => 'Normale',
                '0.03em' => 'Larga',
                '0.08em' => 'Molto larga',
            ],
        ],
        'allineamento' => [
            'nome' => 'Allineamento',
            'css' => 'text-align',
            'valori' => [
                '' => 'Come adesso',
                'left' => 'A sinistra',
                'center' => 'Centrato',
                'right' => 'A destra',
            ],
        ],
        'colore' => [
            // Unica proprieta' a testo libero, e l'unica che puo' rendere
            // illeggibile qualcosa: per questo passa dal controllo del
            // contrasto al salvataggio, come il colore principale.
            'nome' => 'Colore',
            'css' => 'color',
            'valori' => [],
        ],
    ];

    /**
     * Quello che e' stato salvato, ripulito.
     *
     * @return array<string, array<string, string>>
     */
    public static function valori(): array
    {
        $grezzo = (string) Settings::get(self::KEY, '');

        if ($grezzo === '') {
            return [];
        }

        $letto = json_decode($grezzo, true);

        return is_array($letto) ? self::ripulisci($letto) : [];
    }

    /**
     * Tiene solo elementi, proprieta' e valori che conosciamo.
     *
     * Si applica **sia in lettura sia in scrittura**, non per diffidenza del
     * proprio codice ma perche' il valore passa dal database: una riga
     * scritta a mano, o rimasta da una versione in cui un elemento esisteva,
     * non deve diventare CSS.
     *
     * @param array<mixed> $dati
     * @return array<string, array<string, string>>
     */
    public static function ripulisci(array $dati): array
    {
        $pulito = [];

        foreach ($dati as $elemento => $proprieta) {
            if (!is_string($elemento) || !isset(self::ELEMENTI[$elemento]) || !is_array($proprieta)) {
                continue;
            }

            foreach ($proprieta as $nome => $valore) {
                if (!is_string($nome) || !isset(self::PROPRIETA[$nome]) || !is_string($valore) || $valore === '') {
                    continue;
                }

                if ($nome === 'colore') {
                    $colore = Theme::coloreValido($valore);

                    if ($colore !== null) {
                        $pulito[$elemento][$nome] = $colore;
                    }

                    continue;
                }

                // Elenco chiuso: se il valore non e' una delle voci della
                // tendina, non esiste.
                if (isset(self::PROPRIETA[$nome]['valori'][$valore])) {
                    $pulito[$elemento][$nome] = $valore;
                }
            }
        }

        return $pulito;
    }

    /**
     * Il CSS dei ritocchi, da stampare dopo il foglio di stile.
     *
     * Vale solo nelle pagine pubbliche, quindi lo chiede `_card.php` e non
     * il guscio dell'applicazione.
     */
    public static function blocco(): string
    {
        $aspetto = AuthLayout::current();
        $css = '';

        foreach (self::valori() as $elemento => $proprieta) {
            $definizione = self::ELEMENTI[$elemento];

            // Un elemento che in questo aspetto non esiste non si stampa:
            // sarebbe una regola che non trova niente, e nel foglio di stile
            // le regole morte confondono chi un giorno andra' a leggerlo.
            if ($definizione['solo'] !== null && $definizione['solo'] !== $aspetto) {
                continue;
            }

            $righe = [];

            foreach ($proprieta as $nome => $valore) {
                $righe[] = self::PROPRIETA[$nome]['css'] . ':' . $valore;
            }

            if ($righe !== []) {
                $css .= $definizione['selettore'] . '{' . implode(';', $righe) . '}';
            }
        }

        return $css;
    }

    /**
     * I colori proposti sono leggibili? Torna null se si', o il motivo.
     *
     * Ogni elemento sta su un fondo noto — i due della presentazione e il
     * marchio sul crema, gli altri sul bianco del riquadro — e il controllo
     * si fa su quello. Il crema cambia con la tavolozza, quindi si prova
     * contro **tutte e quattro**: la tavolozza si puo' cambiare dopo.
     *
     * @param array<string, array<string, string>> $valori
     */
    public static function perche(array $valori): ?string
    {
        foreach ($valori as $elemento => $proprieta) {
            if (!isset($proprieta['colore'])) {
                continue;
            }

            $colore = $proprieta['colore'];
            $nome = self::ELEMENTI[$elemento]['nome'];
            $suCrema = self::ELEMENTI[$elemento]['solo'] === AuthLayout::AFFIANCATO;

            $fondi = ['sul riquadro bianco' => Theme::SUPERFICIE];

            if ($suCrema) {
                $fondi = [];

                foreach (Theme::TAVOLOZZE as $tavolozza) {
                    $fondi['sul fondo della tavolozza ' . $tavolozza['nome']] = $tavolozza['auth_bg'];
                }
            }

            foreach ($fondi as $dove => $fondo) {
                $rapporto = Theme::contrasto($colore, $fondo);

                if ($rapporto < Theme::MINIMO) {
                    return sprintf(
                        '«%s»: il colore %s %s dà un contrasto di %.2f:1, mentre ne servono '
                        . 'almeno %.1f:1. Un colore più scuro risolve.',
                        $nome,
                        $colore,
                        $dove,
                        $rapporto,
                        Theme::MINIMO
                    );
                }
            }
        }

        return null;
    }
}
