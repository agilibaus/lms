<?php

declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Un testo breve scritto in un'area di testo, con qualche tag HTML scritto a
 * mano: l'obiettivo e la descrizione del corso, l'obiettivo del modulo
 * (11/10, scelte di Alessandro).
 *
 * Le regole:
 *
 *   - **si salva quello che si scrive**, a capo convertiti e spazi ai bordi
 *     tolti, e **si ripulisce quando si mostra**: chi riapre il campo
 *     ritrova il testo come l'ha scritto, senza `&amp;` comparsi al posto
 *     delle sue «&»;
 *   - **i tag ammessi sono pochi, da testo**: paragrafi, a capo, grassetto,
 *     corsivo, sottolineato, elenchi e collegamenti (`TAG`). Gli altri si
 *     tolgono tenendo il testo, come nelle lezioni (`HtmlSanitizer`);
 *   - **gli a capo valgono come prima**: Invio va a capo, una riga vuota apre
 *     un paragrafo; vicino ai tag di paragrafi ed elenchi non contano, cosi'
 *     un elenco scritto su piu' righe non si riempie di righe vuote. Le
 *     descrizioni scritte prima, senza tag, si vedono come si vedevano;
 *   - **il limite conta il testo visibile**, non i tag (`caratteri()`), e lo
 *     conta allo stesso modo il contatore nel browser (`quiz-open-count.js`,
 *     campi con `data-conta="testo"`). Il campo non ha `maxlength`, che
 *     conterebbe anche i tag: oltre il limite **il server rifiuta** e
 *     ripropone il testo, non taglia.
 */
final class TestoBreve
{
    /** Tag ammessi, con i loro attributi. */
    public const TAG = [
        'p' => [], 'br' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'a' => ['href', 'title', 'target', 'rel'],
    ];

    /**
     * Quanto puo' pesare il testo scritto, tag compresi: una colonna TEXT
     * contiene 65.535 byte. Un testo dentro il limite visibile ci arriva solo
     * con un uso dei tag fuori misura.
     */
    public const MAX_BYTE = 65535;

    /** Elementi che vanno a capo da soli: attorno a loro gli a capo non contano. */
    private const BLOCCHI = ['p', 'ul', 'ol', 'li'];

    /** Il testo arrivato dal modulo: a capo convertiti, spazi ai bordi tolti. */
    public static function normalizza(string $valore): string
    {
        return trim(str_replace("\r\n", "\n", $valore));
    }

