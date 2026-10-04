<?php

declare(strict_types=1);

/**
 * Test delle tavolozze di colori (§8.5, patch 0080).
 *
 * Esecuzione:  php tests/tema_test.php
 *
 * Quasi tutto e' logica pura. L'unica prova che ha bisogno del database e'
 * quella sul blocco stampato nella pagina — legge quale tavolozza e'
 * scelta — e se il database non c'e' viene saltata dicendolo, invece di far
 * fallire il resto.
 *
 * PERCHE' ESISTE. Una tavolozza non e' un colore, sono sette valori che
 * devono reggersi fra loro. Il controllo di accessibilita' (`accessibilita.js`)
 * misura il contrasto di quello che c'e' **sullo schermo**, quindi vede solo
 * la tavolozza attiva al momento: per sapere che tutte e quattro vanno bene
 * bisognerebbe eseguirlo quattro volte, col server acceso. Questo file rifa
 * lo stesso conto in un secondo, senza server, su ogni tavolozza e su ogni
 * coppia — e fallisce **prima** che una tavolozza nuova arrivi a una pagina.
 *
 * L'altra meta' del file riguarda la sicurezza: i colori arrivano dal
 * database e finiscono dentro un tag `<style>`. Se un valore passasse senza
 * controllo, sarebbe CSS scritto da chi ha scritto quella riga.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\ElementStyle;
use App\Core\Theme;

$ok = 0;
$fail = 0;

function check(string $label, bool $condizione, string $dettaglio = ''): void
{
    global $ok, $fail;
    $condizione ? $ok++ : $fail++;
    echo ($condizione ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condizione && $dettaglio !== '') {
        echo '         · ' . $dettaglio . PHP_EOL;
    }
}

// ---------------------------------------------------------------
echo PHP_EOL . 'La formula del contrasto' . PHP_EOL;

// I tre valori noti: se questi tornano, la formula e' quella delle WCAG.
check(
    'nero su bianco fa 21',
    abs(Theme::contrasto('#000000', '#FFFFFF') - 21.0) < 0.01,
    sprintf('%.4f', Theme::contrasto('#000000', '#FFFFFF'))
);
check(
    'bianco su bianco fa 1',
    abs(Theme::contrasto('#FFFFFF', '#FFFFFF') - 1.0) < 0.01
);
check(
    'il verso non conta',
    abs(Theme::contrasto('#4F7256', '#FFFFFF') - Theme::contrasto('#FFFFFF', '#4F7256')) < 0.0001
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Quali colori entrano nel foglio di stile' . PHP_EOL;

check('#4F7256 va bene', Theme::coloreValido('#4F7256') === '#4F7256');
check('minuscolo diventa maiuscolo', Theme::coloreValido('#4f7256') === '#4F7256');
check('la forma corta si espande', Theme::coloreValido('#abc') === '#AABBCC');
check('uno spazio intorno non disturba', Theme::coloreValido('  #abc  ') === '#AABBCC');

// Questa e' la prova che conta: il valore finisce dentro un tag <style>.
check(
    'una fuga dal CSS viene respinta',
    Theme::coloreValido('#fff; } body { display: none') === null,
    'un valore cosi, se passasse, sarebbe CSS scritto da chi ha scritto la riga nel database'
);
check('un nome di colore viene respinto', Theme::coloreValido('red') === null);
check('una funzione CSS viene respinta', Theme::coloreValido('rgb(0,0,0)') === null);
check('un url viene respinto', Theme::coloreValido('url(http://x/y)') === null);
check('il vuoto viene respinto', Theme::coloreValido('') === null);
check('cinque cifre vengono respinte', Theme::coloreValido('#12345') === null);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il blocco stampato nella pagina' . PHP_EOL;

// Queste prove leggono la tavolozza scelta nell'installazione su cui girano,
// quindi NON possono dare per scontato quale sia: un test che pretende lo
// stato del database fallisce sul computer di chi ha scelto un'altra
// tavolozza, e non per un difetto del codice. Si verifica la regola, non il
// valore: o non si stampa niente, o si stampa un blocco ben formato.
try {
    $blocco = Theme::blocco();
    $scelta = Theme::paletteCorrente();

    echo '  --   tavolozza attiva qui: ' . $scelta . PHP_EOL;

    if ($scelta === Theme::PREDEFINITA && Theme::valori()['primary'] === Theme::TAVOLOZZE[Theme::PREDEFINITA]['primary']) {
        check(
            'con la tavolozza predefinita non si stampa niente',
            $blocco === '',
            'e gia quello del foglio di stile: stamparlo di nuovo e solo peso in piu'
        );
    } else {
        check('con una tavolozza scelta si stampa qualcosa', $blocco !== '');
    }

    if ($blocco !== '') {
        check(
            'quello che si stampa e solo un blocco :root di variabili',
            (bool) preg_match('/^:root\{(--[a-z-]+: [^;{}<>]+;?)+\}$/', $blocco),
            $blocco
        );
        check(
            'niente che possa chiudere il tag <style>',
            !str_contains($blocco, '<') && !str_contains($blocco, '>'),
            $blocco
        );
    }
} catch (Throwable $e) {
    echo '  --   database non raggiungibile: prove sul blocco saltate' . PHP_EOL;
}

// ---------------------------------------------------------------
echo PHP_EOL . 'Il colore principale proposto dall\'admin' . PHP_EOL;

check('il verde di oggi passa', Theme::perche('#4F7256') === null);
check('un grigio chiarissimo viene rifiutato', Theme::perche('#DDDDDD') !== null);
check('il giallo viene rifiutato', Theme::perche('#FFD400') !== null);
check('il nero passa', Theme::perche('#000000') === null);
check(
    'il motivo del rifiuto dice un numero',
    (bool) preg_match('/\d+[.,]\d+:1/', (string) Theme::perche('#DDDDDD')),
    (string) Theme::perche('#DDDDDD')
);
check(
    'una scrittura sbagliata lo dice invece di parlare di contrasto',
    str_contains((string) Theme::perche('verde'), '#rrggbb')
);

// Le varianti derivate da un colore scelto a mano devono a loro volta
// reggere: e' quello che il salvataggio promette.
foreach (['#4F7256', '#000000', '#3F6079', '#7A2E2E'] as $scelto) {
    $hover = Theme::scurisci($scelto, 0.12);
    $soft = Theme::schiarisci($scelto, 0.90);

    check(
        "da {$scelto}: il bianco si legge sulla variante scura {$hover}",
        Theme::contrasto('#FFFFFF', $hover) >= Theme::MINIMO,
        sprintf('%.2f', Theme::contrasto('#FFFFFF', $hover))
    );
    check(
        "da {$scelto}: il colore si legge sulla sua tinta chiara {$soft}",
        Theme::contrasto($scelto, $soft) >= Theme::MINIMO,
        sprintf('%.2f', Theme::contrasto($scelto, $soft))
    );
}

// ---------------------------------------------------------------
echo PHP_EOL . 'Le quattro tavolozze, coppia per coppia' . PHP_EOL;

/**
 * Le combinazioni che si verificano davvero sullo schermo. L'elenco non e'
 * teorico: viene dal leggere, su una pagina vera, quale colore di testo
 * finisce sopra quale sfondo.
 *
 * Una coppia che si potrebbe immaginare — il grigio dei testi secondari sul
 * crema delle pagine pubbliche — **non c'e'**, ed e' stato verificato: quel
 * grigio sta sempre dentro il riquadro bianco. Aggiungerla avrebbe fatto
 * fallire il test per una combinazione che nessuno vede.
 *
 * @var array<string, callable(array<string, string>): array{0: string, 1: string}>
 */
