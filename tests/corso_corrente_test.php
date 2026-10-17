<?php

declare(strict_types=1);

/**
 * Il corso in cui ci si trova (17/10, Alessandro, variante C della
 * sidebar), senza database: da quali dati di una pagina si capisce che la
 * pagina e' di un corso. Le voci del ramo e chi le vede le prova
 * `tests/permessi.js`.
 *
 * Esecuzione:  php tests/corso_corrente_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\CorsoCorrente;

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

$casi = [
    [['course' => ['id' => 7, 'title' => 'x']], 7, 'la pagina del corso, Modifica corso, il report del corso'],
    [['module' => ['id' => 3, 'course_id' => 9]], 9, 'una pagina di un modulo: nuova lezione, nuovo quiz'],
    [['module' => ['id' => 3, 'course_id' => 9], 'course' => ['id' => 9]], 9, 'lezione e quiz: modulo e corso insieme'],
    [['corso' => ['id' => 4, 'title' => 'x']], 4, 'la bacheca e «L\'esperto risponde»'],
    [['corso' => null, 'corsi' => []], null, 'la bacheca senza corsi da mostrare'],
    [['course' => ['title' => 'Nuovo'], 'module' => null], null, '«Nuovo corso»: il corso non esiste ancora'],
    [['course' => null], null, 'nessun corso'],
    [['courses' => [['id' => 1], ['id' => 2]]], null, 'un elenco di corsi non è un corso'],
    [['pageTitle' => 'Agenda'], null, 'una pagina di piattaforma'],
    [['course' => ['id' => '12']], 12, 'l\'identificativo letto dal database come testo'],
    [['course' => ['id' => 0]], null, 'un identificativo zero non è un corso'],
];

foreach ($casi as [$dati, $atteso, $cosa]) {
    $avuto = CorsoCorrente::da($dati);
    check($cosa . ' → ' . var_export($atteso, true), $avuto === $atteso, var_export($avuto, true));
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
