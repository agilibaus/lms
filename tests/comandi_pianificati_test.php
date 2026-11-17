<?php

declare(strict_types=1);

/**
 * Test degli stati dei comandi pianificati (17/11): `App\Core\ComandiPianificati`.
 *
 * Esecuzione:  php tests/comandi_pianificati_test.php
 *
 * Niente server e niente database: la data di adesso la passa il test. La
 * pagina e il numero nel menu si provano in `tests/comandi_pianificati.js`.
 *
 * COSA VERIFICA:
 *
 *   1. **Le soglie decise**: avvisi e inviti fermi oltre 30 minuti, il
 *      rilascio dei moduli oltre 2 giorni; un minuto prima, regolari.
 *   2. **«Mai partito»** senza data, con una data vuota o illeggibile; una
 *      data nel futuro conta come appena eseguito.
 *   3. **Il conto per il menu** somma fermi e mai partiti, e nient'altro.
 *   4. **«Quanto tempo fa»**, singolare e plurale.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\ComandiPianificati as C;

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

$adesso = strtotime('2026-11-17 15:00:00');
$fa = static fn (int $secondi): string => date('Y-m-d H:i:s', $adesso - $secondi);
$soglia = [];

foreach (C::COMANDI as $c) {
    $soglia[$c['chiave']] = $c['soglia'];
}

echo "1. Le soglie\n";

foreach (['AVVISI_LAST_RUN_AT' => 'avvisi', 'INVITES_LAST_RUN_AT' => 'inviti'] as $chiave => $nome) {
    check("{$nome}: 29 minuti fa, regolare", C::stato($fa(29 * 60), $soglia[$chiave], $adesso) === C::REGOLARE);
    check("{$nome}: 31 minuti fa, fermo", C::stato($fa(31 * 60), $soglia[$chiave], $adesso) === C::FERMO);
}

check('rilascio: 47 ore fa, regolare', C::stato($fa(47 * 3600), $soglia['DRIP_LAST_RUN_AT'], $adesso) === C::REGOLARE);
check('rilascio: 49 ore fa, fermo', C::stato($fa(49 * 3600), $soglia['DRIP_LAST_RUN_AT'], $adesso) === C::FERMO);

echo "\n2. Mai partito\n";

foreach ([null, '', '   ', 'boh'] as $v) {
    check('«' . var_export($v, true) . '»: mai partito', C::stato($v, 1800, $adesso) === C::MAI);
}

check('una data nel futuro: regolare', C::stato(date('Y-m-d H:i:s', $adesso + 600), 1800, $adesso) === C::REGOLARE);

echo "\n3. Il conto per il menu\n";

$valori = [
    'AVVISI_LAST_RUN_AT' => $fa(60),
    'INVITES_LAST_RUN_AT' => $fa(3600),
    'DRIP_LAST_RUN_AT' => null,
];
$leggi = static fn (string $k): ?string => $valori[$k] ?? null;
check('uno regolare, uno fermo, uno mai partito: 2', C::daControllare($leggi, $adesso) === 2);
$elenco = C::elenco($leggi, $adesso);
check('l\'elenco dice i tre stati, nell\'ordine', array_column($elenco, 'stato') === [C::REGOLARE, C::FERMO, C::MAI]);
check('tutti regolari: 0', C::daControllare(static fn (string $k): ?string => $fa(60), $adesso) === 0);

echo "\n4. Quanto tempo fa\n";

foreach ([[20, 'adesso'], [60, '1 minuto fa'], [45 * 60, '45 minuti fa'], [3600, '1 ora fa'], [5 * 3600, '5 ore fa'],
          [86400, '1 giorno fa'], [4 * 86400 + 3600, '4 giorni fa']] as [$s, $atteso]) {
    $detto = C::quantoFa($fa($s), $adesso);
    check("{$s} secondi: «{$atteso}»", $detto === $atteso, $detto);
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
