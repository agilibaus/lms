<?php

declare(strict_types=1);

/**
 * La sorveglianza esterna (23/11): `App\Core\Sorveglianza` e i tre comandi
 * pianificati, contro un Healthchecks.io finto (`tests/aiuti/healthchecks_finto.php`).
 *
 * Esecuzione:  php tests/sorveglianza_test.php   (database di sviluppo acceso)
 *
 * Accende il servizio finto su 127.0.0.1:8125 e lo spegne alla fine;
 * rimette com'erano le due impostazioni della sorveglianza e toglie le
 * email di prova dalla coda.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\Settings;
use App\Core\Sorveglianza;
use App\Core\Url;

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

$porta = 8125;
$registro = sys_get_temp_dir() . '/hc_finto_prova.log';
$sconosciuti = sys_get_temp_dir() . '/hc_finto_prova_404.txt';
@unlink($registro);
file_put_contents($sconosciuti, '');
putenv("HC_FINTO_REGISTRO=$registro");
putenv("HC_FINTO_SCONOSCIUTI=$sconosciuti");
putenv("HEALTHCHECKS_BASE_URL=http://127.0.0.1:$porta");

$servizio = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$porta", __DIR__ . '/aiuti/healthchecks_finto.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $tubi
);
usleep(400000);

$db = Database::connection();
$prima = [];
foreach ([Sorveglianza::KEY, Sorveglianza::KEY_URL] as $k) {
    $prima[$k] = Settings::stored($k);
}

$chiamate = static function () use ($registro): array {
    return is_file($registro)
        ? array_map(static fn (string $r): array => json_decode($r, true), array_filter(explode("\n", (string) file_get_contents($registro))))
        : [];
};
$azzera = static function () use ($registro): void {
    @unlink($registro);
};
const CHIAVE = 'chiaveDiProvaSorveglianza1';

register_shutdown_function(static function () use ($servizio, $prima, $db, $registro, $sconosciuti): void {
    foreach ($prima as $k => $v) {
        $v === null
            ? $db->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$k])
            : $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$k, $v]);
    }
    $db->exec("DELETE FROM email_queue WHERE subject LIKE '[sorveglianza] %'");
    proc_terminate($servizio);
    @unlink($registro);
    @unlink($sconosciuti);
});

echo 'La chiave' . PHP_EOL;
check('una ping key va bene; un indirizzo intero, una chiave corta o con spazi no',
    Sorveglianza::chiaveValida(CHIAVE) && !Sorveglianza::chiaveValida('https://hc-ping.com/' . CHIAVE)
        && !Sorveglianza::chiaveValida('corta') && !Sorveglianza::chiaveValida('chiave con degli spazi dentro'));

echo PHP_EOL . 'Spenta, attiva, altrove' . PHP_EOL;
Sorveglianza::cancella(null);
$azzera();
check('senza chiave: spenta, e nessun segnale parte',
    Sorveglianza::stato() === Sorveglianza::SPENTA && !Sorveglianza::segnala(Sorveglianza::AVVISI) && $chiamate() === []);

Sorveglianza::salva(CHIAVE, null);
check('salvata dal pannello: attiva, per questo indirizzo',
    Sorveglianza::stato() === Sorveglianza::ATTIVA && Sorveglianza::salvataPer() === Url::base(), (string) Sorveglianza::salvataPer());

$azzera();
$arrivato = Sorveglianza::segnala(Sorveglianza::AVVISI);
$c = $chiamate();
check('«tutto bene»: una POST a <base>/<chiave>/pistacchio-avvisi, e il servizio risponde',
    $arrivato && count($c) === 1 && $c[0]['metodo'] === 'POST' && $c[0]['uri'] === '/' . CHIAVE . '/pistacchio-avvisi', json_encode($c));

$azzera();
Sorveglianza::segnala(Sorveglianza::INVITI, false, 'qualcosa');
$c = $chiamate();
check('«qualcosa non va»: lo stesso indirizzo con /fail, e il messaggio nel corpo',
    count($c) === 1 && $c[0]['uri'] === '/' . CHIAVE . '/pistacchio-inviti/fail' && $c[0]['corpo'] === 'qualcosa', json_encode($c));

Settings::set(Sorveglianza::KEY_URL, 'https://lms.movimente.it');
$azzera();
check('la chiave salvata per un altro indirizzo (un database copiato): altrove, e nessun segnale',
    Sorveglianza::stato() === Sorveglianza::ALTROVE && !Sorveglianza::segnala(Sorveglianza::AVVISI) && $chiamate() === []);

Sorveglianza::cancella(null);
putenv('HEALTHCHECKS_PING_KEY=' . CHIAVE);
$azzera();
check('la chiave scritta nel .env vale su qualunque indirizzo',
    Sorveglianza::stato() === Sorveglianza::ATTIVA && Sorveglianza::segnala(Sorveglianza::AVVISI) && count($chiamate()) === 1);
putenv('HEALTHCHECKS_PING_KEY');
Sorveglianza::salva(CHIAVE, null);

echo PHP_EOL . 'La posta' . PHP_EOL;
$base = Sorveglianza::emailFerme();
$riga = $db->prepare("INSERT INTO email_queue (to_email, subject, body, attempts, created_at, retry_until)
                      VALUES (?, ?, 'x', ?, NOW() - INTERVAL ? MINUTE, NOW() + INTERVAL ? HOUR)");
$riga->execute(['ferma@sorveglianza.test', '[sorveglianza] tentata, da due ore', 2, 120, 20]);
$riga->execute(['mai@sorveglianza.test', '[sorveglianza] mai tentata', 0, 120, 20]);
$riga->execute(['giovane@sorveglianza.test', '[sorveglianza] tentata, da mezz\'ora', 1, 30, 20]);
$riga->execute(['finita@sorveglianza.test', '[sorveglianza] non partita (tentativi finiti)', 9, 2000, -1]);
check('conta solo l\'email gia\' tentata, ancora in coda, nata da piu\' di un\'ora', Sorveglianza::emailFerme() === $base + 1,
    'prima ' . $base . ', ora ' . Sorveglianza::emailFerme());

$azzera();
Sorveglianza::segnalaPosta();
$c = $chiamate();
$attese = $base + 1;
check('con un\'email ferma: «qualcosa non va», con il numero e senza indirizzi',
    count($c) === 1 && $c[0]['uri'] === '/' . CHIAVE . '/pistacchio-posta/fail'
        && $c[0]['corpo'] === ($attese === 1 ? '1 email' : $attese . ' email') . ' in coda da più di un\'ora' && !str_contains($c[0]['corpo'], '@'),
    json_encode($c, JSON_UNESCAPED_UNICODE));

$db->exec("DELETE FROM email_queue WHERE subject LIKE '[sorveglianza] %'");
if ($base === 0) {
    $azzera();
    Sorveglianza::segnalaPosta();
    $c = $chiamate();
    check('senza email ferme: «tutto bene»', count($c) === 1 && $c[0]['uri'] === '/' . CHIAVE . '/pistacchio-posta', json_encode($c));
} else {
    echo "  --   nella coda di sviluppo ci sono gia' $base email ferme: la prova «tutto bene» qui non si fa" . PHP_EOL;
}

echo PHP_EOL . 'Il segnale di prova' . PHP_EOL;
file_put_contents($sconosciuti, "pistacchio-rilascio\n");
$esiti = Sorveglianza::prova();
check('un segnale per check; 404 dove un check con quel nome non c\'e\'',
    array_keys($esiti) === Sorveglianza::CHECK && $esiti['pistacchio-avvisi'] === 200 && $esiti['pistacchio-inviti'] === 200
        && $esiti['pistacchio-rilascio'] === 404, json_encode($esiti));
file_put_contents($sconosciuti, '');

echo PHP_EOL . 'Un servizio che non risponde non ferma il comando' . PHP_EOL;
putenv('HEALTHCHECKS_BASE_URL=http://127.0.0.1:8199');
$inizio = microtime(true);
$esito = Sorveglianza::segnala(Sorveglianza::AVVISI);
check('nessun servizio in ascolto: falso, nessuna eccezione, subito', $esito === false && microtime(true) - $inizio < 6);
putenv("HEALTHCHECKS_BASE_URL=http://127.0.0.1:$porta/lento");
$inizio = microtime(true);
$esito = Sorveglianza::segnala(Sorveglianza::AVVISI);
$tempo = microtime(true) - $inizio;
check('un servizio lentissimo: falso dopo al piu\' 5 secondi', $esito === false && $tempo < 6.5, sprintf('%.1f s', $tempo));
putenv("HEALTHCHECKS_BASE_URL=http://127.0.0.1:$porta");

echo PHP_EOL . 'I tre comandi segnalano a giro finito' . PHP_EOL;
$codice = [
    'bin/avvisi' => ['Sorveglianza::segnala(Sorveglianza::AVVISI)', 'Sorveglianza::segnalaPosta()'],
    'bin/invita-utenti' => ['Sorveglianza::segnala(Sorveglianza::INVITI)'],
    'bin/rilascio-moduli' => ['Sorveglianza::segnala(Sorveglianza::RILASCIO)'],
];
$mancano = [];
foreach ($codice as $file => $chiamate_attese) {
    $testo = (string) file_get_contents(__DIR__ . '/../' . $file);
    foreach ($chiamate_attese as $attesa) {
        if (!str_contains($testo, $attesa)) {
            $mancano[] = "$file: $attesa";
        }
    }
}
check('ogni comando chiama il suo check (avvisi anche la posta)', $mancano === [], implode('; ', $mancano));

// Un giro vero, del comando che non manda email quando non c'e' niente da
// mandare: gli inviti senza utenti in attesa.
$inAttesa = (int) $db->query('SELECT COUNT(*) FROM users WHERE invite_pending = 1')->fetchColumn();
if ($inAttesa === 0) {
    $ultimoGiro = Settings::stored('INVITES_LAST_RUN_AT');
    $azzera();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/invita-utenti') . ' 2>&1', $uscita, $codiceUscita);
    $c = $chiamate();
    check('bin/invita-utenti, a giro finito, manda «tutto bene» a pistacchio-inviti',
        $codiceUscita === 0 && count($c) === 1 && $c[0]['uri'] === '/' . CHIAVE . '/pistacchio-inviti', json_encode($c) . ' ' . implode(' ', $uscita));
    if ($ultimoGiro !== null) {
        $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'INVITES_LAST_RUN_AT'")->execute([$ultimoGiro]);
    }
} else {
    echo "  --   ci sono $inAttesa inviti in attesa: il giro vero di bin/invita-utenti qui non si fa" . PHP_EOL;
}

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
