<?php

declare(strict_types=1);

/**
 * Test dell'indirizzo del report di uno studente aperto da un corso o da un
 * gruppo (17/11): `App\Core\ContestoStudente`.
 *
 * Esecuzione:  php tests/contesto_studente_test.php
 *
 * Niente server e niente database. La pagina vera, con i permessi, si prova
 * in `tests/contesto_studente.js`.
 *
 * COSA VERIFICA:
 *
 *   1. **Andata e ritorno**: il collegamento di ogni pagina di partenza,
 *      letto dalla pagina dello studente, riporta a quella pagina, con i
 *      suoi parametri.
 *   2. **Il ritorno non porta fuori**: il percorso lo scrive il server; da
 *      `torna` passano solo coppie nome=valore semplici.
 *   3. **Un contesto storto non c'e'**: id non numerico, zero, negativo,
 *      array; un `da` sconosciuto torna alla pagina principale.
 *   4. **I file scaricati** portano solo il corso o il gruppo.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\ContestoStudente;

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

/** Il collegamento come lo legge la pagina dello studente. */
function letto(string $link): ?array
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $get);

    return ContestoStudente::daRichiesta($get);
}

echo "1. Andata e ritorno\n";

$partenze = [
    ['corso', 7, [], '/reports/courses/7'],
    ['griglia', 7, ['gruppo' => '3', 'ordina' => 'studente', 'verso' => 'disc'], '/reports/courses/7/griglia?gruppo=3&ordina=studente&verso=disc'],
    ['fermi', 7, ['giorni' => '30'], '/reports/fermi?giorni=30'],
    ['gestione', 7, [], '/admin/courses/7/edit'],
    ['gruppo', 4, ['ordina' => 'nome'], '/reports/groups/4?ordina=nome'],
    ['scheda-gruppo', 4, [], '/admin/groups/4/edit'],
];

foreach ($partenze as [$da, $id, $parametri, $atteso]) {
    $link = ContestoStudente::link(5, $da, $id, $parametri);
    $c = letto($link);
    $indietro = $c === null ? null : ContestoStudente::indietro($c);
    check("da «{$da}» si torna a {$atteso}", $indietro === $atteso, $link . ' → ' . var_export($indietro, true));
}

$c = letto(ContestoStudente::link(5, 'corso', 7));
check('dal corso il contesto e\' il corso', $c !== null && $c['tipo'] === 'corso' && $c['id'] === 7);
$c = letto(ContestoStudente::link(5, 'scheda-gruppo', 4));
check('dalla gestione del gruppo il contesto e\' il gruppo', $c !== null && $c['tipo'] === 'gruppo' && $c['id'] === 4);
check('il collegamento resta sulla pagina dello studente', str_starts_with(ContestoStudente::link(5, 'corso', 7), '/reports/students/5?'));

echo "\n2. Il ritorno non porta fuori\n";

$storti = [
    'un indirizzo intero' => 'https://altrove.example/x',
    'due barre' => '//altrove.example',
    'un nome con la barra' => 'a/b=1',
    'un elenco' => 'x[]=1&x[]=2',
];

foreach ($storti as $nome => $torna) {
    $c = ContestoStudente::daRichiesta(['corso' => '7', 'da' => 'corso', 'torna' => $torna]);
    $indietro = ContestoStudente::indietro($c);
    check("con {$nome} si torna comunque al corso", str_starts_with($indietro, '/reports/courses/7') && !str_contains($indietro, 'altrove') && !str_contains($indietro, '//'), $indietro);
}

$c = ContestoStudente::daRichiesta(['corso' => '7', 'da' => 'corso', 'torna' => str_repeat('a=1&', 600)]);
check('un `torna` troppo lungo si lascia', ContestoStudente::indietro($c) === '/reports/courses/7');
$c = ContestoStudente::daRichiesta(['corso' => '7', 'da' => 'corso', 'torna' => ['giorni' => '30']]);
check('un `torna` che non e\' un testo si lascia', ContestoStudente::indietro($c) === '/reports/courses/7');
$link = ContestoStudente::link(5, 'fermi', 7, ['giorni' => '30', 'x' => ['a'], 'Strano!' => '1']);
check('dalla pagina di partenza passano solo i parametri semplici', ContestoStudente::indietro(letto($link)) === '/reports/fermi?giorni=30', $link);

echo "\n3. Un contesto storto non c'e'\n";

foreach (['abc', '0', '-3', '7.5', '', ' 7'] as $id) {
    check("corso «{$id}»: nessun contesto", ContestoStudente::daRichiesta(['corso' => $id, 'da' => 'corso']) === null);
}

check('corso come elenco: nessun contesto', ContestoStudente::daRichiesta(['corso' => ['7']]) === null);
check('niente: nessun contesto', ContestoStudente::daRichiesta([]) === null);
$c = ContestoStudente::daRichiesta(['corso' => '7', 'da' => 'boh']);
check('un `da` sconosciuto torna al corso', ContestoStudente::indietro($c) === '/reports/courses/7');
$c = ContestoStudente::daRichiesta(['gruppo' => '4', 'da' => 'griglia']);
check('un `da` del corso con un gruppo torna al gruppo', ContestoStudente::indietro($c) === '/reports/groups/4');

echo "\n4. I file scaricati\n";

$c = letto(ContestoStudente::link(5, 'griglia', 7, ['gruppo' => '3']));
check('dal corso, solo il corso', ContestoStudente::perScaricare($c) === '?corso=7', ContestoStudente::perScaricare($c));
$c = letto(ContestoStudente::link(5, 'scheda-gruppo', 4));
check('dal gruppo, solo il gruppo', ContestoStudente::perScaricare($c) === '?gruppo=4');
check('senza contesto, niente', ContestoStudente::perScaricare(null) === '');

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
