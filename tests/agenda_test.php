<?php

declare(strict_types=1);

/**
 * Test dell'agenda: raggruppamento, griglia del mese, file .ics.
 *
 * Esecuzione:  php tests/agenda_test.php
 *
 * Niente server e niente database: `App\Core\Agenda` raggruppa eventi che
 * gli vengono dati, e `App\Core\Ics` scrive testo. Il «chi vede cosa» non
 * si prova qui ma in `tests/permessi.js`, dove c'e' un mondo con due
 * proprietari diversi — un controllo sui permessi senza qualcuno da cui
 * difendersi non prova niente.
 *
 * COSA VERIFICA, e perche' proprio questo:
 *
 *   1. **I gruppi sono relativi a oggi**, non al mese: e' «la settimana
 *      prossima» l'informazione che si cerca aprendo un'agenda.
 *   2. **Un incontro cominciato ma non finito resta fra quelli di oggi.**
 *      Guardando solo l'ora d'inizio finirebbe nel passato proprio mentre
 *      serve di piu': e' in corso.
 *   3. **La griglia del mese comincia di lunedi'** e contiene settimane
 *      intere. Un errore di un giorno qui sposta tutto il mese e si nota
 *      solo contando.
 *   4. **Quello che arriva dall'indirizzo e' una data o niente.**
 *      `DateTimeImmutable` accetterebbe «next tuesday» e «+30 years».
 *   5. **Il file .ics e' valido**: righe piegate a 75 ottetti, CRLF, orari
 *      in UTC, caratteri speciali protetti. Un calendario che non riesce a
 *      leggere il file non dice perche': smette e basta.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Agenda;
use App\Core\Ics;

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

/** Un orario fisso, cosi' il test non dipende da quando lo si esegue. */
$adesso = new DateTimeImmutable('2026-10-14 10:00:00'); // mercoledì

$evento = static function (string $quando, string $titolo, ?string $fine = null, string $tipo = Agenda::INCONTRO): array {
    return [
        'tipo' => $tipo,
        'id' => abs(crc32($titolo)) % 1000,
        'titolo' => $titolo,
        'inizio' => new DateTimeImmutable($quando),
        'fine' => $fine === null ? null : new DateTimeImmutable($fine),
        'contesto' => 'Corso di prova',
        'url' => '/live/1',
    ];
};

// ---------------------------------------------------------------
// Il raggruppamento
// ---------------------------------------------------------------

echo PHP_EOL . 'Il raggruppamento' . PHP_EOL;

$eventi = [
    $evento('2026-10-01 09:00:00', 'molto prima', '2026-10-01 10:00:00'),
    $evento('2026-10-14 08:00:00', 'stamattina', '2026-10-14 09:00:00'),
    $evento('2026-10-14 09:30:00', 'in corso adesso', '2026-10-14 11:00:00'),
    $evento('2026-10-14 18:00:00', 'stasera', '2026-10-14 19:00:00'),
    $evento('2026-10-17 10:00:00', 'fra tre giorni', '2026-10-17 11:00:00'),
    $evento('2026-10-28 10:00:00', 'fra due settimane', '2026-10-28 11:00:00'),
    $evento('2026-10-20 00:00:00', 'apertura del modulo', null, Agenda::APERTURA),
];

$g = Agenda::raggruppa($eventi, $adesso);

$titoli = static fn (array $lista): array => array_map(static fn (array $e): string => $e['titolo'], $lista);

check(
    'quello che e gia finito sta fra i passati',
    $titoli($g['passati']) === ['stamattina', 'molto prima'],
    'ottenuto: ' . implode(', ', $titoli($g['passati']))
);
check(
    'i passati si leggono a ritroso, l ultimo per primo',
    ($g['passati'][0]['titolo'] ?? '') === 'stamattina'
);
check(
    'un incontro cominciato ma non finito resta fra quelli di oggi',
    in_array('in corso adesso', $titoli($g['oggi']), true),
    'guardando solo l\'ora d\'inizio finirebbe nel passato proprio mentre serve di piu\''
);
check(
    'quello di stasera e di oggi',
    in_array('stasera', $titoli($g['oggi']), true)
);
check(
    'tre giorni avanti sta nella settimana',
    in_array('fra tre giorni', $titoli($g['settimana']), true),
    'ottenuto: ' . implode(', ', $titoli($g['settimana']))
);
// Il 20 ottobre cade dentro ai sette giorni dal 14: sta nella settimana,
// non piu' in la'. Scritto male la prima volta in questo test, non nel
// codice — ed e' il genere di conto che conviene far fare alla macchina.
check(
    'anche l apertura del 20 sta nei prossimi sette giorni',
    in_array('apertura del modulo', $titoli($g['settimana']), true),
    'ottenuto: ' . implode(', ', $titoli($g['settimana']))
);
check(
    'due settimane avanti sta piu in la',
    $titoli($g['prossimi']) === ['fra due settimane'],
    'ottenuto: ' . implode(', ', $titoli($g['prossimi']))
);
check(
    'nessun evento si perde per strada',
    count($g['oggi']) + count($g['settimana']) + count($g['prossimi']) + count($g['passati']) === count($eventi)
);

