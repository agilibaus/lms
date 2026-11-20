<?php

declare(strict_types=1);

/**
 * Il filtro della pagina Utenti per gruppo e tutor (20/11), senza database:
 * `App\Core\FiltroUtenti` sulle righe come le restituisce `UserModel`.
 *
 * Esecuzione:  php tests/filtro_utenti_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\FiltroUtenti;

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

// Le righe come le scrive `UserModel::all()`: gruppo e tutor separati da
// \x1F, le coppie da \x1E; un gruppo senza tutor ha il tutor vuoto.
$utenti = [
    ['id' => 1, 'role' => 'admin', 'gruppi_tutor' => null, 'tutor_riferimento' => null],
    ['id' => 2, 'role' => 'studente', 'gruppi_tutor' => "Giallo\x1FStefania Bianchi", 'tutor_riferimento' => 'Stefania Bianchi'],
    ['id' => 3, 'role' => 'studente', 'gruppi_tutor' => "Giallo\x1FStefania Bianchi\x1EVerde\x1F", 'tutor_riferimento' => 'Stefania Bianchi'],
    ['id' => 4, 'role' => 'studente', 'gruppi_tutor' => "Verde\x1F", 'tutor_riferimento' => null],
    ['id' => 5, 'role' => 'assistente', 'gruppi_tutor' => null, 'tutor_riferimento' => 'Luca Neri, Stefania Bianchi'],
    ['id' => 6, 'role' => 'studente', 'gruppi_tutor' => null, 'tutor_riferimento' => null],
    ['id' => 7, 'role' => 'studente', 'gruppi_tutor' => "Rosso\x1FLuca Neri", 'tutor_riferimento' => 'Luca Neri'],
];

$ids = static fn (array $righe): array => array_column($righe, 'id');
$filtra = static fn (array $q): array => $ids(FiltroUtenti::daRichiesta($q)->applica($utenti));

echo 'Le celle, come la tabella le scrive' . PHP_EOL;
check('studente con due gruppi: una riga per gruppo, «—» dove manca il tutor',
    FiltroUtenti::celle($utenti[2]) === ['gruppi' => ['Giallo', 'Verde'], 'tutor' => ['Stefania Bianchi', '—']],
    json_encode(FiltroUtenti::celle($utenti[2]), JSON_UNESCAPED_UNICODE));
check('assistente: nessun gruppo, i tutor che affianca',
    FiltroUtenti::celle($utenti[4]) === ['gruppi' => ['—'], 'tutor' => ['Luca Neri', 'Stefania Bianchi']],
    json_encode(FiltroUtenti::celle($utenti[4]), JSON_UNESCAPED_UNICODE));
check('admin e studente senza gruppo: «—» e «—»',
    FiltroUtenti::celle($utenti[0]) === ['gruppi' => ['—'], 'tutor' => ['—']]
        && FiltroUtenti::celle($utenti[5]) === ['gruppi' => ['—'], 'tutor' => ['—']]);

echo PHP_EOL . 'Il filtro' . PHP_EOL;
check('senza criteri passano tutti', $filtra([]) === [1, 2, 3, 4, 5, 6, 7]);
check('Gruppo: Giallo', $filtra(['gruppo' => 'Giallo']) === [2, 3], json_encode($filtra(['gruppo' => 'Giallo'])));
check('Gruppo: — (il personale e gli studenti senza gruppo)', $filtra(['gruppo' => '—']) === [1, 5, 6], json_encode($filtra(['gruppo' => '—'])));
check('Tutor: Stefania Bianchi (i suoi studenti e l\'assistente che la affianca)',
    $filtra(['tutor' => 'Stefania Bianchi']) === [2, 3, 5], json_encode($filtra(['tutor' => 'Stefania Bianchi'])));
check('Tutor: — (chi ha almeno una riga senza tutor)', $filtra(['tutor' => '—']) === [1, 3, 4, 6], json_encode($filtra(['tutor' => '—'])));
check('Gruppo e Tutor insieme sono in AND, anche su righe diverse',
    $filtra(['gruppo' => 'Verde', 'tutor' => 'Stefania Bianchi']) === [3], json_encode($filtra(['gruppo' => 'Verde', 'tutor' => 'Stefania Bianchi'])));
check('AND senza nessuno che li passa tutti e due: elenco vuoto', $filtra(['gruppo' => 'Rosso', 'tutor' => 'Stefania Bianchi']) === []);
check('un valore vuoto o di spazi non filtra', $filtra(['gruppo' => '', 'tutor' => '  ']) === [1, 2, 3, 4, 5, 6, 7]);

echo PHP_EOL . 'I criteri attivi e le voci dei menu' . PHP_EOL;
check('quanti criteri sono attivi', FiltroUtenti::daRichiesta([])->attivi() === 0
    && FiltroUtenti::daRichiesta(['gruppo' => 'Giallo'])->attivi() === 1
    && FiltroUtenti::daRichiesta(['gruppo' => 'Giallo', 'tutor' => '—'])->attivi() === 2);
$voci = FiltroUtenti::daRichiesta([])->voci($utenti);
check('le voci in ordine alfabetico, con «—» in fondo',
    $voci === ['gruppi' => ['Giallo', 'Rosso', 'Verde', '—'], 'tutor' => ['Luca Neri', 'Stefania Bianchi', '—']],
    json_encode($voci, JSON_UNESCAPED_UNICODE));
$voci = FiltroUtenti::daRichiesta(['gruppo' => 'Blu'])->voci($utenti);
check('un valore scelto che non compare più resta fra le voci', in_array('Blu', $voci['gruppi'], true));
check('un gruppo dal nome numerico resta un nome',
    FiltroUtenti::daRichiesta([])->voci([['gruppi_tutor' => "2026\x1F", 'tutor_riferimento' => null]])['gruppi'] === ['2026']);

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
