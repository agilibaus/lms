<?php

declare(strict_types=1);

/**
 * Ogni sottolineatura di Pistacchio e' spessa 1 px (24/11, Alessandro).
 *
 * Una regola che sottolinea senza dire lo spessore lo lascia al carattere
 * (`auto`): cambia con il carattere scelto, la misura del testo e lo
 * schermo. Cosi' i nomi nel cerchio del gruppo, a 16 px, avevano al
 * passaggio del mouse una linea piu' spessa dei collegamenti nelle frasi,
 * fermi a 1 px dalla 0414.
 *
 * Si legge `public/assets/css/style.css`: ogni blocco che mette
 * `text-decoration: underline` (o `text-decoration-line: underline`) deve
 * dire anche `text-decoration-thickness: 1px`. Una regola nuova che se ne
 * dimentica fa fallire il test.
 *
 * Esecuzione:  php tests/sottolineature_test.php
 */

$ok = 0;
$fail = 0;

function check(string $etichetta, bool $condizione, string $dettagli = ''): void
{
    global $ok, $fail;
    $condizione ? $ok++ : $fail++;
    echo ($condizione ? '  OK   ' : '  FAIL ') . $etichetta . PHP_EOL;
    if (!$condizione && $dettagli !== '') {
        echo '         · ' . str_replace(PHP_EOL, PHP_EOL . '         · ', $dettagli) . PHP_EOL;
    }
}

$css = (string) file_get_contents(__DIR__ . '/../public/assets/css/style.css');
$css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

// I blocchi piu' interni: `selettore { dichiarazioni }`, anche dentro @media.
preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $blocchi, PREG_SET_ORDER);

$sottolineano = 0;
$senza = [];

foreach ($blocchi as [, $selettore, $corpo]) {
    if (preg_match('~text-decoration(-line)?\s*:\s*underline~', $corpo) !== 1) {
        continue;
    }

    $sottolineano++;

    if (preg_match('~text-decoration-thickness\s*:\s*1px\s*(;|$)~', $corpo) !== 1) {
        $senza[] = mb_substr((string) preg_replace('~\s+~', ' ', trim($selettore)), 0, 120);
    }
}

check('il foglio di stile ha regole che sottolineano (' . $sottolineano . ')', $sottolineano >= 10);
check('ognuna dice lo spessore: 1 px', $senza === [], implode(PHP_EOL, $senza));

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
