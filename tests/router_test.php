<?php

declare(strict_types=1);

/**
 * Test del Router (16/10), senza database e senza server: il testo fisso di
 * una rotta vale solo per se stesso, e ogni rotta di app/routes.php trova
 * ancora il proprio indirizzo.
 *
 * Esecuzione:  php tests/router_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Router;

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

$aRegex = new ReflectionMethod(Router::class, 'toRegex');
$regex = static fn (string $modello): string => (string) $aRegex->invoke(new Router(), $modello);

/** @return array<string, string>|null i parametri, o null se l'indirizzo non corrisponde */
$parametri = static function (string $modello, string $indirizzo) use ($regex): ?array {
    if (preg_match($regex($modello), $indirizzo, $m) !== 1) {
        return null;
    }

    return array_filter($m, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
};

// ---------------------------------------------------------------
echo 'Il testo fisso vale solo per se stesso' . PHP_EOL;

$cal = '/calendario/{token}.ics';
check('«/calendario/abc.ics» risponde, con token «abc»', $parametri($cal, '/calendario/abc.ics') === ['token' => 'abc']);

foreach (['/calendario/abcXics', '/calendario/abc-ics', '/calendario/abc/ics', '/calendario/abc.icsx', '/calendario/.ics'] as $sbagliato) {
    check('«' . $sbagliato . '» non risponde', $parametri($cal, $sbagliato) === null);
}

check('«/agenda/evento/5.ics» risponde, con id «5»', $parametri('/agenda/evento/{id}.ics', '/agenda/evento/5.ics') === ['id' => '5']);
check('«/agenda/evento/5Xics» non risponde', $parametri('/agenda/evento/{id}.ics', '/agenda/evento/5Xics') === null);

foreach (['/a+b/{x}', '/a(b)/{x}', '/a*b/{x}', '/a?b/{x}', '/a|b/{x}', '/a#b/{x}', '/a$b/{x}', '/a[b]/{x}'] as $modello) {
    $vero = str_replace('{x}', '1', $modello);
    check('«' . $modello . '» risponde a «' . $vero . '» e a nient\'altro',
        $parametri($modello, $vero) === ['x' => '1']
        && $parametri($modello, str_replace(['+', '(', ')', '*', '?', '|', '#', '$', '[', ']'], 'Z', $vero)) === null);
}

check('un segnaposto non attraversa una barra', $parametri('/corsi/{id}', '/corsi/1/2') === null);
check('due segnaposti nella stessa rotta', $parametri('/admin/courses/{id}/enrollments/{userId}/delete', '/admin/courses/3/enrollments/7/delete') === ['id' => '3', 'userId' => '7']);

// ---------------------------------------------------------------
echo PHP_EOL . 'Ogni rotta di app/routes.php trova il proprio indirizzo' . PHP_EOL;

// routes.php chiede solo get() e post() a $router: qui un registratore li
// raccoglie con il loro modello, che il Router vero non conserva.
$router = new class {
    /** @var list<array{string, string}> */
    public array $rotte = [];

    public function get(string $modello, array $gestore): void
    {
        $this->rotte[] = ['GET', $modello];
    }

    public function post(string $modello, array $gestore): void
    {
        $this->rotte[] = ['POST', $modello];
    }
};

require __DIR__ . '/../app/routes.php';

$mancate = [];

foreach ($router->rotte as [$metodo, $modello]) {
    preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $modello, $nomi);
    $atteso = [];
    $indirizzo = $modello;

    foreach ($nomi[1] as $n => $nome) {
        $valore = 'v' . $n;
        $atteso[$nome] = $valore;
        $indirizzo = str_replace('{' . $nome . '}', $valore, $indirizzo);
    }

    if ($parametri($modello, $indirizzo) !== $atteso) {
        $mancate[] = $metodo . ' ' . $modello;
    }
}

check('le rotte sono state lette (' . count($router->rotte) . ')', count($router->rotte) > 100);
check('ognuna risponde al proprio indirizzo, con i parametri giusti', $mancate === [], implode(', ', $mancate));

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
