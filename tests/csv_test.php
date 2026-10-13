<?php

declare(strict_types=1);

/**
 * Test delle celle del CSV (13/10, verifica di sicurezza): un testo che
 * Excel leggerebbe come formula esce con l'apostrofo davanti; numeri,
 * telefoni e testi normali escono come sono.
 *
 * Esecuzione:  php tests/csv_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Csv;

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

echo 'Formule neutralizzate' . PHP_EOL;

foreach ([
    '=HYPERLINK("http://esempio.invalid";"clic")',
    '+SUM(A1:A9)',
    '-2+3+cmd|\' /C calc\'!A0',
    '@SOMMA(A1)',
    "\t=1+1",
    "\r=1+1",
] as $formula) {
    check('«' . addcslashes($formula, "\t\r") . '» esce con l\'apostrofo', Csv::cella($formula) === "'" . $formula,
        var_export(Csv::cella($formula), true));
}

echo PHP_EOL . 'Il resto come prima' . PHP_EOL;

foreach (['Marta Cabrini', 'elena@tiscali.it', '-3', '12,5', '75%', '+39 333 1234567', '035-123456', '(035) 12 34 56',
    '13/10/2026 09:30', '', 'Anna-Maria'] as $testo) {
    check('«' . $testo . '» resta com\'è', Csv::cella($testo) === $testo, var_export(Csv::cella($testo), true));
}

check('i numeri restano numeri', Csv::cella(-3) === -3 && Csv::cella(12.5) === 12.5);
check('i vuoti restano vuoti', Csv::cella(null) === null);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
