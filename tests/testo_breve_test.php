<?php

declare(strict_types=1);

/**
 * Test dei testi brevi con qualche tag HTML: l'obiettivo e la descrizione del
 * corso, l'obiettivo del modulo (11/10). Senza database.
 *
 *   - quali tag restano e quali si tolgono, e che il codice non passi;
 *   - gli a capo: Invio va a capo, una riga vuota apre un paragrafo, e vicino
 *     a paragrafi ed elenchi non contano; i testi scritti prima, senza tag,
 *     si vedono come prima;
 *   - il conto dei caratteri visibili, lo stesso del contatore nel browser;
 *   - la frase del rifiuto oltre il limite;
 *   - il testo semplice degli estratti.
 *
 * Esecuzione:  php tests/testo_breve_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\HtmlSanitizer;
use App\Core\TestoBreve;
use App\Models\CourseModel;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $dettaglio = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condition && $dettaglio !== '') {
        echo '         · ' . $dettaglio . PHP_EOL;
    }
}

function uguale(string $label, string $atteso, string $ottenuto): void
{
    check($label, $atteso === $ottenuto, 'atteso ' . json_encode($atteso, JSON_UNESCAPED_UNICODE)
        . ', ottenuto ' . json_encode($ottenuto, JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------
echo 'Gli a capo' . PHP_EOL;

uguale('un testo senza tag: Invio va a capo, una riga vuota apre un paragrafo',
    '<p>Riga uno<br>riga due</p><p>Secondo paragrafo</p>',
    TestoBreve::html("Riga uno\nriga due\n\nSecondo paragrafo"));
uguale('gli a capo arrivati dal browser (\r\n) valgono come gli altri',
    '<p>Uno<br>due</p><p>Tre</p>',
    TestoBreve::html("Uno\r\ndue\r\n\r\nTre"));
uguale('piu\' righe vuote di fila aprono un paragrafo solo',
    '<p>Uno</p><p>Due</p>',
    TestoBreve::html("Uno\n\n\n\nDue"));
uguale('un elenco scritto su piu\' righe non si riempie di righe vuote',
    '<p>Intro:</p><ul><li>uno</li><li>due</li></ul><p>Dopo</p>',
    TestoBreve::html("Intro:\n<ul>\n  <li>uno</li>\n  <li>due</li>\n</ul>\nDopo"));
uguale('i paragrafi scritti a mano restano, gli a capo fra l\'uno e l\'altro non contano',
    '<p>Già<br>scritto</p><p>a mano</p>',
    TestoBreve::html("<p>Già\nscritto</p>\n<p>a mano</p>"));
uguale('un elenco dentro un elemento di elenco',
    '<ol><li>a<ul><li>b</li></ul></li></ol>',
    TestoBreve::html("<ol><li>a\n<ul><li>b</li></ul></li></ol>"));
uguale('il grassetto in mezzo al testo resta nel paragrafo',
    '<p>Un <strong>obiettivo</strong> chiaro<br>e misurabile</p>',
    TestoBreve::html("Un <strong>obiettivo</strong> chiaro\ne misurabile"));
uguale('un testo vuoto non scrive niente', '', TestoBreve::html("  \n "));
uguale('null non scrive niente', '', TestoBreve::html(null));

// ---------------------------------------------------------------
echo PHP_EOL . 'I tag' . PHP_EOL;

$tutti = TestoBreve::html('<p><strong>g</strong> <b>b</b> <em>c</em> <i>i</i> <u>s</u> <a href="https://movimente.it">l</a></p><ul><li>x</li></ul><ol><li>y</li></ol>');
foreach (['<strong>', '<b>', '<em>', '<i>', '<u>', '<a href="https://movimente.it">', '<ul>', '<ol>', '<li>'] as $tag) {
    check('resta ' . $tag, str_contains($tutti, $tag), $tutti);
}

$fuori = TestoBreve::html('<h2>Titolo</h2><table><tr><td>cella</td></tr></table><img src="https://x.it/a.png"><iframe src="https://www.youtube.com/embed/x"></iframe><div>blocco</div>');
foreach (['<h2', '<table', '<td', '<img', '<iframe', '<div'] as $tag) {
    check('si toglie ' . $tag, !str_contains($fuori, $tag), $fuori);
}
check('di un tag tolto resta il testo', str_contains($fuori, 'Titolo') && str_contains($fuori, 'cella') && str_contains($fuori, 'blocco'), $fuori);

$codice = TestoBreve::html('Ciao<script>alert(1)</script> <a href="javascript:alert(2)" onclick="x()">link</a> <strong onmouseover="y()">forte</strong> <style>p{}</style>');
check('lo script sparisce con il suo contenuto', !str_contains($codice, 'script') && !str_contains($codice, 'alert(1)'), $codice);
check('un collegamento javascript: perde l\'indirizzo', !str_contains($codice, 'javascript'), $codice);
check('i gestori di eventi spariscono', !str_contains($codice, 'onclick') && !str_contains($codice, 'onmouseover'), $codice);
check('lo stile sparisce', !str_contains($codice, '<style'), $codice);

$nuovaScheda = TestoBreve::html('<a href="https://x.it" target="_blank">x</a>');
check('un collegamento in una nuova scheda riceve noopener', str_contains($nuovaScheda, 'rel="noopener noreferrer"'), $nuovaScheda);

uguale('«<» e «&» scritti come testo restano testo', '<p>a &lt; b e 3 &gt; 2 &amp; c</p>', TestoBreve::html('a < b e 3 > 2 & c'));

check('il ripulitore delle lezioni, senza elenco, tiene i suoi tag',
    str_contains((string) HtmlSanitizer::clean('<h2>T</h2><table><tr><td>c</td></tr></table>'), '<h2>')
    && str_contains((string) HtmlSanitizer::clean('<h2>T</h2><table><tr><td>c</td></tr></table>'), '<table>'));

// ---------------------------------------------------------------
echo PHP_EOL . 'I caratteri visibili' . PHP_EOL;

check('i tag non contano', TestoBreve::caratteri('<strong>ab</strong>') === 2);
check('un a capo conta uno, anche se arriva come \r\n', TestoBreve::caratteri("a\r\nb") === 3);
check('un\'entita\' conta come il carattere che e\'', TestoBreve::caratteri('a &amp; b') === 5);
check('«a < b» e\' testo, non un tag', TestoBreve::caratteri('a < b e 3 > 2') === 13);
check('una lettera accentata conta uno', TestoBreve::caratteri('perché') === 6);
check('un collegamento conta solo la parola', TestoBreve::caratteri('<a href="https://www.movimente.it/una/pagina/lunga">qui</a>') === 3);

$pieno = str_repeat(str_repeat('a', 99) . "\n", 9) . '<strong>' . str_repeat('b', 95) . 'FINE.</strong>';
check('1.000 caratteri visibili con 9 a capo e un grassetto: dentro il limite',
    TestoBreve::caratteri($pieno) === 1000 && TestoBreve::eccesso($pieno, 1000) === 0,
    'contati ' . TestoBreve::caratteri($pieno));
check('e il testo salvato e\' piu\' lungo di 1.000', mb_strlen($pieno) > 1000);

// ---------------------------------------------------------------
echo PHP_EOL . 'Oltre il limite' . PHP_EOL;

check('nessuna frase dentro il limite', TestoBreve::problema("L'obiettivo del corso", $pieno, 1000) === null);
uguale('la frase dice di quanto', "L'obiettivo del corso supera di 37 caratteri il limite di 1.000.",
    (string) TestoBreve::problema("L'obiettivo del corso", str_repeat('x', 1037), 1000));
uguale('al singolare', 'La descrizione supera di 1 carattere il limite di 3.000.',
    (string) TestoBreve::problema('La descrizione', str_repeat('x', 3001), 3000));
uguale('le migliaia con il punto', "L'obiettivo del modulo supera di 1.200 caratteri il limite di 1.000.",
    (string) TestoBreve::problema("L'obiettivo del modulo", str_repeat('x', 2200), 1000));
$pesante = str_repeat('<strong></strong>', 4000) . 'x';
check('un testo che non sta nella colonna si rifiuta anche dentro il limite visibile',
    TestoBreve::caratteri($pesante) === 1 && str_contains((string) TestoBreve::problema('La descrizione', $pesante, 3000), 'troppo lungo'));

uguale('il contatore: rimasti', '998 caratteri rimasti', TestoBreve::restano(998));
uguale('il contatore: uno rimasto', '1 carattere rimasto', TestoBreve::restano(1));
uguale('il contatore: in più', '37 caratteri in più', TestoBreve::restano(-37));
uguale('il contatore: uno in più', '1 carattere in più', TestoBreve::restano(-1));

// ---------------------------------------------------------------
echo PHP_EOL . 'Il campo' . PHP_EOL;

$campo = TestoBreve::campo('objective', 'Obiettivo del corso', 'objective', 1000, '<em>ab</em>', 3);
check('niente maxlength, che conterebbe i tag', !str_contains($campo, 'maxlength'), $campo);
check('il contatore conta il testo visibile', str_contains($campo, '998 caratteri rimasti'), $campo);
check('il campo dice al contatore di contare il testo', str_contains($campo, 'data-conta="testo"'));
check('il contatore e\' la prima descrizione, l\'aiuto la seconda', str_contains($campo, 'aria-describedby="objective-resta objective-aiuto"'));
check('il testo scritto torna com\'e\', i tag visibili nel campo', str_contains($campo, '&lt;em&gt;ab&lt;/em&gt;</textarea>'), $campo);
$oltre = TestoBreve::campo('objective', 'Obiettivo del corso', 'objective', 1000, str_repeat('x', 1037), 3);
check('riproposto oltre il limite, il contatore dice quanti in più, in evidenza',
    str_contains($oltre, '37 caratteri in più') && str_contains($oltre, 'quiz-open-count-basso'), $oltre);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il testo semplice e gli estratti' . PHP_EOL;

uguale('niente tag, a capo e paragrafi diventano spazi', 'Intro: uno due forte Dopo &',
    TestoBreve::testo("Intro:\n<ul><li>uno</li><li>due <strong>forte</strong></li></ul>\n\nDopo &amp;"));
$lunga = '<p><strong>' . str_repeat('parola ', 60) . '</strong></p>';
$estratto = CourseModel::estratto($lunga);
check('l\'estratto non contiene tag', !str_contains($estratto, '<') && str_ends_with($estratto, '…'), $estratto);
check('una descrizione corta con molti tag non viene tagliata', !CourseModel::estrattoTaglia('<p><strong><em>' . str_repeat('x', 50) . '</em></strong></p>'));
check('una descrizione lunga si', CourseModel::estrattoTaglia($lunga));

// ---------------------------------------------------------------
echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
