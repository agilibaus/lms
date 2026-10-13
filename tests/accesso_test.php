<?php

declare(strict_types=1);

/**
 * Test dell'accesso protetto (13/10, verifica di sicurezza):
 *
 *   - il limite ai tentativi (`App\Core\TentativiAccesso`): cinque password
 *     sbagliate per indirizzo nell'ora bloccano l'account per un'ora, con
 *     l'avviso dei tentativi che restano dalla terza; cinquanta per IP nel
 *     quarto d'ora fermano l'IP; il blocco finisce da solo e chi entra
 *     azzera i suoi errori;
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
    $err = static function () use ($chi): string {
        TentativiAccesso::registraErrore($chi, IP_A);

        return TentativiAccesso::avvisoErrore($chi, IP_A);
    };

    check('prima password sbagliata: l\'avviso di sempre', $err() === 'Email o password non corretti.');
    check('seconda: ancora l\'avviso di sempre', $err() === 'Email o password non corretti.');
    $terza = $err();
    check('terza: restano 2 tentativi', str_contains($terza, 'Ancora 2 tentativi, poi l’account si blocca per un’ora.'), $terza);
    $quarta = $err();
    check('quarta: resta 1 tentativo', str_contains($quarta, 'Ancora 1 tentativo,'), $quarta);
    check('fin qui si puo\' ancora provare', TentativiAccesso::blocco($chi, IP_A) === null);

    $quinta = $err();
    check('quinta: l\'avviso dice che l\'account e\' bloccato per un\'ora',
        str_contains($quinta, 'l’account è bloccato per un’ora') && str_contains($quinta, 'fra 60 minuti'), $quinta);
    $blocco = TentativiAccesso::blocco($chi, IP_A);
    check('l\'account e\' bloccato per un\'ora', $blocco === ['motivo' => TentativiAccesso::ACCOUNT, 'minuti' => 60],
        var_export($blocco, true));
    check('anche da un altro IP', (TentativiAccesso::blocco($chi, IP_B)['minuti'] ?? null) === 60);
    check('maiuscole e spazi non cambiano l\'indirizzo', (TentativiAccesso::blocco('  anna@prova-accesso.INVALID ', IP_B)['minuti'] ?? null) === 60);
    check('un altro indirizzo dallo stesso IP no', TentativiAccesso::blocco('bruno@prova-accesso.invalid', IP_A) === null);

    // Il blocco dura un'ora dalla quinta: si invecchiano le righe invece di
    // aspettare.
    $pdo->prepare("UPDATE login_failures SET created_at = created_at - INTERVAL 59 MINUTE WHERE email = 'anna@prova-accesso.invalid'")->execute();
    check('dopo 59 minuti manca 1 minuto', (TentativiAccesso::blocco($chi, IP_A)['minuti'] ?? null) === 1);
    $pdo->prepare("UPDATE login_failures SET created_at = created_at - INTERVAL 2 MINUTE WHERE email = 'anna@prova-accesso.invalid'")->execute();
    check('dopo un\'ora il blocco e\' finito', TentativiAccesso::blocco($chi, IP_A) === null);
    check('e si riparte da zero', TentativiAccesso::restano($chi) === 5 && $err() === 'Email o password non corretti.');

    // Cinque errori sparsi nell'ora bloccano lo stesso; quelli di piu' di
    // un'ora fa non contano.
    TentativiAccesso::azzera($chi);
    $pdo->prepare("INSERT INTO login_failures (email, ip, created_at) VALUES ('anna@prova-accesso.invalid', :ip, NOW() - INTERVAL 70 MINUTE)")
        ->execute(['ip' => IP_A]);
    for ($i = 0; $i < 4; $i++) {
        $pdo->prepare("INSERT INTO login_failures (email, ip, created_at) VALUES ('anna@prova-accesso.invalid', :ip, NOW() - INTERVAL :m MINUTE)")
            ->execute(['ip' => IP_A, 'm' => 50 - $i * 10]);
    }
    check('quattro errori nell\'ultima ora e uno piu\' vecchio: nessun blocco, resta 1 tentativo',
        TentativiAccesso::blocco($chi, IP_A) === null && TentativiAccesso::restano($chi) === 1);
    $err();
    check('il quinto nell\'ora blocca, per un\'ora da adesso', (TentativiAccesso::blocco($chi, IP_A)['minuti'] ?? null) === 60);

    TentativiAccesso::azzera($chi);
    $resta = (int) $pdo->query("SELECT COUNT(*) FROM login_failures WHERE email = 'anna@prova-accesso.invalid'")->fetchColumn();
    check('azzerare toglie gli errori e il blocco', $resta === 0 && TentativiAccesso::blocco($chi, IP_A) === null, $resta . ' righe');

    for ($i = 0; $i < TentativiAccesso::MAX_PER_IP; $i++) {
        TentativiAccesso::registraErrore('persona' . $i . '@prova-accesso.invalid', IP_C);
    }
    $daIp = TentativiAccesso::blocco('nuovo@prova-accesso.invalid', IP_C);
    check('cinquanta indirizzi diversi dallo stesso IP: l\'IP e\' fermato per un quarto d\'ora',
        $daIp === ['motivo' => TentativiAccesso::IP, 'minuti' => 15], var_export($daIp, true));
    check('con la sua frase', str_contains(TentativiAccesso::messaggio($daIp ?? ['motivo' => 'ip', 'minuti' => 15]), 'da questa connessione'));
    check('chi arriva da un altro IP no', TentativiAccesso::blocco('nuovo@prova-accesso.invalid', IP_B) === null);

    $pdo->prepare("INSERT INTO login_failures (email, ip, created_at) VALUES ('vecchio@prova-accesso.invalid', :ip, NOW() - INTERVAL 2 DAY)")
        ->execute(['ip' => IP_B]);
    TentativiAccesso::registraErrore('altro@prova-accesso.invalid', IP_B);
    $vecchie = (int) $pdo->query("SELECT COUNT(*) FROM login_failures WHERE email = 'vecchio@prova-accesso.invalid'")->fetchColumn();
    check('le righe di piu\' di un giorno si tolgono da sole', $vecchie === 0);

    check('la frase dice quanto aspettare', str_contains(TentativiAccesso::messaggio(['motivo' => 'account', 'minuti' => 1]), 'fra 1 minuto,')
        && str_contains(TentativiAccesso::messaggio(['motivo' => 'account', 'minuti' => 42]), 'fra 42 minuti,'));
} finally {
    $pulisci();
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
