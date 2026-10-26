<?php

declare(strict_types=1);

/**
 * Test di `App\Auth\Ruoli` (26/10): il nucleo fisso di ogni ruolo e i cinque
 * interruttori, compreso «Tentativi dei questionari» che vale solo con
 * «Report» acceso.
 *
 * Le pagine e le azioni che ogni interruttore apre e chiude le prova
 * `tests/permessi.js`; qui si prova la regola da sola, perche' alcune pagine
 * la ripetono (il tentativo, per esempio, chiede anche i report) e da li'
 * una dipendenza rotta non si vedrebbe.
 *
 * Esecuzione:  php tests/ruoli_test.php
 *
 * ATTENZIONE: scrive nella tabella `settings` del database del .env. Le
 * cinque chiavi si salvano all'inizio e si rimettono com'erano alla fine,
 * anche se un'asserzione fallisce.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Auth\Ruoli;
use App\Core\Settings;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

/** @param array<string, string|null> $valori */
function imposta(array $valori): void
{
    foreach (array_keys(Ruoli::INTERRUTTORI) as $chiave) {
        Settings::set($chiave, $valori[$chiave] ?? null);
    }
}

$prima = [];

foreach (array_keys(Ruoli::INTERRUTTORI) as $chiave) {
    $prima[$chiave] = Settings::stored($chiave);
}

try {
    echo 'Nucleo fisso' . PHP_EOL;
    imposta(array_fill_keys(array_keys(Ruoli::INTERRUTTORI), '0'));

    check('tutto spento: assistente e studente senza permessi',
        Ruoli::permessi('assistente') === [] && Ruoli::permessi('studente') === []);
    check('tutto spento: il tutor ha il nucleo e basta', Ruoli::permessi('tutor') === Ruoli::NUCLEO['tutor']);
    check('il tutor non crea gruppi né assegna corsi', !in_array('group.manage', Ruoli::permessi('tutor'), true));
    check('l\'admin ha sempre tutto il suo nucleo', Ruoli::permessi('admin') === Ruoli::NUCLEO['admin']);
    check('un ruolo che non esiste non ha niente', Ruoli::permessi('ospite') === []);

    echo 'Interruttori' . PHP_EOL;
    imposta([]);
    check('senza righe salvate sono tutti accesi',
        in_array('group.members_own', Ruoli::permessi('tutor'), true)
        && Ruoli::permessi('assistente') === ['report.view_assigned', 'quiz.grade_assigned', 'live.view_assigned']
        && Ruoli::permessi('studente') === ['board.propose']);

    foreach (Ruoli::INTERRUTTORI as $chiave => $i) {
        imposta([$chiave => '0']);
        check($chiave . ' spento toglie ' . $i['permesso'] . ' e solo quello',
            !in_array($i['permesso'], Ruoli::permessi($i['ruolo']), true)
            && ($chiave === 'ROLE_ASSISTENTE_REPORT' || count(Ruoli::permessi($i['ruolo'])) === count(array_filter(
                Ruoli::INTERRUTTORI, static fn (array $x): bool => $x['ruolo'] === $i['ruolo']
            )) + count(Ruoli::NUCLEO[$i['ruolo']]) - 1));
    }

    imposta(['ROLE_ASSISTENTE_REPORT' => '0']);
    check('«Report» spento spegne anche «Tentativi», anche se la sua casella è accesa',
        !Ruoli::acceso('ROLE_ASSISTENTE_TENTATIVI') && Ruoli::permessi('assistente') === ['live.view_assigned']);

    imposta(['ROLE_ASSISTENTE_REPORT' => '1', 'ROLE_STUDENTE_PROPOSTE' => 'sì']);
    check('spento vale solo «0»: ogni altro valore è acceso',
        Ruoli::acceso('ROLE_ASSISTENTE_TENTATIVI') && Ruoli::acceso('ROLE_STUDENTE_PROPOSTE'));
    check('una chiave che non è un interruttore non è accesa', !Ruoli::acceso('ROLE_INVENTATO'));
    check('le cinque chiavi si possono scrivere dal pannello, nessun\'altra ROLE_',
        array_reduce(array_keys(Ruoli::INTERRUTTORI), static fn (bool $c, string $k): bool => $c && Settings::isWritable($k), true)
        && !Settings::isWritable('ROLE_INVENTATO'));
} finally {
    foreach ($prima as $chiave => $valore) {
        Settings::set($chiave, $valore);
    }
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
