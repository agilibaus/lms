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
    /** Tag ammessi, con i rispettivi attributi. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'sub' => [], 'sup' => [], 'mark' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => ['start', 'type'], 'li' => [],
        'blockquote' => ['cite'], 'pre' => [], 'code' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'figure' => [], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'caption' => [], 'colgroup' => ['span'], 'col' => ['span'],
        'span' => [], 'div' => [],
        'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder'],
    ];

    /** Elementi da eliminare insieme al loro contenuto. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'noscript', 'template', 'form', 'object', 'embed', 'applet'];

    /** Host da cui è ammesso un iframe (video incorporati). */
    private const IFRAME_HOSTS = [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'player.vimeo.com', 'vimeo.com',
    ];

    /** Schemi ammessi nei collegamenti. */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(?string $html): ?string
    {
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
        self::cleanElement($root);

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

    private static function cleanElement(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (!array_key_exists($tag, self::ALLOWED)) {
                // Tag sconosciuto: si tiene il contenuto e si butta l'involucro.
                self::cleanElement($child);
                self::unwrap($child);

                continue;
            }

            self::cleanAttributes($child, $tag);

            if ($tag === 'iframe' && !self::isAllowedIframe($child)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            self::cleanElement($child);
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

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
        }

        // Un link che si apre in una nuova scheda senza rel="noopener" lascia
        // alla pagina di destinazione un riferimento alla nostra finestra.
        if ($tag === 'a' && $element->getAttribute('target') !== '') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
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
