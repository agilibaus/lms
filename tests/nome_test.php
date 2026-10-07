<?php

declare(strict_types=1);

/**
 * Test di nome e cognome e di come compare una persona (07/10).
 *
 * Esecuzione:  php tests/nome_test.php
 *
 * Senza database. `PersonName` decide come si divide un nome tutto insieme,
 * come si scrivono le iniziali e che cosa vede chi guarda; `UserImport`
 * legge nome e cognome dai tre formati di file che accetta.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\PersonName;
use App\Core\UserImport;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    if (!$condition && $detail !== '') {
        echo '         · ' . $detail . PHP_EOL;
    }
}

echo PHP_EOL . 'La divisione di un nome tutto insieme' . PHP_EOL;

check('«Mario Rossi»: nome e cognome', PersonName::split('Mario Rossi') === ['Mario', 'Rossi']);
check('«Maria Grazia Rossi»: la prima parola è il nome (il caso che sbaglia, ed è dichiarato)',
    PersonName::split('Maria Grazia Rossi') === ['Maria', 'Grazia Rossi']);
check('spazi in più: ignorati', PersonName::split("  Mario \t Rossi ") === ['Mario', 'Rossi']);
check('una parola sola: cognome vuoto', PersonName::split('Mario') === ['Mario', '']);

echo PHP_EOL . 'Nome e cognome come li scrive una persona' . PHP_EOL;

$pulito = static function (string $n, string $c): string {
    try {
        return implode('|', PersonName::clean($n, $c));
    } catch (\InvalidArgumentException $e) {
        return 'errore: ' . $e->getMessage();
    }
};
check('ripuliti dagli spazi', $pulito(' Maria  Grazia ', ' De   Luca ') === 'Maria Grazia|De Luca');
check('senza nome: errore che lo dice', $pulito('', 'Rossi') === 'errore: Il nome è obbligatorio.');
check('senza cognome: errore che lo dice', $pulito('Mario', ' ') === 'errore: Il cognome è obbligatorio.');
check('troppo lungo: errore', str_starts_with($pulito(str_repeat('a', 101), 'Rossi'), 'errore'));

echo PHP_EOL . 'Le iniziali' . PHP_EOL;

check('«Mario Rossi» → «M. R.»', PersonName::initials('Mario', 'Rossi') === 'M. R.');
check('una per parola: «Maria Grazia De Luca» → «M. G. D. L.»', PersonName::initials('Maria Grazia', 'De Luca') === 'M. G. D. L.');
check('il trattino separa: «Jean-Luc» → «J. L.»', PersonName::initials('Jean-Luc', 'Rossi') === 'J. L. R.');
check('l\'apostrofo no: «D\'Amico» → «D.»', PersonName::initials('Anna', "D'Amico") === 'A. D.');
check('le lettere accentate restano: «Èlia Ònida» → «È. Ò.»', PersonName::initials('èlia', 'ònida') === 'È. Ò.');

echo PHP_EOL . 'Che cosa vede chi guarda' . PHP_EOL;

$mario = ['id' => 5, 'first_name' => 'Mario', 'last_name' => 'Rossi', 'full_name' => 'Mario Rossi'];
$vede = static fn (string $scelta, int $chi, bool $staff): string
    => PersonName::shown($mario + ['name_display' => $scelta], $chi, $staff);

check('scelta «nome e cognome»: un altro studente vede «Mario Rossi»', $vede('full', 9, false) === 'Mario Rossi');
check('scelta «solo il nome»: vede «Mario»', $vede('first', 9, false) === 'Mario');
check('scelta «solo le iniziali»: vede «M. R.»', $vede('initials', 9, false) === 'M. R.');
check('lo staff vede sempre nome e cognome', $vede('initials', 9, true) === 'Mario Rossi');
check('ognuno vede se stesso per intero', $vede('initials', 5, false) === 'Mario Rossi');
check('una scelta sconosciuta vale «nome e cognome», non un nome vuoto', $vede('boh', 9, false) === 'Mario Rossi');
check('senza scelta salvata: nome e cognome (il predefinito)',
    PersonName::shown($mario, 9, false) === 'Mario Rossi');

echo PHP_EOL . 'L\'importazione: nome e cognome in due colonne' . PHP_EOL;

$leggi = static fn (string $csv): array => UserImport::leggi($csv);

$r = $leggi("email;nome;cognome\nmario@example.it;Maria Grazia;Rossi\n")['righe'][0] ?? [];
check('colonne «nome» e «cognome»: prese come sono',
    ($r['first_name'] ?? '') === 'Maria Grazia' && ($r['last_name'] ?? '') === 'Rossi', json_encode($r));

$e = $leggi("email;nome completo\nmario@example.it;Mario Rossi\n");
check('colonna unica «nome completo»: il file si rifiuta (deciso da Elena)',
    $e['righe'] === [] && $e['errore'] !== null, (string) $e['errore']);

$e = $leggi("email;nome\nmario@example.it;Mario Rossi\n");
check('colonna «nome» senza «cognome»: il file si rifiuta', $e['righe'] === [] && $e['errore'] !== null, (string) $e['errore']);

$r = $leggi("email;nome;cognome\nmario@example.it;Mario;\n")['righe'][0] ?? [];
check('cognome vuoto in una riga: quella riga si scarta dicendo perché', ($r['errore'] ?? '') === 'manca il cognome', json_encode($r));

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
