<?php

declare(strict_types=1);

/**
 * Test dell'indirizzo dei ritorni (17/11): `App\Core\Ritorno`.
 *
 * Esecuzione:  php tests/ritorno_test.php
 *
 * Niente server e niente database. Le pagine vere si provano in
 * `tests/ritorni.js`.
 *
 * COSA VERIFICA:
 *
 *   1. **Andata e ritorno**: il collegamento, letto dalla pagina di arrivo,
 *      riporta alla pagina di partenza con i suoi parametri — anche quando
 *      la partenza ha a sua volta un ritorno (due livelli).
 *   2. **Solo le partenze che la pagina accetta**; senza `da_id`, o con un
 *      `da_id` storto, dove serve, niente.
 *   3. **Il ritorno non porta fuori**: il percorso lo scrive il server.
 *   4. **Dopo un salvataggio** il rimando alla stessa pagina si porta dietro
 *      il ritorno; verso un'altra pagina, un altro sito, o senza Referer,
 *      resta com'e'.
 *   5. **Una partenza sconosciuta nel codice** e' un errore, non un
 *      collegamento muto.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Ritorno;

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

/** La pagina di arrivo legge il proprio indirizzo. */
function letto(string $link, array $ammesse): ?array
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $get);

    return Ritorno::daRichiesta($get, $ammesse);
}

echo "1. Andata e ritorno\n";

$link = Ritorno::link('/reports/courses/7', 'elenco-corsi', 0, ['q' => 'mindful', 'ordina' => 'titolo', 'verso' => 'disc']);
$r = letto($link, ['elenco-corsi']);
check('dall\'elenco dei corsi, con ricerca e ordine', $r !== null && Ritorno::indirizzo($r) === '/reports/elenco/courses?q=mindful&ordina=titolo&verso=disc', $link);

$link = Ritorno::link('/live/9', 'gruppo', 4, ['corso' => '2']);
$r = letto($link, ['gruppo']);
check('dal gruppo, con il ramo del corso', $r !== null && Ritorno::indirizzo($r) === '/gruppi/4?corso=2');

$link = Ritorno::link('/bacheca/nuova?corso=3&categoria=5', 'categoria', 5);
check('un indirizzo che ha gia\' dei parametri li tiene', str_starts_with($link, '/bacheca/nuova?corso=3&categoria=5&da=categoria&da_id=5'), $link);

// Due livelli: il tentativo aperto dal report di uno studente, aperto a
// sua volta dalla griglia di un corso con un gruppo scelto.
$studente = ['corso' => '7', 'da' => 'griglia', 'torna' => 'gruppo=3&ordina=studente'];
$link = Ritorno::link('/attempts/22', 'report-studente', 5, $studente);
$r = letto($link, ['report-studente']);
$al = $r === null ? '' : Ritorno::indirizzo($r);
parse_str((string) parse_url($al, PHP_URL_QUERY), $diNuovo);
check('due livelli: si torna al report dello studente con il suo ritorno intatto',
    str_starts_with($al, '/reports/students/5?') && $diNuovo === $studente, $al);

echo "\n2. Solo le partenze accettate\n";

check('una partenza che la pagina non accetta: niente', letto(Ritorno::link('/live/9', 'agenda'), ['gruppo']) === null);
check('`da` sconosciuto: niente', Ritorno::daRichiesta(['da' => 'altrove'], ['altrove']) === null);
check('senza `da_id` dove serve: niente', Ritorno::daRichiesta(['da' => 'gruppo'], ['gruppo']) === null);

foreach (['abc', '0', '-1', '4.5'] as $id) {
    check("`da_id` «{$id}»: niente", Ritorno::daRichiesta(['da' => 'gruppo', 'da_id' => $id], ['gruppo']) === null);
}

$r = Ritorno::daRichiesta(['da' => 'agenda', 'da_id' => '99'], ['agenda']);
check('una partenza senza id ignora `da_id`', $r !== null && Ritorno::indirizzo($r) === '/agenda');

echo "\n3. Il ritorno non porta fuori\n";

foreach (['https://altrove.example/x', '//altrove.example', 'a/b=1', 'x[]=1&x[]=2'] as $torna) {
    $r = Ritorno::daRichiesta(['da' => 'agenda', 'torna' => $torna], ['agenda']);
    $al = Ritorno::indirizzo($r);
    check('«' . $torna . '»: resta sull\'Agenda', str_starts_with($al, '/agenda') && !str_contains($al, 'altrove') && !str_contains($al, '//'), $al);
}

$r = Ritorno::daRichiesta(['da' => 'agenda', 'torna' => str_repeat('a=1&', 600)], ['agenda']);
check('un `torna` troppo lungo si lascia', Ritorno::indirizzo($r) === '/agenda');

echo "\n4. Dopo un salvataggio\n";

$sito = 'lms.example';
$da = 'https://lms.example/live/9?da=report-incontro&da_id=9&torna=ordina%3Dnome';
$s = Ritorno::dopoIlSalvataggio('/live/9', $da, $sito);
check('stessa pagina: il rimando si porta dietro il ritorno', $s === '/live/9?da=report-incontro&da_id=9&torna=ordina%3Dnome', $s);
$s = Ritorno::dopoIlSalvataggio('/live/9#presenze', $da, $sito);
check('con l\'ancora, il ritorno va prima dell\'ancora', $s === '/live/9?da=report-incontro&da_id=9&torna=ordina%3Dnome#presenze', $s);
$s = Ritorno::dopoIlSalvataggio('/admin/courses/3/edit?scheda=iscritti', 'https://lms.example/admin/courses/3/edit?da=scheda-gruppo&da_id=4', $sito);
check('un rimando con i suoi parametri li tiene', $s === '/admin/courses/3/edit?scheda=iscritti&da=scheda-gruppo&da_id=4', $s);
check('un\'altra pagina: il rimando non cambia', Ritorno::dopoIlSalvataggio('/live', $da, $sito) === '/live');
check('un altro sito: il rimando non cambia', Ritorno::dopoIlSalvataggio('/live/9', 'https://altrove.example/live/9?da=agenda', $sito) === '/live/9');
check('senza Referer: il rimando non cambia', Ritorno::dopoIlSalvataggio('/live/9', '', $sito) === '/live/9');
check('una pagina senza ritorno: il rimando non cambia', Ritorno::dopoIlSalvataggio('/live/9', 'https://lms.example/live/9?ordina=nome', $sito) === '/live/9');
check('un rimando che ha gia\' il suo ritorno non si tocca',
    Ritorno::dopoIlSalvataggio('/live/9?da=agenda', $da, $sito) === '/live/9?da=agenda');
check('un rimando fuori dal sito non si tocca', Ritorno::dopoIlSalvataggio('https://altrove.example/live/9', $da, $sito) === 'https://altrove.example/live/9');
check('la porta del sito non conta', Ritorno::dopoIlSalvataggio('/live/9', $da, 'lms.example:8443') !== '/live/9');

echo "\n5. Una partenza sconosciuta nel codice\n";

try {
    Ritorno::link('/live/9', 'boh');
    check('eccezione', false);
} catch (InvalidArgumentException) {
    check('eccezione', true);
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
