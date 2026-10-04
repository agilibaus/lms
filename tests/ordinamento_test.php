<?php

declare(strict_types=1);

/**
 * Test dell'ordinamento delle tabelle dal nome della colonna.
 *
 * Esecuzione:  php tests/ordinamento_test.php
 *
 * Niente server e niente database: `App\Core\Ordinamento` lavora su righe
 * gia' lette e su un elenco di parametri, e si prova con tutti e due finti.
 *
 * COSA VERIFICA, e perche' proprio questo. L'ordinamento sbaglia in modi
 * che non si vedono guardando la pagina: le righe restano tutte li', nello
 * stesso numero, e sembrano solo messe in un altro ordine.
 *
 *   1. **Quello che arriva dall'indirizzo non diventa mai un nome di
 *      campo.** Una chiave non dichiarata viene ignorata, e l'elenco resta
 *      nell'ordine di partenza.
 *   2. **I numeri si ordinano da numeri.** Per stringa, 100 viene prima di 9.
 *   3. **Le date si ordinano dalla data vera**, non da «04/10/2026»: li'
 *      marzo verrebbe prima di maggio e il 2025 dopo il 2026.
 *   4. **I vuoti stanno in fondo in tutti e due i versi.** Un trattino che
 *      sale in cima invertendo l'ordine non e' un'informazione.
 *   5. **Senza parametri non si ordina niente**: ogni elenco ha gia' un suo
 *      ordine, e sostituirlo al primo caricamento e' un peggioramento.
 *   6. L'intestazione dichiara lo stato a un lettore di schermo
 *      (`aria-sort`) e il collegamento porta al verso opposto.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Ordinamento;

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

$_SERVER['REQUEST_URI'] = '/admin/users';

$COLONNE = [
    'nome' => ['full_name', Ordinamento::TESTO],
    'punti' => ['score', Ordinamento::NUMERO],
    'quando' => ['starts_at', Ordinamento::DATA],
];

$RIGHE = [
    ['full_name' => 'Zoe', 'score' => 9, 'starts_at' => '2026-03-01 09:00:00'],
    ['full_name' => 'àlberto', 'score' => 100, 'starts_at' => '2025-05-01 09:00:00'],
    ['full_name' => 'Bruno', 'score' => 20, 'starts_at' => '2026-05-01 09:00:00'],
    ['full_name' => null, 'score' => null, 'starts_at' => null],
];

/** @return list<string|null> */
$valori = static function (array $righe, string $campo): array {
    return array_map(static fn (array $r) => $r[$campo], $righe);
};

$con = static function (array $parametri) use ($COLONNE): Ordinamento {
    return Ordinamento::daRichiesta($COLONNE, $parametri);
};

// ---------------------------------------------------------------
// Quello che arriva dall'indirizzo
// ---------------------------------------------------------------

echo PHP_EOL . 'Quello che arriva dall\'indirizzo' . PHP_EOL;

check('senza parametri non si ordina', $con([])->chiave() === null);
check(
    'senza parametri le righe restano come sono',
    $valori($con([])->applica($RIGHE), 'full_name') === ['Zoe', 'àlberto', 'Bruno', null],
    'l\'ordine di partenza lo decide la query, non questa classe'
);
check('una chiave non dichiarata viene ignorata', $con(['ordina' => 'full_name'])->chiave() === null);
check(
    'e con essa le righe non si muovono',
    $valori($con(['ordina' => 'password'])->applica($RIGHE), 'full_name') === ['Zoe', 'àlberto', 'Bruno', null]
);
check('il campo di una chiave ignorata e null', $con(['ordina' => 'id'])->campo() === null);
check(
    'la chiave dichiarata diventa il campo dichiarato',
    $con(['ordina' => 'nome'])->campo() === 'full_name',
    'il nome del campo non arriva mai da fuori: lo sceglie la vista'
);
check('un verso sconosciuto vale crescente', $con(['ordina' => 'nome', 'verso' => 'su'])->verso() === 'asc');
// `?ordina[]=nome` arriva come array: senza un controllo sul tipo, PHP lo
// converte in stringa e stampa un avviso in cima alla pagina. Trovato
// provando indirizzi inventati su una pagina vera.
check(
    'un parametro che arriva come array non e una chiave',
    $con(['ordina' => ['nome'], 'verso' => ['asc']])->chiave() === null
);
check('il verso decrescente si riconosce', $con(['ordina' => 'nome', 'verso' => 'desc'])->verso() === 'desc');

