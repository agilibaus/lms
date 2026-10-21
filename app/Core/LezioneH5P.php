<?php

declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * I contenuti H5P dentro le lezioni (23/10, Alessandro, passo 3).
 *
 * LE DECISIONI (23/10):
 * - **piu' contenuti per lezione**, oltre al questionario del modulo;
 * - **dove vuole chi scrive**: il pulsante «Esercizio H5P» dell'editor
 *   mette nel testo un segnaposto, `<div data-h5p="12">…</div>`, che nella
 *   lezione diventa l'esercizio. Il testo del segnaposto serve solo a chi
 *   scrive, e l'editor lo riscrive a ogni apertura;
 * - un contenuto **non messo** nel testo compare dopo il testo; uno messo
 *   **due volte** compare una volta sola, al primo segnaposto; un segnaposto
 *   di un contenuto eliminato non mostra niente;
 * - **qualunque tipo**, anche senza punteggio, e **nessun punteggio salvato**;
 * - «Segna come completata» si sblocca quando lo studente ha **usato** tutti
 *   gli esercizi della lezione (una qualunque interazione: vedi
 *   `/assets/js/h5p-lezione.js`). Come per il video, la regola sta nel
 *   browser: senza JavaScript il pulsante resta attivo.
 *
 * Qui le regole sul testo, senza database: i dati in `LessonH5PModel`, i
 * file in `H5PArchivio`.
 */
final class LezioneH5P
{
    /** L'attributo del segnaposto; il sanificatore lo lascia solo con un numero. */
    public const ATTRIBUTO = 'data-h5p';

    /** Il commento che prende il posto di un segnaposto durante la composizione. */
    private const SEGNO = 'pistacchio-h5p:';

    /**
     * Gli id dei segnaposto nel testo, nell'ordine e senza ripetizioni.
     *
     * @return list<int>
     */
    public static function segnaposti(?string $html): array
    {
        $ids = [];

        foreach (self::nodi($html)[1] as $nodo) {
            $ids[] = (int) $nodo->getAttribute(self::ATTRIBUTO);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Il testo della lezione con gli esercizi al posto dei segnaposto.
     * `$player` riceve l'id e restituisce l'HTML dell'esercizio. Un id che
     * non e' fra `$validi` (contenuto eliminato) sparisce; uno ripetuto
     * compare al primo segnaposto e gli altri spariscono.
     *
     * @param list<int>              $validi
     * @param callable(int): string  $player
     * @return array{html: string, messi: list<int>}
     */
    public static function componi(?string $html, array $validi, callable $player): array
    {
        $html = (string) $html;

        if (!str_contains($html, self::ATTRIBUTO)) {
            return ['html' => $html, 'messi' => []];
        }

        [$documento, $nodi, $radice] = self::nodi($html);

        if ($documento === null || $radice === null) {
            return ['html' => $html, 'messi' => []];
        }

        $messi = [];

        foreach ($nodi as $nodo) {
            $id = (int) $nodo->getAttribute(self::ATTRIBUTO);

            if (in_array($id, $validi, true) && !in_array($id, $messi, true)) {
                $messi[] = $id;
                $nodo->parentNode?->replaceChild($documento->createComment(self::SEGNO . $id), $nodo);
            } else {
                $nodo->parentNode?->removeChild($nodo);
            }
        }

        $uscita = '';

        foreach (iterator_to_array($radice->childNodes) as $figlio) {
            $uscita .= $documento->saveHTML($figlio);
        }

        $uscita = (string) preg_replace_callback(
            '/<!--' . preg_quote(self::SEGNO, '/') . '(\d+)-->/',
            static fn (array $m): string => $player((int) $m[1]),
            $uscita
        );

        return ['html' => trim($uscita), 'messi' => $messi];
    }

    /**
     * I contenuti non messi nel testo, nel loro ordine: compaiono dopo.
     *
     * @param list<array<string, mixed>> $contenuti con `id`
     * @param list<int>                  $messi
     * @return list<array<string, mixed>>
     */
    public static function dopoIlTesto(array $contenuti, array $messi): array
    {
        return array_values(array_filter($contenuti, static fn (array $c): bool => !in_array((int) $c['id'], $messi, true)));
    }

    /** Il testo del segnaposto nell'editor: «Esercizio interattivo: stop.h5p (Drag the Words)». */
    public static function etichetta(array $contenuto): string
    {
        return 'Esercizio interattivo: ' . $contenuto['h5p_file'] . ' (' . H5P::nome((string) $contenuto['h5p_library']) . ')';
    }

    /** Se un testo ha qualcosa da leggere oltre ai segnaposto (per la regola del solo video). */
    public static function testoSenzaSegnaposti(?string $html): string
    {
        $html = (string) $html;

        if (!str_contains($html, self::ATTRIBUTO)) {
            return trim(strip_tags($html));
        }

        return trim(strip_tags(self::componi($html, [], static fn (int $id): string => '')['html']));
    }

    /**
     * Il documento, i segnaposto e la radice. I segnaposto sono i `div` con
     * l'attributo numerico: il sanificatore lascia l'attributo solo li'.
     *
     * @return array{0: ?DOMDocument, 1: list<DOMElement>, 2: ?DOMElement}
     */
    private static function nodi(?string $html): array
    {
        $html = (string) $html;

        if (!str_contains($html, self::ATTRIBUTO)) {
            return [null, [], null];
        }

        $documento = new DOMDocument('1.0', 'UTF-8');
        $prima = libxml_use_internal_errors(true);
        $documento->loadHTML(
            '<?xml encoding="UTF-8"?><div id="pistacchio-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prima);

        $radice = $documento->getElementById('pistacchio-root');
        $nodi = [];

        foreach ((new DOMXPath($documento))->query('//div[@' . self::ATTRIBUTO . ']') ?: [] as $nodo) {
            if ($nodo instanceof DOMElement && preg_match('/^\d{1,10}$/', $nodo->getAttribute(self::ATTRIBUTO)) === 1) {
                $nodi[] = $nodo;
            }
        }

        return [$documento, $nodi, $radice];
    }
}
