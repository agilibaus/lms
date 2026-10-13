<?php

declare(strict_types=1);

/**
 * Test dell'accesso protetto (13/10, verifica di sicurezza):
 *
 *   - il limite ai tentativi (`App\Core\TentativiAccesso`): dieci password
 *     sbagliate per indirizzo, cinquanta per IP, nel quarto d'ora; il blocco
 *     finisce da solo e chi entra azzera i suoi errori;
 *   - il cookie di sessione (`App\Core\Sessione`): HttpOnly e SameSite
 *     sempre, Secure in https.
 *
 * Esecuzione:  php tests/accesso_test.php
 *
 * La prima parte scrive nel database del .env (quello di sviluppo): righe
 * con indirizzi e IP finti, cancellate alla fine.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\Sessione;
use App\Core\TentativiAccesso;

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

// ---------------------------------------------------------------
echo 'Il cookie di sessione' . PHP_EOL;

$http = Sessione::parametri(false);
$https = Sessione::parametri(true);
check('HttpOnly sempre', $http['httponly'] === true && $https['httponly'] === true);
check('SameSite=Lax sempre', $http['samesite'] === 'Lax' && $https['samesite'] === 'Lax');
check('Secure solo in https', $http['secure'] === false && $https['secure'] === true);
check('dura quanto il browser, su tutto il sito', $http['lifetime'] === 0 && $http['path'] === '/');

// ---------------------------------------------------------------
echo PHP_EOL . 'Il limite ai tentativi' . PHP_EOL;

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo '  (saltati: database non raggiungibile)' . PHP_EOL;
    echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
    exit($fail > 0 ? 1 : 0);
}

const IP_A = '203.0.113.7';
const IP_B = '203.0.113.8';
const IP_C = '203.0.113.9';
$pulisci = static function () use ($pdo): void {
    $pdo->prepare("DELETE FROM login_failures WHERE ip LIKE '203.0.113.%' OR email LIKE '%@prova-accesso.invalid'")->execute();
};
$pulisci();

try {
    $chi = 'Anna@Prova-Accesso.invalid';

    for ($i = 1; $i < TentativiAccesso::MAX_PER_INDIRIZZO; $i++) {
        TentativiAccesso::registraErrore($chi, IP_A);
    }
    check('nove password sbagliate: si puo\' ancora provare', TentativiAccesso::minutiDiBlocco($chi, IP_A) === null);

    TentativiAccesso::registraErrore($chi, IP_A);
    $minuti = TentativiAccesso::minutiDiBlocco($chi, IP_A);
    check('alla decima l\'indirizzo e\' fermato per un quarto d\'ora', $minuti === 15, var_export($minuti, true));
    check('anche da un altro IP', TentativiAccesso::minutiDiBlocco($chi, IP_B) === 15);
    check('maiuscole e spazi non cambiano l\'indirizzo', TentativiAccesso::minutiDiBlocco('  anna@prova-accesso.INVALID ', IP_B) === 15);
    check('un altro indirizzo dallo stesso IP no', TentativiAccesso::minutiDiBlocco('bruno@prova-accesso.invalid', IP_A) === null);

    // Il blocco finisce quando il primo errore della finestra esce dal quarto
    // d'ora: si invecchiano le righe invece di aspettare.
    $pdo->prepare("UPDATE login_failures SET created_at = NOW() - INTERVAL 14 MINUTE WHERE email = 'anna@prova-accesso.invalid'")->execute();
    check('dopo 14 minuti manca 1 minuto', TentativiAccesso::minutiDiBlocco($chi, IP_A) === 1);
    $pdo->prepare("UPDATE login_failures SET created_at = NOW() - INTERVAL 16 MINUTE WHERE email = 'anna@prova-accesso.invalid'")->execute();
    check('dopo 16 minuti il blocco e\' finito', TentativiAccesso::minutiDiBlocco($chi, IP_A) === null);

    TentativiAccesso::registraErrore($chi, IP_A);
    TentativiAccesso::azzera($chi);
    $resta = (int) $pdo->query("SELECT COUNT(*) FROM login_failures WHERE email = 'anna@prova-accesso.invalid'")->fetchColumn();
    check('chi entra azzera i suoi errori', $resta === 0, $resta . ' righe');

    for ($i = 0; $i < TentativiAccesso::MAX_PER_IP; $i++) {
        TentativiAccesso::registraErrore('persona' . $i . '@prova-accesso.invalid', IP_C);
    }
    check('cinquanta indirizzi diversi dallo stesso IP: l\'IP e\' fermato',
        TentativiAccesso::minutiDiBlocco('nuovo@prova-accesso.invalid', IP_C) === 15);
    check('chi arriva da un altro IP no', TentativiAccesso::minutiDiBlocco('nuovo@prova-accesso.invalid', IP_B) === null);

    $pdo->prepare("INSERT INTO login_failures (email, ip, created_at) VALUES ('vecchio@prova-accesso.invalid', :ip, NOW() - INTERVAL 2 DAY)")
        ->execute(['ip' => IP_B]);
    TentativiAccesso::registraErrore('altro@prova-accesso.invalid', IP_B);
    $vecchie = (int) $pdo->query("SELECT COUNT(*) FROM login_failures WHERE email = 'vecchio@prova-accesso.invalid'")->fetchColumn();
    check('le righe di piu\' di un giorno si tolgono da sole', $vecchie === 0);

    check('la frase dice quanto aspettare', str_contains(TentativiAccesso::messaggio(1), 'fra 1 minuto,')
        && str_contains(TentativiAccesso::messaggio(15), 'fra 15 minuti,'));
} finally {
    $pulisci();
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