$coppie = [
    'principale su bianco' => fn (array $t): array => [$t['primary'], Theme::SUPERFICIE],
    'principale sullo sfondo' => fn (array $t): array => [$t['primary'], Theme::SFONDO_PAGINA],
    'principale sul fondo delle pagine pubbliche' => fn (array $t): array => [$t['primary'], $t['auth_bg']],
    'bianco sul pulsante' => fn (array $t): array => ['#FFFFFF', $t['primary']],
    'bianco sul pulsante premuto' => fn (array $t): array => ['#FFFFFF', $t['hover']],
    'variante scura sulla tinta chiara' => fn (array $t): array => [$t['hover'], $t['soft']],
    'principale sulla tinta chiara' => fn (array $t): array => [$t['primary'], $t['soft']],
    'testo sulla tinta chiara' => fn (array $t): array => [Theme::TESTO, $t['soft']],
    'titolo della presentazione' => fn (array $t): array => [$t['scene_h'], $t['auth_bg']],
    'testo della presentazione' => fn (array $t): array => [$t['scene_p'], $t['auth_bg']],
];

foreach (Theme::TAVOLOZZE as $chiave => $tavolozza) {
    $peggiore = 99.0;
    $dove = '';

    foreach ($coppie as $nome => $f) {
        [$a, $b] = $f($tavolozza);
        $rapporto = Theme::contrasto($a, $b);

        if ($rapporto < $peggiore) {
            $peggiore = $rapporto;
            $dove = $nome;
        }

        check(
            sprintf('%-11s %-44s %5.2f', $chiave, $nome, $rapporto),
            $rapporto >= Theme::MINIMO,
            sprintf('%s su %s: servono %.1f:1', $a, $b, Theme::MINIMO)
        );
    }

    printf("         (%s: la coppia piu' stretta e \"%s\", %.2f:1)%s", $chiave, $dove, $peggiore, PHP_EOL);
}