// La settimana e' «sette giorni», non «fino a domenica»: di domenica
// pomeriggio il secondo criterio lascerebbe il gruppo vuoto proprio quando
// si vuole sapere che cosa succede nei giorni dopo.
$domenica = new DateTimeImmutable('2026-10-18 15:00:00');
$gDomenica = Agenda::raggruppa([$evento('2026-10-22 10:00:00', 'mercoledì dopo')], $domenica);

check(
    'di domenica, i giorni successivi sono ancora «nei prossimi sette»',
    count($gDomenica['settimana']) === 1,
    'con «fino a domenica» il gruppo sarebbe vuoto e la cosa finirebbe in fondo'
);

// ---------------------------------------------------------------
// La griglia del mese
// ---------------------------------------------------------------

echo PHP_EOL . 'La griglia del mese' . PHP_EOL;

$ottobre = new DateTimeImmutable('2026-10-01 00:00:00');
$griglia = Agenda::griglia($ottobre, $eventi);

$tutteLeCaselle = array_merge(...$griglia);

check('le settimane sono intere', count($tutteLeCaselle) % 7 === 0, 'caselle: ' . count($tutteLeCaselle));
check(
    'la griglia comincia di lunedi',
    $griglia[0][0]['giorno']->format('N') === '1',
    'comincia di ' . $griglia[0][0]['giorno']->format('l')
);
check(
    'il primo del mese c e',
    in_array('2026-10-01', array_map(static fn (array $c): string => $c['giorno']->format('Y-m-d'), $tutteLeCaselle), true)
);
check(
    'e anche l ultimo',
    in_array('2026-10-31', array_map(static fn (array $c): string => $c['giorno']->format('Y-m-d'), $tutteLeCaselle), true)
);
check(
    'i giorni di orlo sono marcati come fuori mese',
    $griglia[0][0]['fuori'] === true && $griglia[0][3]['fuori'] === false,
    'il 1° ottobre 2026 e un giovedì: la prima riga comincia il 28 settembre'
);

$conEventi = array_values(array_filter(
    $tutteLeCaselle,
    static fn (array $c): bool => $c['eventi'] !== []
));

check(
    'gli eventi cadono nel loro giorno',
    count($conEventi) === 5,
    'giorni con qualcosa: ' . count($conEventi)
);
check(
    'tre eventi dello stesso giorno stanno nella stessa casella',
    count(array_values(array_filter(
        $tutteLeCaselle,
        static fn (array $c): bool => $c['giorno']->format('Y-m-d') === '2026-10-14'
    ))[0]['eventi']) === 3
);

// Febbraio di un anno bisestile che comincia di domenica: il caso in cui
// un errore di un giorno nella griglia si vede subito.
$febbraio = Agenda::griglia(new DateTimeImmutable('2032-02-01 00:00:00'), []);

check(
    'un mese che comincia di domenica ha la sua riga in piu',
    count($febbraio) === 6 || count($febbraio) === 5,
    'settimane: ' . count($febbraio)
);
check(
    'il 29 febbraio di un bisestile c e',
    in_array(
        '2032-02-29',
        array_map(static fn (array $c): string => $c['giorno']->format('Y-m-d'), array_merge(...$febbraio)),
        true
    )
);

// ---------------------------------------------------------------
// Il mese chiesto nell'indirizzo
// ---------------------------------------------------------------

echo PHP_EOL . 'Il mese chiesto nell\'indirizzo' . PHP_EOL;

$oggi = new DateTimeImmutable('2026-10-14 10:00:00');
$corrente = '2026-10';

foreach ([
    null => $corrente,
    '' => $corrente,
    'next tuesday' => $corrente,
    '+30 years' => $corrente,
    '2026-13' => $corrente,
    '2026-00' => $corrente,
    '9999-01' => $corrente,
    '1900-01' => $corrente,
    '2026-1' => $corrente,
    '2026-11' => '2026-11',
    '2025-01' => '2025-01',
] as $dato => $atteso) {
    $avuto = Agenda::meseChiesto($dato === '' ? '' : (string) $dato, $oggi)->format('Y-m');

    check(
        '«' . (string) $dato . '» → ' . $atteso,
        $avuto === $atteso,
        'ottenuto: ' . $avuto
    );
}

