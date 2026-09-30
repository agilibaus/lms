<?php

declare(strict_types=1);

/**
 * Test del riferimento al video nel modulo della lezione.
 *
 * Esecuzione:  php tests/lesson_video_test.php
 *
 * Nasce da un difetto vero: nel modulo i due campi dell'ID — quello di Bunny
 * e quello di Cloudflare — si chiamavano tutti e due `video_ref`. L'attributo
 * `hidden` nasconde il campo ma non lo toglie dall'invio, quindi partivano
 * tutti e due e in PHP vinceva l'ultimo, cioe' quello vuoto. L'ID scritto
 * dall'amministratore non arrivava mai al salvataggio, e la lezione restava
 * senza video senza che niente lo dicesse.
 *
 * Qui si verificano le due meta' della correzione: che i nomi nel modulo
 * siano distinti, e che il controller legga quello del provider scelto.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\LessonController;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

/**
 * I metodi sono privati: sono dettagli del controller, non una interfaccia.
 * Il test li raggiunge con la riflessione invece di aprirli al mondo solo
 * per essere collaudati.
 *
 * @param array<string, string> $post
 */
function chiama(string $metodo, array $post, mixed ...$argomenti): mixed
{
    $_POST = $post;

    $m = new ReflectionMethod(LessonController::class, $metodo);
    $m->setAccessible(true);

    return $m->invoke(new LessonController(), ...$argomenti);
}

echo PHP_EOL . '--- Nomi dei campi nel modulo' . PHP_EOL;

$modulo = (string) file_get_contents(__DIR__ . '/../app/Views/lessons/form.php');

preg_match_all('/name="(video_ref[a-z_]*)"/', $modulo, $trovati);
$nomi = $trovati[1];

check('i campi dell\'ID sono due', count($nomi) === 2);
check('hanno nomi diversi fra loro', count(array_unique($nomi)) === count($nomi));
check('nessuno si chiama piu\' «video_ref» nudo', !in_array('video_ref', $nomi, true));
check('c\'e\' il campo di Bunny', in_array('video_ref_bunny', $nomi, true));
check('c\'e\' il campo di Cloudflare', in_array('video_ref_cloudflare', $nomi, true));

echo PHP_EOL . '--- Lettura del riferimento' . PHP_EOL;

// Il caso del difetto: il modulo manda tutti e due i campi, quello di Bunny
// pieno e quello di Cloudflare vuoto perche' nascosto.
$comeArrivaDalModulo = [
    'video_provider' => 'bunny',
    'video_ref_bunny' => 'abcd1234-5678-90ef-ghij-klmnopqrstuv',
    'video_ref_cloudflare' => '',
];

check(
    'con provider bunny legge il campo di Bunny',
    chiama('externalVideoRefFromPost', $comeArrivaDalModulo, 'bunny') === 'abcd1234-5678-90ef-ghij-klmnopqrstuv'
);

check(
    'il campo vuoto dell\'altro provider non lo cancella',
    chiama('externalVideoRefFromPost', $comeArrivaDalModulo, 'bunny') !== null
);

check(
    'con provider cloudflare legge il campo di Cloudflare',
    chiama('externalVideoRefFromPost', [
        'video_provider' => 'cloudflare',
        'video_ref_bunny' => '',
        'video_ref_cloudflare' => 'cf-99',
    ], 'cloudflare') === 'cf-99'
);

check('lo spazio intorno all\'ID viene tolto', chiama('externalVideoRefFromPost', ['video_ref_bunny' => '  xyz  '], 'bunny') === 'xyz');
check('la casella vuota vale «nessun video»', chiama('externalVideoRefFromPost', ['video_ref_bunny' => '   '], 'bunny') === null);
check('la casella assente vale «nessun video»', chiama('externalVideoRefFromPost', [], 'bunny') === null);

echo PHP_EOL . '--- Il provider scelto' . PHP_EOL;

check('bunny passa', chiama('videoProviderFromPost', ['video_provider' => 'bunny']) === 'bunny');
check('cloudflare passa', chiama('videoProviderFromPost', ['video_provider' => 'cloudflare']) === 'cloudflare');
check('self_hosted passa', chiama('videoProviderFromPost', ['video_provider' => 'self_hosted']) === 'self_hosted');
check('un valore inventato ripiega su «nessuno»', chiama('videoProviderFromPost', ['video_provider' => 'youtube']) === 'none');
check('senza tendina vale «nessuno»', chiama('videoProviderFromPost', []) === 'none');

echo PHP_EOL . '--- Il riferimento quando si modifica una lezione' . PHP_EOL;

$eraSulServer = ['video_provider' => 'self_hosted', 'video_ref' => 'video-42.mp4'];
$eraSuBunny = ['video_provider' => 'bunny', 'video_ref' => 'vecchio-id'];

check(
    'passando a bunny prende l\'ID scritto adesso',
    chiama('resolveVideoRefForUpdate', ['video_ref_bunny' => 'nuovo-id'], $eraSuBunny, 'bunny') === 'nuovo-id'
);

check(
    'restando su self_hosted il file caricato resta',
    chiama('resolveVideoRefForUpdate', [], $eraSulServer, 'self_hosted') === 'video-42.mp4'
);

check(
    'passando a self_hosted da altro non si eredita un riferimento',
    chiama('resolveVideoRefForUpdate', [], $eraSuBunny, 'self_hosted') === null
);

check(
    'scegliendo «nessuno» il riferimento si azzera',
    chiama('resolveVideoRefForUpdate', ['video_ref_bunny' => 'nuovo-id'], $eraSuBunny, 'none') === null
);

echo PHP_EOL . '--- L\'avviso quando manca l\'ID' . PHP_EOL;

/** @param array<string, string> $post */
function avviso(array $post, string $provider): ?string
{
    $_SESSION = [];
    chiama('warnMissingVideoRef', $post, $provider);

    return $_SESSION['flash_error'] ?? null;
}

check('bunny senza ID avvisa', avviso(['video_ref_bunny' => ''], 'bunny') !== null);
check('cloudflare senza ID avvisa', avviso([], 'cloudflare') !== null);
check('bunny con ID non avvisa', avviso(['video_ref_bunny' => 'abc'], 'bunny') === null);
check('self_hosted non c\'entra e non avvisa', avviso([], 'self_hosted') === null);
check('«nessuno» non avvisa', avviso([], 'none') === null);

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