// Ogni tavolozza deve avere tutte le chiavi: una dimenticata uscirebbe come
// stringa vuota dentro il CSS.
$attese = ['nome', 'primary', 'hover', 'soft', 'auth_bg', 'orb_a', 'orb_b', 'scene_h', 'scene_p'];

foreach (Theme::TAVOLOZZE as $chiave => $tavolozza) {
    check(
        "{$chiave}: non manca nessun valore",
        array_keys($tavolozza) === $attese || count(array_diff($attese, array_keys($tavolozza))) === 0,
        'mancano: ' . implode(', ', array_diff($attese, array_keys($tavolozza)))
    );
}

check(
    'la tavolozza predefinita esiste',
    isset(Theme::TAVOLOZZE[Theme::PREDEFINITA])
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Arrotondamento e dimensione del testo' . PHP_EOL;

check('il livello predefinito di arrotondamento esiste', isset(Theme::RAGGI[Theme::RAGGIO_PREDEFINITO]));
check('la misura predefinita del testo esiste', isset(Theme::MISURE_TESTO[Theme::MISURA_TESTO_PREDEFINITA]));

foreach (Theme::RAGGI as $chiave => $r) {
    check(
        "{$chiave}: i due raggi sono misure in pixel",
        preg_match('/^\d{1,3}px$/', $r['sm']) === 1 && preg_match('/^\d{1,3}px$/', $r['md']) === 1,
        $r['sm'] . ' / ' . $r['md']
    );
    check(
        "{$chiave}: il raggio dei riquadri non e piu piccolo di quello dei campi",
        (int) $r['md'] >= (int) $r['sm'],
        'invertirli farebbe sembrare la pagina assemblata a caso'
    );
}

foreach (Theme::MISURE_TESTO as $chiave => $m) {
    $px = (int) $m['px'];
    check("{$chiave}: misura in pixel", preg_match('/^\d{1,3}px$/', $m['px']) === 1, $m['px']);
    check(
        "{$chiave}: non si scende sotto i 15 px",
        $px >= 15,
        $m['px'] . ' — piu in basso peggiora la lettura per tutti'
    );
    check("{$chiave}: non si sale sopra i 20 px", $px <= 20, $m['px']);
}

// ---------------------------------------------------------------
echo PHP_EOL . 'Misura della presentazione (aspetto affiancato)' . PHP_EOL;

check(
    'la misura predefinita esiste',
    isset(Theme::MISURE_SCENA[Theme::MISURA_SCENA_PREDEFINITA])
);

$precedenteTitolo = 0.0;

foreach (Theme::MISURE_SCENA as $chiave => $m) {
    check(
        "{$chiave}: due misure in rem",
        preg_match('/^\d{1,2}(\.\d{1,2})?rem$/', $m['titolo']) === 1
        && preg_match('/^\d{1,2}(\.\d{1,2})?rem$/', $m['testo']) === 1,
        $m['titolo'] . ' / ' . $m['testo']
    );
    check(
        "{$chiave}: il titolo e piu grande del testo sotto",
        (float) $m['titolo'] > (float) $m['testo'],
        'un titolo piu piccolo del proprio sottotitolo non e una misura, e un difetto'
    );
    check(
        "{$chiave}: piu grande del livello precedente",
        (float) $m['titolo'] > $precedenteTitolo,
        'i livelli devono crescere, o l\'elenco confonde invece di aiutare'
    );
    $precedenteTitolo = (float) $m['titolo'];
}

check(
    'la misura predefinita e quella che il foglio di stile aveva prima',
    Theme::MISURE_SCENA[Theme::MISURA_SCENA_PREDEFINITA]['titolo'] === '1.85rem'
    && Theme::MISURE_SCENA[Theme::MISURA_SCENA_PREDEFINITA]['testo'] === '1rem',
    'chi non tocca niente non deve vedere la pagina cambiata'
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il colore del testo, e il grigio ricavato da lui' . PHP_EOL;

check('il quasi-nero di oggi passa', Theme::percheTesto(Theme::TESTO) === null);
check('un grigio chiaro viene rifiutato', Theme::percheTesto('#BBBBBB') !== null);
check(
    'un grigio al limite viene rifiutato per lo sfondo, non per il bianco',
    str_contains((string) Theme::percheTesto('#767676'), 'sfondo'),
    (string) Theme::percheTesto('#767676')
);
check(
    'un colore buono su bianco ma non sulle tinte tenui viene rifiutato',
    Theme::percheTesto('#6E6E6E') !== null || Theme::contrasto('#6E6E6E', '#F0EAF3') >= Theme::MINIMO,
    'il controllo deve guardare anche le tavolozze che oggi non sono attive'
);

// Il grigio spento e' ricavato, non scelto: deve restare leggibile comunque
// sia il colore di partenza.
foreach ([Theme::TESTO, '#000000', '#333333', '#1F2937', '#402020'] as $testo) {
    $spento = Theme::spegni($testo);

    check(
        "da {$testo} il grigio {$spento} si legge sul bianco",
        Theme::contrasto($spento, Theme::SUPERFICIE) >= Theme::MINIMO,
        sprintf('%.2f', Theme::contrasto($spento, Theme::SUPERFICIE))
    );
    check(
        "da {$testo} il grigio {$spento} si legge sullo sfondo",
        Theme::contrasto($spento, Theme::SFONDO_PAGINA) >= Theme::MINIMO,
        sprintf('%.2f', Theme::contrasto($spento, Theme::SFONDO_PAGINA))
    );
    check(
        "da {$testo} il grigio e piu chiaro del testo, non piu scuro",
        Theme::contrasto($spento, Theme::SUPERFICIE) <= Theme::contrasto($testo, Theme::SUPERFICIE),
        'un "testo secondario" piu marcato del testo principale e il contrario di quello che serve'
    );
}

check(
    'un testo gia al limite non produce un grigio illeggibile',
    Theme::contrasto(Theme::spegni('#767676'), Theme::SUPERFICIE) >= 4.0,
    'in quel caso si restituisce il colore di partenza invece di schiarire'
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Ritocchi ai singoli elementi' . PHP_EOL;

check('ci sono elementi da ritoccare', ElementStyle::ELEMENTI !== []);
check('ci sono proprieta', ElementStyle::PROPRIETA !== []);

$vietate = ['display', 'visibility', 'position', 'content', 'opacity', 'z-index', 'transform'];
$fuorilegge = [];

foreach (ElementStyle::PROPRIETA as $nome => $prop) {
    if (in_array($prop['css'], $vietate, true)) {
        $fuorilegge[] = $nome . ' => ' . $prop['css'];
    }
}

check(
    'nessuna proprieta puo nascondere o spostare qualcosa',
    $fuorilegge === [],
    implode(', ', $fuorilegge) . ' — e la trappola di §5: cio che si nasconde resta inviato'
);

foreach (ElementStyle::ELEMENTI as $chiave => $el) {
    check(
        "{$chiave}: il selettore non puo uscire dal proprio ambito",
        !str_contains($el['selettore'], '{') && !str_contains($el['selettore'], '}')
        && !str_contains($el['selettore'], ';'),
        $el['selettore']
    );
    check("{$chiave}: ha un nome e una descrizione", $el['nome'] !== '' && $el['descrizione'] !== '');
}

// La pulizia e' la difesa vera: tutto quello che non e' negli elenchi
// chiusi non diventa CSS.
$sporco = [
    'scene_titolo' => ['peso' => '400', 'inventata' => 'x', 'stile' => 'italic'],
    'elemento_che_non_esiste' => ['peso' => '700'],
    'pulsante' => ['peso' => '999'],
    'marchio' => ['colore' => '#fff; } body{display:none'],
    'scene_testo' => ['allineamento' => 'justify'],
    42 => ['peso' => '400'],
];
$pulito = ElementStyle::ripulisci($sporco);

check('un valore valido passa', ($pulito['scene_titolo']['peso'] ?? '') === '400');
check('una proprieta inventata non passa', !isset($pulito['scene_titolo']['inventata']));
check('un elemento che non esiste non passa', !isset($pulito['elemento_che_non_esiste']));
check('un peso fuori elenco non passa', !isset($pulito['pulsante']['peso']));
check(
    'un colore che prova a chiudere il CSS non passa',
    !isset($pulito['marchio']['colore']),
    'finirebbe dentro un tag <style>'
);
check('un allineamento fuori elenco non passa', !isset($pulito['scene_testo']['allineamento']));
check('una chiave che non e una stringa non passa', !isset($pulito[42]));

// Il CSS prodotto: solo regole, niente che possa uscirne.
try {
    $css = ElementStyle::blocco();
    check(
        'il CSS prodotto non contiene tag',
        !str_contains($css, '<') && !str_contains($css, '>'),
        $css
    );
} catch (Throwable $e) {
    echo '  --   database non raggiungibile: prova sul CSS saltata' . PHP_EOL;
}

check(
    'un colore illeggibile sul fondo della presentazione viene respinto',
    ElementStyle::perche(['scene_titolo' => ['colore' => '#CCCCCC']]) !== null
);
check(
    'un colore illeggibile sul riquadro bianco viene respinto',
    ElementStyle::perche(['titolo_riquadro' => ['colore' => '#999999']]) !== null
);
check(
    'un colore scuro passa',
    ElementStyle::perche(['scene_titolo' => ['colore' => '#2F3A2C']]) === null
);
check(
    'il motivo dice quale elemento e quale fondo',
    str_contains((string) ElementStyle::perche(['scene_titolo' => ['colore' => '#CCCCCC']]), 'presentazione'),
    (string) ElementStyle::perche(['scene_titolo' => ['colore' => '#CCCCCC']])
);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
