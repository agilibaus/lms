<?php

declare(strict_types=1);

namespace App\Core;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Ripulisce l'HTML prodotto dall'editor prima di salvarlo.
 *
 * Funziona per lista consentita: tutto ciò che non è esplicitamente ammesso
 * viene rimosso. Il testo resta, i tag sconosciuti vengono srotolati, gli
 * elementi pericolosi (script, style, form...) spariscono con il loro contenuto.
 *
 * Gli autori delle lezioni sono admin e tutor, quindi gente di cui ci si fida:
 * questa barriera serve contro un account compromesso, contro il codice
 * incollato per sbaglio da un'altra pagina e contro un tutor che non dovrebbe
 * poter eseguire script nel browser degli studenti.
 */
class HtmlSanitizer
{
    /**
     * Tag ammessi, con i rispettivi attributi.
     *
     * `style` (20/11, Alessandro: «la formattazione libera nell'editor deve
     * esserci sempre») sta sui blocchi di testo e su `span`, ed e' ripulito
     * da `stileSicuro()`: passano solo le proprieta' di `STILE`.
     */
    private const ALLOWED = [
        'p' => ['style'], 'br' => [], 'hr' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'sub' => [], 'sup' => [], 'mark' => [],
        'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'],
        'ul' => [], 'ol' => ['start', 'type'], 'li' => ['style'],
        'blockquote' => ['cite', 'style'], 'pre' => [], 'code' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'figure' => [], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope', 'style'], 'td' => ['colspan', 'rowspan', 'style'],
        'caption' => [], 'colgroup' => ['span'], 'col' => ['span'],
        // `data-h5p`: il segnaposto di un contenuto H5P della lezione
        // (23/10, App\Core\LezioneH5P), solo con un numero.
        'span' => ['style'], 'div' => ['data-h5p', 'style'],
        'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder'],
    ];

    /**
     * La formattazione libera dell'editor (20/11, Alessandro): le sole
     * proprieta' che passano dentro `style`, ciascuna con la forma dei valori
     * ammessi. Sono quelle dei comandi del menu Formato di TinyMCE:
     * carattere, dimensione, colore del testo e dello sfondo, allineamento,
     * altezza della riga. Tutto il resto si toglie, e con `url(`,
     * `expression`, barre rovesciate o commenti si toglie anche la
     * dichiarazione intera: `style` e' una porta, e resta stretta.
     */
    private const STILE = [
        'font-family' => '/^(?:\s*(?:"[A-Za-z0-9 _-]{1,60}"|\'[A-Za-z0-9 _-]{1,60}\'|[A-Za-z0-9 _-]{1,60})\s*)(?:,(?:\s*(?:"[A-Za-z0-9 _-]{1,60}"|\'[A-Za-z0-9 _-]{1,60}\'|[A-Za-z0-9 _-]{1,60})\s*)){0,5}$/',
        'font-size' => '/^(?:\d{1,3}(?:\.\d{1,2})?(?:pt|px|em|rem|%)|xx-small|x-small|small|medium|large|x-large|xx-large|smaller|larger)$/',
        'color' => self::COLORE,
        'background-color' => self::COLORE,
        'text-align' => '/^(?:left|right|center|justify|start|end)$/',
        'line-height' => '/^(?:normal|\d{1,2}(?:\.\d{1,3})?(?:px|em|rem|%)?)$/',
    ];

    /** Un colore: esadecimale, `rgb()`/`rgba()` con soli numeri, o un nome. */
    private const COLORE = '/^(?:#[0-9A-Fa-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3})\s*)?\)|[A-Za-z]{3,20})$/';

    /** Elementi da eliminare insieme al loro contenuto. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'noscript', 'template', 'form', 'object', 'embed', 'applet'];

    /** Host da cui è ammesso un iframe (video incorporati). */
    private const IFRAME_HOSTS = [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'player.vimeo.com', 'vimeo.com',
    ];

    /** Schemi ammessi nei collegamenti. */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * @param array<string, list<string>>|null $allowed i tag ammessi con i loro
     *        attributi; senza, quelli delle lezioni. I testi brevi del corso
     *        ne usano meno (`TestoBreve::TAG`, 11/10).
     */
    public static function clean(?string $html, ?array $allowed = null): ?string
    {
        $allowed ??= self::ALLOWED;

        if ($html === null) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // Il prologo XML forza l'interpretazione UTF-8; i flag evitano che
        // DOMDocument aggiunga <html>, <body> e un doctype.
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="pistacchio-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('pistacchio-root');

        if ($root === null) {
            return null;
        }

        self::dropForbidden($document);
        self::cleanElement($root, $allowed);

        $clean = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $clean .= $document->saveHTML($child);
        }

        $clean = trim($clean);

        return $clean === '' ? null : $clean;
    }

    // ---------------------------------------------------------------

    private static function dropForbidden(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $query = implode(' | ', array_map(
            static fn (string $tag): string => '//' . $tag,
            self::DROP_WITH_CONTENT
        ));

        foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Commenti: possono contenere codice per vecchi browser, e non servono.
        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $comment) {
            $comment->parentNode?->removeChild($comment);
        }
    }

    /** @param array<string, list<string>> $allowed */
    private static function cleanElement(DOMNode $node, array $allowed): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (!array_key_exists($tag, $allowed)) {
                // Tag sconosciuto: si tiene il contenuto e si butta l'involucro.
                self::cleanElement($child, $allowed);
                self::unwrap($child);

                continue;
            }

            self::cleanAttributes($child, $allowed[$tag]);

            if ($tag === 'iframe' && !self::isAllowedIframe($child)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            self::cleanElement($child, $allowed);
        }
    }

    /** @param list<string> $allowed */
    private static function cleanAttributes(DOMElement $element, array $allowed): void
    {
        $tag = strtolower($element->nodeName);

        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->nodeName);

            // Via ogni gestore di eventi (onclick, onerror...) e tutto ciò che
            // non è nella lista del tag.
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (($name === 'href' || $name === 'src') && !self::isSafeUrl($attribute->nodeValue ?? '', $name)) {
                $element->removeAttribute($attribute->nodeName);
            }

            if ($name === 'data-h5p' && preg_match('/^\d{1,10}$/', (string) $attribute->nodeValue) !== 1) {
                $element->removeAttribute($attribute->nodeName);
            }

            if ($name === 'style') {
                $stile = self::stileSicuro((string) $attribute->nodeValue);

                if ($stile === '') {
                    $element->removeAttribute($attribute->nodeName);
                } else {
                    $element->setAttribute('style', $stile);
                }
            }
        }

        // Un link che si apre in una nuova scheda senza rel="noopener" lascia
        // alla pagina di destinazione un riferimento alla nostra finestra.
        if ($tag === 'a' && $element->getAttribute('target') !== '') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Le dichiarazioni di `style` che passano, riscritte una per una
     * («proprieta: valore;»), o una stringa vuota. Una dichiarazione con una
     * proprieta' non ammessa, o un valore che non ha la forma attesa, si
     * toglie da sola; le altre restano.
     */
    public static function stileSicuro(string $stile): string
    {
        // Prima di tutto cio' che non deve mai stare in uno stile, ovunque
        // si trovi: chi lo scrive non sta usando il menu Formato.
        if (preg_match('/url\s*\(|expression|javascript:|\\\\|\/\*|[<>{}@]/i', $stile) === 1) {
            return '';
        }

        $tenute = [];

        foreach (explode(';', $stile) as $dichiarazione) {
            $parti = explode(':', $dichiarazione, 2);

            if (count($parti) !== 2) {
                continue;
            }

            $proprieta = strtolower(trim($parti[0]));
            $valore = trim($parti[1]);
            $forma = self::STILE[$proprieta] ?? null;

            if ($forma === null || $valore === '' || preg_match($forma, $valore) !== 1) {
                continue;
            }

            $tenute[$proprieta] = $proprieta . ': ' . $valore . ';';
        }

        return implode(' ', $tenute);
    }

    private static function isSafeUrl(string $url, string $attribute): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Percorsi interni e ancore: sempre ammessi.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '') {
            return false;
        }

        // 'data:' e 'javascript:' restano fuori: il primo permette di incorporare
        // documenti arbitrari, il secondo esegue codice.
        return $attribute === 'href'
            ? in_array($scheme, self::LINK_SCHEMES, true)
            : in_array($scheme, ['http', 'https'], true);
    }

    private static function isAllowedIframe(DOMElement $iframe): bool
    {
        $src = trim($iframe->getAttribute('src'));

        if ($src === '') {
            return false;
        }

        $host = strtolower((string) parse_url($src, PHP_URL_HOST));

        return in_array($host, self::IFRAME_HOSTS, true);
    }

    /**
     * Sostituisce un elemento con i suoi figli.
     */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
