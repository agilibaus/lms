<?php

declare(strict_types=1);

/**
 * Test di «Studenti fermi»: la regola, lo stato, le frasi.
 *
 * Esecuzione:  php tests/studenti_fermi_test.php
 *
 * Niente server e niente database: `App\Core\StudentiFermi` fa i conti su
 * date che gli vengono date, `App\Core\Presenza::daScrivere` decide se e'
 * ora di scrivere. Chi vede la pagina si prova in `tests/permessi.js`.
 *
 * COSA VERIFICA, e perche' proprio questo:
 *
 *   1. **La soglia arriva dall'indirizzo**: vale solo 7, 14 o 30, il resto
 *      torna a 14. Un «?giorni=0» mostrerebbe tutti come fermi.
 *   2. **I giorni sono di calendario**: ieri sera alle 23 e' «ieri», non
 *      «oggi» perche' sono passate meno di 24 ore.
 *   3. **Il bordo della soglia**: fermo da esattamente 14 giorni e' fermo
 *      con la soglia 14; da 13 no.
 *   4. **Lo stato**: mai iniziato, entra ma non avanza, non entra piu'.
 *   5. **La presenza si scrive al piu' ogni cinque minuti.**
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Presenza;
use App\Core\StudentiFermi;

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

$adesso = new DateTimeImmutable('2026-10-20 10:00:00');

echo PHP_EOL . 'La soglia' . PHP_EOL;

check('7, 14 e 30 sono accettati', StudentiFermi::soglia('7') === 7 && StudentiFermi::soglia('14') === 14 && StudentiFermi::soglia(30) === 30);
check('niente soglia vuol dire 14', StudentiFermi::soglia(null) === 14);
check('0 torna a 14', StudentiFermi::soglia('0') === 14, 'con 0 sarebbero fermi tutti');
check('un numero non previsto torna a 14', StudentiFermi::soglia('15') === 14 && StudentiFermi::soglia('-7') === 14);
check('il testo torna a 14', StudentiFermi::soglia('tanti') === 14);

echo PHP_EOL . 'I giorni' . PHP_EOL;

check('stamattina e oggi', StudentiFermi::giorni('2026-10-20 08:00:00', $adesso) === 0);
check('ieri sera alle 23 e ieri', StudentiFermi::giorni('2026-10-19 23:00:00', $adesso) === 1, 'sono meno di 24 ore, ma e un altro giorno');
check('il futuro conta zero', StudentiFermi::giorni('2026-10-25 08:00:00', $adesso) === 0);
check('trenta giorni', StudentiFermi::giorni('2026-09-20 12:00:00', $adesso) === 30);

echo PHP_EOL . 'Fermo o no' . PHP_EOL;

$v = StudentiFermi::valuta('2026-10-06 09:00:00', '2026-09-01 00:00:00', null, 14, $adesso);
check('da esattamente 14 giorni e fermo con soglia 14', $v['fermo'] && $v['giorni'] === 14);

$v = StudentiFermi::valuta('2026-10-07 09:00:00', '2026-09-01 00:00:00', null, 14, $adesso);
check('da 13 giorni non e fermo con soglia 14', !$v['fermo'] && $v['giorni'] === 13);

$v = StudentiFermi::valuta('2026-10-07 09:00:00', '2026-09-01 00:00:00', null, 7, $adesso);
check('da 13 giorni e fermo con soglia 7', $v['fermo']);

$v = StudentiFermi::valuta(null, '2026-10-15 00:00:00', null, 14, $adesso);
check('iscritto da 5 giorni e mai iniziato non e ancora fermo', !$v['fermo'] && $v['giorni'] === 5, 'si conta dall iscrizione');

$v = StudentiFermi::valuta(null, '2026-09-01 00:00:00', '2026-10-19 10:00:00', 14, $adesso);
check('mai iniziato e iscritto da tempo e fermo', $v['fermo'] && $v['stato'] === StudentiFermi::MAI, 'anche se entra: non ha mai fatto niente');

echo PHP_EOL . 'Lo stato' . PHP_EOL;

$v = StudentiFermi::valuta('2026-09-01 09:00:00', '2026-08-01 00:00:00', '2026-10-18 10:00:00', 14, $adesso);
check('entrato due giorni fa: entra ma non avanza', $v['stato'] === StudentiFermi::ENTRA);

$v = StudentiFermi::valuta('2026-09-01 09:00:00', '2026-08-01 00:00:00', '2026-10-06 10:00:00', 14, $adesso);
check('entrato 14 giorni fa con soglia 14: non entra piu', $v['stato'] === StudentiFermi::ESCE);

$v = StudentiFermi::valuta('2026-09-01 09:00:00', '2026-08-01 00:00:00', null, 14, $adesso);
check('visita mai registrata: non entra piu', $v['stato'] === StudentiFermi::ESCE, 'prima della migrazione la visita non c era');

check('le tre etichette', StudentiFermi::etichettaStato(StudentiFermi::MAI) === 'non ha mai iniziato'
    && StudentiFermi::etichettaStato(StudentiFermi::ENTRA) === 'entra ma non avanza'
    && StudentiFermi::etichettaStato(StudentiFermi::ESCE) === 'non entra più');

echo PHP_EOL . 'Le frasi' . PHP_EOL;

check('oggi, ieri, da N giorni', StudentiFermi::quando(0) === 'oggi' && StudentiFermi::quando(1) === 'ieri' && StudentiFermi::quando(12) === 'da 12 giorni');
check('la lezione ha il titolo', StudentiFermi::descrivi('lezione', 'Il respiro') === 'ha completato la lezione «Il respiro»');
check('l incontro ha il titolo', StudentiFermi::descrivi('incontro', 'Tappa 1') === 'era all\'incontro «Tappa 1»');
check('la domanda non ha titolo', StudentiFermi::descrivi('domanda', 'qualunque') === 'ha fatto una domanda all\'esperto');
foreach (['lezione', 'video', 'questionario', 'incontro', 'domanda', 'risorsa', 'racconto'] as $tipo) {
    check('il tipo «' . $tipo . '» ha una frase', StudentiFermi::descrivi($tipo, 'X') !== 'X');
}

echo PHP_EOL . 'La presenza' . PHP_EOL;

check('la prima volta si scrive', Presenza::daScrivere(null, 1000));
check('dopo un minuto no', !Presenza::daScrivere(1000, 1060));
check('dopo cinque minuti si', Presenza::daScrivere(1000, 1300));

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
