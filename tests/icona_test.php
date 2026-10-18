<?php

declare(strict_types=1);

/**
 * Le icone (18/10, Bootstrap Icons), senza database: `App\Core\Icona` e il
 * suo uso nelle viste.
 *
 * Esecuzione:  php tests/icona_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Icona;

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

foreach (Icona::nomi() as $nome) {
    $svg = Icona::svg($nome);
    check($nome . ': un svg nascosto ai lettori di schermo, che non prende il fuoco',
        str_starts_with($svg, '<svg ') && str_contains($svg, 'aria-hidden="true"') && str_contains($svg, 'focusable="false"'), $svg);
    check($nome . ': prende il colore del testo, viewBox 16x16, solo <path>',
        str_contains($svg, 'fill="currentColor"') && str_contains($svg, 'viewBox="0 0 16 16"')
        && preg_match_all('/<(\w+)/', $svg, $m) > 0 && array_diff(array_unique($m[1]), ['svg', 'path']) === [], $svg);
}

check('la classe in piu\' si aggiunge, con l\'escape', str_contains(Icona::svg('play', 'a"b'), 'class="icona icona-play a&quot;b"'));

try {
    Icona::svg('nessuna');
    check('un nome sconosciuto e\' un errore', false);
} catch (InvalidArgumentException) {
    check('un nome sconosciuto e\' un errore', true);
}

// Ogni icona chiamata nelle viste esiste: un nome sbagliato farebbe cadere
// la pagina solo quando la si apre.
$usate = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app/Views'));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        preg_match_all("/Icona::svg\\('([a-z0-9-]+)'/", (string) file_get_contents($f->getPathname()), $m);
        preg_match_all("/Icona::svg\\([^;]*?\\?\\s*'([a-z0-9-]+)'\\s*:\\s*\\(?[^;]*?'([a-z0-9-]+)'\\s*:\\s*'([a-z0-9-]+)'/", (string) file_get_contents($f->getPathname()), $t);
        $usate = array_merge($usate, $m[1], $t[1], $t[2], $t[3]);
    }
}
$usate = array_values(array_unique($usate));
$mancanti = array_diff($usate, Icona::nomi());
check('le viste usano ' . count($usate) . ' icone, e ci sono tutte', $usate !== [] && $mancanti === [], implode(', ', $mancanti));

// Niente piu' «✓» scritte a mano: la spunta e' l'icona (18/10).
$conSpunta = [];
$conFrecce = [];
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $testo = (string) file_get_contents($f->getPathname());
        if (str_contains($testo, '&check;') || str_contains($testo, '✓')) {
            $conSpunta[] = basename($f->getPathname());
        }
        if (preg_match('/&[ud]arr;|[↑↓]/u', $testo) === 1) {
            $conFrecce[] = basename($f->getPathname());
        }
    }
}
check('nessuna vista scrive la spunta a mano', $conSpunta === [], implode(', ', $conSpunta));
check('nessuna vista scrive le frecce di riordino a mano', $conFrecce === [], implode(', ', $conFrecce));

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
