<?php

declare(strict_types=1);

/**
 * Test del sanificatore HTML: verifica che l'editor delle lezioni non possa
 * salvare codice eseguibile nel browser degli studenti.
 *
 * Esecuzione:  php tests/html_sanitizer_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\HtmlSanitizer;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

/** Comodità: sanifica e restituisce stringa (mai null) per i confronti. */
function clean(?string $html): string
{
    return (string) HtmlSanitizer::clean($html);
}

// ---------------------------------------------------------------
echo "Valori vuoti" . PHP_EOL;

check('null resta null', HtmlSanitizer::clean(null) === null);
check('stringa vuota diventa null', HtmlSanitizer::clean('   ') === null);
check('solo tag vietati diventano null', HtmlSanitizer::clean('<script>alert(1)</script>') === null);

// ---------------------------------------------------------------
echo PHP_EOL . "Contenuto legittimo" . PHP_EOL;

$rich = '<h2>Titolo</h2><p>Testo <strong>in grassetto</strong> e <em>corsivo</em>.</p>'
    . '<ul><li>Primo</li><li>Secondo</li></ul>';
check('formattazione di base preservata', clean($rich) === $rich);

$table = '<table><thead><tr><th scope="col">A</th></tr></thead><tbody><tr><td colspan="2">B</td></tr></tbody></table>';
check('tabella con colspan e scope preservata', clean($table) === $table);

check(
    'accenti non diventano entità',
    clean('<p>Città perché è così</p>') === '<p>Città perché è così</p>'
);

// ---------------------------------------------------------------
echo PHP_EOL . "Codice eseguibile" . PHP_EOL;

$result = clean('<p>Prima</p><script>alert(1)</script><p>Dopo</p>');
check('script rimosso col suo contenuto', !str_contains($result, 'alert') && !str_contains($result, 'script'));
check('il testo intorno allo script resta', str_contains($result, 'Prima') && str_contains($result, 'Dopo'));

$result = clean('<img src="https://esempio.it/a.png" onerror="alert(1)" alt="a">');
check('onerror rimosso', !str_contains($result, 'onerror'));
check('immagine e alt conservati', str_contains($result, 'https://esempio.it/a.png') && str_contains($result, 'alt="a"'));

check('onclick rimosso', !str_contains(clean('<p onclick="alert(1)">x</p>'), 'onclick'));
check('style inline rimosso', !str_contains(clean('<p style="position:fixed">x</p>'), 'style'));
check('blocco <style> rimosso', !str_contains(clean('<style>body{display:none}</style><p>x</p>'), 'display'));
check('form rimosso col suo contenuto', !str_contains(clean('<form action="/x"><input name="p"></form><p>y</p>'), 'input'));
check('commento rimosso', !str_contains(clean('<p>x</p><!-- segreto -->'), 'segreto'));

// ---------------------------------------------------------------
echo PHP_EOL . "URL" . PHP_EOL;

check('javascript: rimosso da href', !str_contains(clean('<a href="javascript:alert(1)">x</a>'), 'javascript'));
check('data: rimosso da src', !str_contains(clean('<img src="data:text/html;base64,PHNjcmlwdD4=" alt="">'), 'data:'));
check('http conservato', str_contains(clean('<a href="http://esempio.it">x</a>'), 'http://esempio.it'));
check('mailto conservato', str_contains(clean('<a href="mailto:info@movimente.it">x</a>'), 'mailto:'));
check('percorso interno conservato', str_contains(clean('<a href="/courses/3">x</a>'), '/courses/3'));
check('ancora conservata', str_contains(clean('<a href="#nota">x</a>'), '#nota'));
check('mailto non ammesso in src', !str_contains(clean('<img src="mailto:x@y.it" alt="">'), 'mailto'));

$result = clean('<a href="https://esempio.it" target="_blank">x</a>');
check('target impone rel noopener', str_contains($result, 'rel="noopener noreferrer"'));
check(
    'senza target nessun rel aggiunto',
    !str_contains(clean('<a href="https://esempio.it">x</a>'), 'rel=')
);

// ---------------------------------------------------------------
echo PHP_EOL . "Iframe" . PHP_EOL;

$youtube = '<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315" allowfullscreen=""></iframe>';
check('YouTube ammesso', str_contains(clean($youtube), 'youtube.com/embed/abc123'));
check(
    'Vimeo ammesso',
    str_contains(clean('<iframe src="https://player.vimeo.com/video/1"></iframe>'), 'player.vimeo.com')
);
check(
    'host estraneo rifiutato',
    clean('<iframe src="https://evil.example/x"></iframe>') === ''
);
check(
    'iframe senza src rifiutato',
    clean('<iframe></iframe>') === ''
);
check(
    'host camuffato rifiutato',
    clean('<iframe src="https://www.youtube.com.evil.example/x"></iframe>') === ''
);

// ---------------------------------------------------------------
echo PHP_EOL . "Tag sconosciuti" . PHP_EOL;

check(
    'tag sconosciuto srotolato, testo conservato',
    clean('<marquee><p>Testo</p></marquee>') === '<p>Testo</p>'
);
check(
    'tag sconosciuto annidato srotolato',
    clean('<custom-el><other><p>A</p></other></custom-el>') === '<p>A</p>'
);
check(
    'script dentro un tag sconosciuto comunque rimosso',
    !str_contains(clean('<marquee><script>alert(1)</script><p>A</p></marquee>'), 'alert')
);

// ---------------------------------------------------------------
echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