// ---------------------------------------------------------------
// Come si confronta
// ---------------------------------------------------------------

echo PHP_EOL . 'Come si confronta' . PHP_EOL;

check(
    'i numeri si ordinano da numeri',
    $valori($con(['ordina' => 'punti'])->applica($RIGHE), 'score') === [9, 20, 100, null],
    'per stringa, 100 verrebbe prima di 9 e di 20'
);
check(
    'i numeri al contrario',
    $valori($con(['ordina' => 'punti', 'verso' => 'desc'])->applica($RIGHE), 'score') === [100, 20, 9, null]
);
check(
    'le date si ordinano dalla data vera',
    $valori($con(['ordina' => 'quando'])->applica($RIGHE), 'starts_at')
        === ['2025-05-01 09:00:00', '2026-03-01 09:00:00', '2026-05-01 09:00:00', null],
    'sul testo all\'italiana, 04/03 verrebbe prima di 04/05 anche di un altro anno'
);
check(
    'le lettere accentate stanno al loro posto nell\'alfabeto',
    $valori($con(['ordina' => 'nome'])->applica($RIGHE), 'full_name') === ['àlberto', 'Bruno', 'Zoe', null],
    'confrontando i byte, «àlberto» finirebbe dopo «Zoe»'
);
check(
    'le maiuscole non contano',
    $valori(
        $con(['ordina' => 'nome'])->applica([
            ['full_name' => 'bruno'],
            ['full_name' => 'Anna'],
        ]),
        'full_name'
    ) === ['Anna', 'bruno']
);

// Il confronto di ripiego: quello che gira dove `intl` non e' installato,
// cioe' proprio dove non possiamo guardare. Non ordina come `Collator` —
// toglie l'accento invece di saperne il posto — ma deve restare molto piu'
// vicino del confronto fra byte, che manda tutte le accentate in fondo.
check(
    'il ripiego senza intl mette comunque «àlberto» prima di «Zoe»',
    Ordinamento::ripiegoTesto('àlberto', 'Zoe') < 0
);
check(
    'e ignora le maiuscole',
    Ordinamento::ripiegoTesto('anna', 'Anna') === 0
);
check(
    'l\'estensione intl e\' installata qui, quindi il giro normale usa Collator',
    class_exists(\Collator::class),
    'se un giorno mancasse, questi controlli proverebbero due volte il ripiego'
);

// ---------------------------------------------------------------
// I vuoti
// ---------------------------------------------------------------

echo PHP_EOL . 'I vuoti' . PHP_EOL;

foreach (['asc', 'desc'] as $verso) {
    $esito = $valori($con(['ordina' => 'nome', 'verso' => $verso])->applica($RIGHE), 'full_name');

    check(
        "i vuoti restano in fondo anche in senso «{$verso}»",
        end($esito) === null,
        'ottenuto: ' . json_encode($esito, JSON_UNESCAPED_UNICODE)
    );
}

$conTrattino = [['full_name' => '—'], ['full_name' => 'Anna'], ['full_name' => '']];
$esito = $valori($con(['ordina' => 'nome', 'verso' => 'desc'])->applica($conTrattino), 'full_name');

check(
    'anche il trattino e la stringa vuota contano come vuoti',
    $esito[0] === 'Anna',
    'ottenuto: ' . json_encode($esito, JSON_UNESCAPED_UNICODE)
);

// ---------------------------------------------------------------
// L'ordine e stabile
// ---------------------------------------------------------------

echo PHP_EOL . 'La stabilita\'' . PHP_EOL;

$pari = [
    ['full_name' => 'Anna', 'score' => 5],
    ['full_name' => 'Bruno', 'score' => 5],
    ['full_name' => 'Carla', 'score' => 5],
];

