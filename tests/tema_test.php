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

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
