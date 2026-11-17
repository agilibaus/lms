<?php

declare(strict_types=1);

/**
 * Test di dove si cerca la chiave di Google (17/11): `App\Core\Google\ChiaveGoogle`.
 *
 * Esecuzione:  php tests/chiave_google_test.php   (database di sviluppo acceso)
 *
 * La seconda parte scrive l'impostazione in tabella e la rimette com'era.
 * Il caricamento dal pannello si prova in `tests/chiave_google.js`.
 *
 * COSA VERIFICA:
 *
 *   1. **Dal valore in tabella conta solo il nome del file**, dentro
 *      `storage/google`: con un percorso di Windows, di Linux o il nome da
 *      solo. Un nome che non e' un JSON semplice non vale niente.
 *   2. **La chiave gia' caricata, con il percorso completo del PC di
 *      sviluppo, funziona ancora**: il client di Google si costruisce.
 *   3. **Un percorso in tabella che porta fuori da `storage/google` non si
 *      legge**, anche se il file esiste.
 *   4. **Il `.env`** resta un percorso, com'e' scritto, quando la tabella e'
 *      vuota.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\Google\ChiaveGoogle;
use App\Core\Google\ServiceAccountClient;
use App\Core\Settings;

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

echo "1. Solo il nome del file\n";

$cartella = '/sito/storage/google';
$casi = [
    'C:\\laragon\\www\\lms\\app\\Controllers\\Admin/../../../storage/google/e9e50ced.json' => $cartella . '/e9e50ced.json',
    // Tutto con le barre rovesciate: su Linux `basename` da solo non le divide.
    'C:\\laragon\\www\\lms\\storage\\google\\f00d.json' => $cartella . '/f00d.json',
    '/var/www/vecchio/storage/google/abc_1-2.json' => $cartella . '/abc_1-2.json',
    'e9e50ced.json' => $cartella . '/e9e50ced.json',
    '../../config/segreti.json' => $cartella . '/segreti.json',
    'chiave.php' => '',
    '.json' => '',
    'chiave con spazi.json' => '',
    '' => '',
];

foreach ($casi as $valore => $atteso) {
    $detto = ChiaveGoogle::nellaCartella($valore, $cartella);
    check('«' . $valore . '» → «' . $atteso . '»', $detto === $atteso, $detto);
}

check('il nome da salvare per un file caricato', ChiaveGoogle::nome('/sito/storage/google/abc.json') === 'abc.json');

// --- con il database --------------------------------------------------------

$pdo = Database::connection();
$prima = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'GOOGLE_SERVICE_ACCOUNT_JSON'")->fetchColumn();
$nome = 'prova-chiave-' . bin2hex(random_bytes(4)) . '.json';
$file = ChiaveGoogle::cartella() . '/' . $nome;
$fuori = sys_get_temp_dir() . '/' . $nome;
$json = json_encode(['client_email' => 'prova@progetto.iam.gserviceaccount.com', 'private_key' => 'non-serve-per-la-prova']);
file_put_contents($file, $json);
file_put_contents($fuori, $json);

try {
    echo "\n2. La chiave gia' caricata\n";

    Settings::set('GOOGLE_SERVICE_ACCOUNT_JSON', 'C:\\laragon\\www\\lms\\app\\Controllers\\Admin/../../../storage/google/' . $nome, null);
    check('il percorso di Windows porta al file in storage/google', ChiaveGoogle::percorso() === $file, ChiaveGoogle::percorso());
    check('e il client di Google si costruisce', ServiceAccountClient::fromEnv() !== null);
    check('il file e\' nostro: si puo\' cancellare dal pannello', ChiaveGoogle::eNostra($file));

    echo "\n3. Fuori da storage/google no\n";

    Settings::set('GOOGLE_SERVICE_ACCOUNT_JSON', $fuori, null);
    @unlink($file);
    check('un percorso fuori, con il file che esiste: non si legge', ServiceAccountClient::fromEnv() === null && ChiaveGoogle::percorso() === $file);
    check('il file fuori non e\' nostro: il pannello non lo cancella', !ChiaveGoogle::eNostra($fuori));

    echo "\n4. Il .env\n";

    Settings::set('GOOGLE_SERVICE_ACCOUNT_JSON', null, null);
    // Il .env letto all'avvio sta in `Env::$vars`: lo si cambia solo per
    // questo processo, il file non si tocca.
    $vars = new ReflectionProperty(App\Core\Env::class, 'vars');
    $vars->setValue(null, ['GOOGLE_SERVICE_ACCOUNT_JSON' => $fuori] + $vars->getValue());
    check('tabella vuota: il percorso del .env, com\'e\' scritto', ChiaveGoogle::percorso() === $fuori, ChiaveGoogle::percorso());
    check('e il client si costruisce', ServiceAccountClient::fromEnv() !== null);
} finally {
    $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['GOOGLE_SERVICE_ACCOUNT_JSON']);

    if ($prima !== false) {
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute(['GOOGLE_SERVICE_ACCOUNT_JSON', $prima]);
    }

    @unlink($file);
    @unlink($fuori);
}

$dopo = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'GOOGLE_SERVICE_ACCOUNT_JSON'")->fetchColumn();
check('l\'impostazione e\' tornata com\'era', $dopo === $prima);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
