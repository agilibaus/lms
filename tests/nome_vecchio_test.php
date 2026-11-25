<?php

declare(strict_types=1);

/**
 * Il nome vecchio del progetto (25/11, Alessandro): «Vivere Mindfulness
 * LMS» risaliva all'inizio dello sviluppo ed era rimasto in cima a
 * `style.css` e come nome del pacchetto in `composer.json`. Il progetto si
 * chiama Pistacchio LMS: nessun file del repository lo nomina piu'. E
 * nemmeno «Mindfulness in Voce», un altro progetto, che un commento di
 * `style.css` dava come origine dello sfondo delle pagine di accesso.
 *
 * Esecuzione:  php tests/nome_vecchio_test.php
 */

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, array $dettagli = []): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condition) {
        foreach (array_slice($dettagli, 0, 8) as $d) {
            echo '         · ' . $d . PHP_EOL;
        }
    }
}

$radice = dirname(__DIR__);
$file = array_filter(explode("\0", (string) shell_exec('git -C ' . escapeshellarg($radice) . ' ls-files -z')));
$trovati = [];
$altroProgetto = [];

foreach ($file as $f) {
    if ($f === 'tests/nome_vecchio_test.php') {
        continue;
    }

    $testo = @file_get_contents($radice . '/' . $f);

    if ($testo !== false && preg_match('/vivere[\s_-]*mindfulness/i', $testo) === 1) {
        $trovati[] = $f;
    }

    if ($testo !== false && preg_match('/mindfulness[\s_-]*in[\s_-]*voce/i', $testo) === 1) {
        $altroProgetto[] = $f;
    }
}

check('ci sono dei file da guardare', count($file) > 100, [count($file) . ' file']);
check('nessun file nomina «Vivere Mindfulness»', $trovati === [], $trovati);
check('nessun file nomina «Mindfulness in Voce»', $altroProgetto === [], $altroProgetto);
check('il pacchetto si chiama pistacchio/lms',
    (json_decode((string) file_get_contents($radice . '/composer.json'), true)['name'] ?? null) === 'pistacchio/lms');

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