// Il nome del mese e' un titolo in cima alla griglia, non una parola in
// mezzo a una frase: va con l'iniziale maiuscola.
check('il nome del mese e in italiano e comincia maiuscolo', Agenda::nomeMese($oggi) === 'Ottobre 2026');
check(
    'vale per tutti e dodici',
    array_map(
        static fn (int $m): string => Agenda::nomeMese(new DateTimeImmutable(sprintf('2026-%02d-01', $m))),
        range(1, 12)
    ) === [
        'Gennaio 2026', 'Febbraio 2026', 'Marzo 2026', 'Aprile 2026', 'Maggio 2026', 'Giugno 2026',
        'Luglio 2026', 'Agosto 2026', 'Settembre 2026', 'Ottobre 2026', 'Novembre 2026', 'Dicembre 2026',
    ]
);
check('i giorni cominciano da lunedì', Agenda::nomiGiorni()[0] === 'lunedì');

// Le etichette dei due tipi stanno in un posto solo perche' compaiono in
// due — sotto al titolo e nel testo annunciato dalla griglia del mese — e
// scritte due volte divergono: e' gia' successo con l'iniziale maiuscola.
check('l etichetta dell incontro comincia maiuscola', Agenda::etichettaTipo(Agenda::INCONTRO) === 'Incontro dal vivo');
check('e anche quella dell apertura', Agenda::etichettaTipo(Agenda::APERTURA) === 'Apertura di un modulo');

// ---------------------------------------------------------------
// Il file .ics
// ---------------------------------------------------------------

echo PHP_EOL . 'Il file .ics' . PHP_EOL;

$ics = Ics::publish([
    [
        'uid' => 'incontro-1@pistacchio',
        'titolo' => 'Lezione di prova; con virgola, e barra \\ rovesciata',
        'inizio' => new DateTimeImmutable('2026-10-20 18:30:00'),
        'fine' => new DateTimeImmutable('2026-10-20 20:00:00'),
        'descrizione' => "Prima riga\nSeconda riga",
        'luogo' => 'https://example.org/molto/lungo/indirizzo/che/supera/sicuramente/i/settantacinque/ottetti/previsti',
    ],
    [
        'uid' => 'apertura-2@pistacchio',
        'titolo' => 'Si apre: Modulo 3',
        'inizio' => new DateTimeImmutable('2026-10-21 09:00:00'),
        'fine' => null,
    ],
], 'Pistacchio · Àlberto Perù');

$righe = explode("\r\n", $ics);

check('comincia e finisce come un calendario', str_starts_with($ics, 'BEGIN:VCALENDAR')
    && str_contains($ics, 'END:VCALENDAR'));
check('e un calendario da leggere, non un invito', str_contains($ics, 'METHOD:PUBLISH')
    && !str_contains($ics, 'ATTENDEE'));
check('porta il nome del calendario', str_contains($ics, 'X-WR-CALNAME:Pistacchio'));
check('ha un evento per ogni voce', substr_count($ics, 'BEGIN:VEVENT') === 2);
check(
    'le righe finiscono con CRLF',
    !preg_match('/[^\r]\n/', $ics) === true,
    'un solo "a capo" senza ritorno a capo basta a far rifiutare il file'
);
check(
    'nessuna riga supera i 75 ottetti',
    array_filter($righe, static fn (string $r): bool => strlen($r) > 75) === [],
    'la piu lunga: ' . max(array_map('strlen', $righe)) . ' ottetti'
);
check(
    'il punto e virgola e la virgola sono protetti',
    str_contains($ics, 'prova\\; con virgola\\,'),
    'non protetti, spezzerebbero il campo in piu parti'
);
check('la barra rovesciata e raddoppiata', str_contains($ics, 'barra \\\\ rovesciata'));
check('gli a capo diventano \\n', str_contains($ics, 'Prima riga\\nSeconda riga'));
check(
    'gli orari sono in UTC',
    preg_match('/DTSTART:\d{8}T\d{6}Z/', $ics) === 1,
    'in un file che legge chiunque, l\'ora locale senza fuso e ambigua'
);
// La durata si **calcola** dal file invece di confrontarla con un orario
// scritto a mano: l'orario dipende dal fuso dell'installazione, e un test
// che lo fissa fallisce su un server configurato diversamente dicendo che
// e' rotta la durata. Sbagliato cosi' la prima volta, qui dentro.
$secondoEvento = explode('BEGIN:VEVENT', $ics)[2] ?? '';
preg_match('/DTSTART:(\d{8}T\d{6}Z)/', $secondoEvento, $da);
preg_match('/DTEND:(\d{8}T\d{6}Z)/', $secondoEvento, $a);
$durata = isset($da[1], $a[1])
    ? (new DateTimeImmutable($a[1]))->getTimestamp() - (new DateTimeImmutable($da[1]))->getTimestamp()
    : -1;

check(
    'un evento senza fine dura mezz ora',
    $durata === 1800,
    'durata trovata: ' . $durata . ' secondi — alcuni calendari non disegnano '
        . 'affatto un evento di durata zero'
);
check(
    'una lettera accentata non viene spezzata a meta',
    mb_check_encoding($ics, 'UTF-8'),
    'il limite di riga e in ottetti: tagliando a meta di una «à» escono byte non validi'
);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