    /**
     * I caratteri visibili: senza i tag, con le entita' lette come il
     * carattere che sono, un a capo per uno. E' lo stesso conto che fa il
     * contatore nel browser, quindi il numero che si legge sopra il campo e
     * quello che il server confronta con il limite coincidono.
     */
    public static function caratteri(string $valore): int
    {
        // Un tag comincia con una lettera dopo «<» (o «</»): «a < b» e' testo.
        $senzaTag = (string) preg_replace('#</?[a-zA-Z][^>]*>#', '', str_replace("\r\n", "\n", $valore));

        return mb_strlen(html_entity_decode($senzaTag, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Di quanti caratteri visibili il testo supera il limite: zero se ci sta. */
    public static function eccesso(string $valore, int $massimo): int
    {
        return max(0, self::caratteri($valore) - $massimo);
    }

    /**
     * La frase per un testo oltre il limite: «L'obiettivo del corso supera di
     * 37 caratteri il limite di 1.000.» Null se ci sta.
     */
    public static function problema(string $soggetto, string $valore, int $massimo): ?string
    {
        if (strlen($valore) > self::MAX_BYTE) {
            return $soggetto . ' è troppo lungo per essere salvato, contando anche i tag.';
        }

        $oltre = self::eccesso($valore, $massimo);

        if ($oltre === 0) {
            return null;
        }

        return $soggetto . ' supera di ' . self::numero($oltre) . ($oltre === 1 ? ' carattere' : ' caratteri')
            . ' il limite di ' . self::numero($massimo) . '.';
    }

    /**
     * L'HTML da mostrare: ripulito con i soli tag ammessi, e con gli a capo
     * trasformati in paragrafi e righe. Stringa vuota per un testo vuoto.
     */
    public static function html(?string $valore): string
    {
        $pulito = HtmlSanitizer::clean(self::normalizza((string) $valore), self::TAG);

        if ($pulito === null) {
            return '';
        }

        $documento = new DOMDocument('1.0', 'UTF-8');
        $prima = libxml_use_internal_errors(true);
        $documento->loadHTML(
            '<?xml encoding="UTF-8"?><div id="testo-breve">' . $pulito . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prima);

        $radice = $documento->getElementById('testo-breve');

        if ($radice === null) {
            return '';
        }

        $risultato = new DOMDocument('1.0', 'UTF-8');
        $contenitore = $risultato->createElement('div');
        $risultato->appendChild($contenitore);

        // I figli della radice: i blocchi restano come sono (con gli a capo
        // interni trasformati), il resto si raccoglie in paragrafi, e una
        // riga vuota chiude il paragrafo in corso.
        $paragrafo = null;

        foreach (iterator_to_array($radice->childNodes) as $figlio) {
            if ($figlio instanceof DOMElement && in_array(strtolower($figlio->nodeName), self::BLOCCHI, true)) {
                $paragrafo = null;
                $contenitore->appendChild($risultato->importNode($figlio, true));

                continue;
            }

            if ($figlio instanceof DOMText) {
                $pezzi = preg_split('/\n\s*\n/', (string) $figlio->nodeValue) ?: [];

                foreach ($pezzi as $n => $pezzo) {
                    if ($n > 0) {
                        $paragrafo = null;
                    }

                    if (trim($pezzo) === '') {
                        continue;
                    }

                    if ($paragrafo === null) {
                        $paragrafo = $risultato->createElement('p');
                        $contenitore->appendChild($paragrafo);
                    }

                    $paragrafo->appendChild($risultato->createTextNode($pezzo));
                }

                continue;
            }

            if ($paragrafo === null) {
                $paragrafo = $risultato->createElement('p');
                $contenitore->appendChild($paragrafo);
            }

            $paragrafo->appendChild($risultato->importNode($figlio, true));
        }

        foreach (iterator_to_array($contenitore->childNodes) as $blocco) {
            self::aCapoDentro($blocco);
        }

        $html = '';

        foreach (iterator_to_array($contenitore->childNodes) as $blocco) {
            $html .= $risultato->saveHTML($blocco);
        }

        return $html;
    }

    /**
     * Il testo semplice, per gli estratti delle schede: niente tag, entita'
     * lette, spazi e a capo ridotti a uno spazio.
     */
    public static function testo(?string $valore): string
    {
        $html = self::html($valore);
        $html = (string) preg_replace('#<br\s*/?>|</(p|li)>|<(ul|ol)\b[^>]*>#i', ' ', $html);
        $testo = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $testo));
    }

    /**
     * Il campo con il contatore, come `CampoContato::html()`, ma senza
     * `maxlength` (conterebbe i tag) e con `data-conta="testo"`, che dice al
     * contatore di contare solo il testo visibile. Sotto, «Tag HTML
     * consentiti»: un comando che apre l'elenco dei tag sulla stessa riga, e
     * un secondo clic lo richiude (11/10, Alessandro). E' un `details`, quindi
     * funziona senza JavaScript; l'aspetto sta in `.tag-consentiti`.
     */
    public static function campo(string $id, string $etichetta, string $nome, int $massimo, string $valore, int $righe): string
    {
        $restano = $massimo - self::caratteri($valore);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

        return '<div class="quiz-open-wrap campo-contato">'
            . '<div class="campo-contato-testa">'
            . '<label for="' . $e($id) . '">' . $e($etichetta) . '</label>'
            . '<span class="quiz-open-count' . ($restano <= 100 ? ' quiz-open-count-basso' : '') . '" id="' . $e($id)
            . '-resta" data-max="' . $massimo . '">' . self::restano($restano) . '</span>'
            . '</div>'
            . '<textarea id="' . $e($id) . '" name="' . $e($nome) . '" rows="' . $righe . '" data-conta="testo"'
            . ' class="quiz-open-answer" aria-describedby="' . $e($id) . '-resta">'
            . $e($valore) . '</textarea>'
            . '</div>'
            . '<div class="form-hint tag-consentiti" id="' . $e($id) . '-aiuto">'
            . '<details><summary class="tag-consentiti-comando">Tag HTML consentiti</summary>'
            . '<span>: &lt;strong&gt;, &lt;em&gt;, &lt;u&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;a href="…"&gt;.</span>'
            . '</details></div>';
    }

    /** «37 caratteri rimasti», «1 carattere rimasto», «12 caratteri in più». */
    public static function restano(int $restano): string
    {
        if ($restano < 0) {
            return self::numero(-$restano) . ($restano === -1 ? ' carattere in più' : ' caratteri in più');
        }

        return $restano . ($restano === 1 ? ' carattere rimasto' : ' caratteri rimasti');
    }

    // ---------------------------------------------------------------

    /**
     * Dentro un blocco: gli a capo vicino ai tag di blocco si tolgono, quelli
     * in mezzo al testo diventano `<br>`.
     */
    private static function aCapoDentro(DOMNode $nodo): void
    {
        $documento = $nodo->ownerDocument;

        if ($documento === null) {
            return;
        }

        foreach (iterator_to_array($nodo->childNodes) as $figlio) {
            if ($figlio instanceof DOMElement) {
                self::aCapoDentro($figlio);

                continue;
            }

            if (!$figlio instanceof DOMText) {
                continue;
            }

            $testo = (string) $figlio->nodeValue;
            $padre = $figlio->parentNode;

            // In una lista il testo fra un <li> e l'altro e' solo spazio.
            if ($padre instanceof DOMElement && in_array(strtolower($padre->nodeName), ['ul', 'ol'], true)) {
                if (trim($testo) === '') {
                    $padre->removeChild($figlio);
                }

                continue;
            }

            // All'inizio e alla fine di un blocco, o accanto a un blocco
            // interno (una lista dentro un <li>), l'a capo non si vede.
            if ($figlio->previousSibling === null || self::eBlocco($figlio->previousSibling)) {
                $testo = (string) preg_replace('/^\s*\n\s*/', '', $testo);
            }

            if ($figlio->nextSibling === null || self::eBlocco($figlio->nextSibling)) {
                $testo = (string) preg_replace('/\s*\n\s*$/', '', $testo);
            }

            if (!str_contains($testo, "\n")) {
                $figlio->nodeValue = $testo;

                continue;
            }

            $righe = explode("\n", $testo);

            foreach ($righe as $n => $riga) {
                if ($n > 0) {
                    $padre?->insertBefore($documento->createElement('br'), $figlio);
                }

                $padre?->insertBefore($documento->createTextNode($riga), $figlio);
            }

            $padre?->removeChild($figlio);
        }
    }

    private static function eBlocco(DOMNode $nodo): bool
    {
        return $nodo instanceof DOMElement && in_array(strtolower($nodo->nodeName), self::BLOCCHI, true);
    }

    private static function numero(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