check(
    'a parita di valore l\'ordine di partenza non si rimescola',
    $valori($con(['ordina' => 'punti'])->applica($pari), 'full_name') === ['Anna', 'Bruno', 'Carla'],
    'altrimenti la stessa pagina ricaricata mostrerebbe un ordine diverso'
);

// ---------------------------------------------------------------
// L'intestazione
// ---------------------------------------------------------------

echo PHP_EOL . 'L\'intestazione' . PHP_EOL;

$spenta = $con([])->th('Nome', 'nome');
$attiva = $con(['ordina' => 'nome'])->th('Nome', 'nome');
$rovescia = $con(['ordina' => 'nome', 'verso' => 'desc'])->th('Nome', 'nome');
$muta = $con([])->th('Azioni');

check('una colonna senza chiave resta un\'intestazione', !str_contains($muta, '<a '));
check('una colonna con chiave diventa un collegamento', str_contains($spenta, '<a '));
check('la colonna non ordinata dichiara aria-sort="none"', str_contains($spenta, 'aria-sort="none"'));
check('quella crescente lo dichiara', str_contains($attiva, 'aria-sort="ascending"'));
check('quella decrescente lo dichiara', str_contains($rovescia, 'aria-sort="descending"'));
check(
    'il primo clic su una colonna nuova ordina in senso crescente',
    str_contains($spenta, 'verso=asc'),
    'dalla A alla Z e quello che ci si aspetta aprendo un elenco'
);
check(
    'il clic su quella gia crescente la rovescia',
    str_contains($attiva, 'verso=desc')
);
check(
    'e il clic su quella decrescente la rimette crescente',
    str_contains($rovescia, 'verso=asc')
);
check(
    'la freccia c\'e sempre, anche vuota',
    substr_count($spenta, 'ordina-freccia') === 1 && substr_count($attiva, 'ordina-freccia') === 1,
    'senza, la colonna cambierebbe larghezza al primo clic'
);
check('la colonna attiva si distingue anche senza la freccia', str_contains($attiva, 'ordina-attiva'));
check(
    'il collegamento dice a voce che cosa fa',
    str_contains($spenta, 'ordina per questa colonna')
        && str_contains($attiva, 'ordina in senso decrescente'),
    '«Nome» letto da solo non si distingue da un\'intestazione qualunque'
);
check(
    'l\'etichetta passa dall\'escape',
    str_contains($con([])->th('<b>x</b>', 'nome'), '&lt;b&gt;'),
    'le intestazioni le scrive la vista, ma una colonna calcolata potrebbe portare dati'
);

// ---------------------------------------------------------------
// L'indirizzo
// ---------------------------------------------------------------

echo PHP_EOL . 'L\'indirizzo' . PHP_EOL;

$_SERVER['REQUEST_URI'] = '/reports/elenco/students?cerca=rossi&pagina=7';
$conRicerca = $con(['cerca' => 'rossi', 'pagina' => '7', 'ordina' => 'nome']);
$link = $conRicerca->indirizzo('punti', 'asc');

check('l\'indirizzo resta quello della pagina', str_starts_with($link, '/reports/elenco/students?'));
check('la ricerca in corso non si perde', str_contains($link, 'cerca=rossi'));
check(
    'la pagina torna alla prima',
    !str_contains($link, 'pagina='),
    'la settima pagina di un ordine nuovo contiene righe diverse da quelle che si guardavano'
);
check('l\'ordine chiesto c\'e', str_contains($link, 'ordina=punti') && str_contains($link, 'verso=asc'));

$_SERVER['REQUEST_URI'] = '/admin/users';
$ostile = Ordinamento::daRichiesta($COLONNE, ['ordina' => 'nome', 'cerca' => '"><script>'])
    ->indirizzo('nome', 'desc');

check(
    'i parametri tenuti finiscono nell\'indirizzo codificati',
    !str_contains($ostile, '<script>') && str_contains($ostile, 'cerca=%22%3E%3Cscript%3E'),
    'ottenuto: ' . $ostile
);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
