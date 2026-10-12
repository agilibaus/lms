<?php

declare(strict_types=1);

/**
 * Test delle email della piattaforma (12/10): l'elenco in `Modelli`, i
 * segnaposto, la chiusura comune, il nome fisso della piattaforma, e il nome
 * dell'organizzazione che nel codice non deve comparire.
 *
 * Esecuzione:  php tests/email_modelli_test.php
 *
 * La prima parte non tocca il database. La seconda legge e scrive `settings`
 * (rimettendo tutto com'era) e, se il database non e' raggiungibile, viene
 * saltata dicendolo.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use App\Core\Mail\Modelli;
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

// ---------------------------------------------------------------
echo 'L\'elenco' . PHP_EOL;

$elenco = Modelli::elenco();
$chiaviSettings = [];

foreach ($elenco as $chiave => $voce) {
    $ammessi = Modelli::ammessi($chiave);
    [$oggetto, $testo] = $voce['predefinito'];

    check($chiave . ': il gruppo esiste', isset(Modelli::GRUPPI[$voce['gruppo']]));
    check($chiave . ': titolo e «quando» scritti', trim($voce['titolo']) !== '' && trim($voce['quando']) !== '');
    check($chiave . ': i segnaposto obbligatori sono fra i suoi',
        array_diff($voce['obbligatori'], array_keys($voce['segnaposto'])) === []);

    $controllo = Modelli::controlla($oggetto . "\n" . $testo, $ammessi, $voce['obbligatori']);
    check($chiave . ': il testo predefinito ha gli obbligatori e solo segnaposto ammessi',
        $controllo === ['mancanti' => [], 'sconosciuti' => []], json_encode($controllo, JSON_UNESCAPED_UNICODE));
    check($chiave . ': il testo predefinito sta nel limite', mb_strlen($testo) <= Modelli::MAX_TESTO);
    check($chiave . ': l\'esempio della prova copre tutti i suoi segnaposto',
        array_diff(array_keys($voce['segnaposto']), array_keys($voce['esempio'])) === []);

    $chiaviSettings[] = $voce['oggetto'];
    $chiaviSettings[] = $voce['testo'];
}

check('le chiavi di settings sono tutte diverse', count($chiaviSettings) === count(array_unique($chiaviSettings)));
check('i testi degli incontri restano sulle chiavi di prima',
    array_diff(Settings::LIVE_MAIL_KEYS, Modelli::chiaviSettings()) === []);
check('la chiusura predefinita sta nel suo limite', mb_strlen(Modelli::CHIUSURA_PREDEFINITA) <= Modelli::MAX_CHIUSURA);
check('la chiusura predefinita usa solo segnaposto comuni',
    Modelli::controlla(Modelli::CHIUSURA_PREDEFINITA, array_keys(Modelli::segnapostoComuni()), [])['sconosciuti'] === []);
check('il pannello puo\' scrivere le chiavi delle email', array_filter(
    Modelli::chiaviSettings(),
    static fn (string $k): bool => !Settings::isWritable($k)
) === []);

// ---------------------------------------------------------------
echo PHP_EOL . 'I segnaposto' . PHP_EOL;

check('si sostituiscono', Modelli::rendi('Ciao {nome}, {corso}', ['{nome}' => 'Anna', '{corso}' => 'Yoga']) === "Ciao Anna, Yoga\n");
check('uno sconosciuto resta scritto com\'e\'', str_contains(Modelli::rendi('Ciao {nmoe}', ['{nome}' => 'Anna']), '{nmoe}'));
check('un segnaposto vuoto non lascia tre righe bianche di fila',
    Modelli::rendi("Prima\n\n{messaggio}\n\nDopo", ['{messaggio}' => '']) === "Prima\n\nDopo\n");
check('gli a capo dal browser valgono come gli altri', Modelli::rendi("a\r\nb", []) === "a\nb\n");

$controllo = Modelli::controlla('Entra da {link} {nomee}', ['{link}', '{password}', '{nome}'], ['{link}', '{password}']);
check('un obbligatorio che manca si trova', $controllo['mancanti'] === ['{password}']);
check('uno sconosciuto si trova', $controllo['sconosciuti'] === ['{nomee}']);
check('un testo vuoto va sempre bene: vale il predefinito',
    Modelli::controlla('  ', ['{link}'], ['{link}']) === ['mancanti' => [], 'sconosciuti' => []]);
check('i comuni sono ammessi in ogni email', array_diff(
    array_keys(Modelli::segnapostoComuni()),
    Modelli::ammessi('verifica')
) === []);

// ---------------------------------------------------------------
echo PHP_EOL . 'Il nome della piattaforma e quello dell\'organizzazione' . PHP_EOL;

check('la piattaforma si chiama «Pistacchio LMS»', Modelli::PIATTAFORMA === 'Pistacchio LMS');

// Il nome dell'organizzazione che usa oggi la piattaforma non deve stare in
// nessun file del progetto (12/10, Alessandro): ogni organizzazione lo
// imposta dal pannello. Composto qui a pezzi, se no il test trova se stesso.
$vietato = 'movi' . 'mente';
$radice = dirname(__DIR__);
$trovati = [];
$cartelle = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), ['.git', 'vendor', 'storage', 'node_modules'], true))
));

foreach ($cartelle as $file) {
    if (!$file->isFile() || $file->getSize() > 2_000_000) {
        continue;
    }

    $contenuto = (string) file_get_contents($file->getPathname());

    // I file binari (immagini, caratteri) non contano.
    if (str_contains($contenuto, "\0")) {
        continue;
    }

    if (stripos($contenuto, $vietato) !== false) {
        $trovati[] = substr($file->getPathname(), strlen($radice) + 1);
    }
}

check('il nome dell\'organizzazione non e\' scritto in nessun file', $trovati === [], implode(', ', $trovati));

// ---------------------------------------------------------------
echo PHP_EOL . 'Rispondi a' . PHP_EOL;

$messaggio = new Message('a@b.it', 'Anna', 'Oggetto', "Testo\n");
check('senza indirizzo per le risposte, niente Reply-To', !str_contains($messaggio->toRfc822('no-reply@x.it', 'X'), 'Reply-To'));
$messaggio->replyTo = 'segreteria@x.it';
check('con l\'indirizzo, Reply-To', str_contains($messaggio->toRfc822('no-reply@x.it', 'X'), "\r\nReply-To: <segreteria@x.it>\r\n"));
$messaggio->replyTo = "x@y.it\r\nBcc: z@w.it";
check('un indirizzo con un a capo non aggiunge intestazioni', !str_contains($messaggio->toRfc822('no-reply@x.it', 'X'), 'Bcc'));

// ---------------------------------------------------------------
echo PHP_EOL . 'Le email composte' . PHP_EOL;

$database = true;

try {
    new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_NAME', 'lms')),
        Env::get('DB_USER', 'root'),
        Env::get('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    $database = false;
}

if (!$database) {
    echo '  (saltati: database non raggiungibile)' . PHP_EOL;
} else {
    // I valori salvati su questa installazione si tolgono di mezzo e si
    // rimettono a posto alla fine.
    $toccate = array_merge(Modelli::chiaviSettings(), ['MAIL_FROM_NAME']);
    $salvati = [];

    foreach ($toccate as $chiave) {
        $salvati[$chiave] = Settings::stored($chiave);
        Settings::set($chiave, null);
    }

    $ripristina = static function () use ($salvati): void {
        foreach ($salvati as $chiave => $valore) {
            Settings::set($chiave, $valore);
        }
    };

    register_shutdown_function($ripristina);

    Settings::set(Modelli::ORGANIZZAZIONE, 'Associazione di prova');
    Settings::set('MAIL_FROM_NAME', 'Un altro nome');

    $accesso = Mailer::invite('nessuno@esempio.invalid', 'Giulia Verdi', 'Prova2345678', 'https://x.invalid/login');
    check('«Il tuo accesso»: la password e il link ci sono',
        str_contains($accesso->body, 'Prova2345678') && str_contains($accesso->body, 'https://x.invalid/login'));
    check('e non parla di una password precedente', !str_contains($accesso->body, 'precedente'));
    check('il nome dell\'organizzazione viene dal pannello', str_contains($accesso->body, 'Associazione di prova ha creato'));
    check('{piattaforma} e\' «Pistacchio LMS» anche con un altro nome del mittente',
        str_contains($accesso->subject, 'Pistacchio LMS') && !str_contains($accesso->body, 'Un altro nome'));
    check('senza account, {nome} e\' il nome passato', str_starts_with($accesso->body, "Ciao Giulia Verdi,\n"));
    check('non restano segnaposto', preg_match('/\{[a-z_]+\}/', $accesso->body . $accesso->subject) === 0, $accesso->body);
    check('in fondo c\'e\' la chiusura predefinita', str_contains($accesso->body, "A presto,\nAssociazione di prova\n"));
    check('il nome del mittente e\' quello impostato', Modelli::nomeMittente() === 'Un altro nome');

    $email = (string) Database::connection()->query("SELECT email FROM users WHERE first_name <> '' AND full_name LIKE CONCAT(first_name, ' %') LIMIT 1")->fetchColumn();

    if ($email !== '') {
        $nome = (string) Database::connection()->query('SELECT first_name FROM users WHERE email = ' . Database::connection()->quote($email))->fetchColumn();
        $avviso = Mailer::passwordChanged($email, 'Nome Cognome', 'https://x.invalid/login');
        check('con un account, {nome} e\' il solo nome di battesimo', str_starts_with($avviso->body, 'Ciao ' . $nome . ",\n"), $avviso->body);
    }

    Settings::set(Modelli::RISPONDI_A, 'segreteria@esempio.invalid');
    Settings::set(Modelli::CHIUSURA, "Grazie,\n{organizzazione} — {contatto}");
    Settings::set('EMAIL_RIFIUTATA_OGGETTO', 'Su misura: {corso}');
    Settings::set('EMAIL_RIFIUTATA_TESTO', "Ciao {nome}, niente da fare per «{corso}».");

    $rifiuto = Mailer::enrollmentRejected('nessuno@esempio.invalid', 'Giulia', 'Yoga');
    check('un oggetto salvato dal pannello ha la precedenza', $rifiuto->subject === 'Su misura: Yoga');
    check('anche il testo', str_starts_with($rifiuto->body, "Ciao Giulia, niente da fare per «Yoga».\n"));
    check('la chiusura salvata va in fondo', str_ends_with($rifiuto->body, "\nGrazie,\nAssociazione di prova — segreteria@esempio.invalid\n"), $rifiuto->body);
    check('le risposte vanno all\'indirizzo «Rispondi a»', $rifiuto->replyTo === 'segreteria@esempio.invalid');

    Settings::set('EMAIL_RIFIUTATA_TESTO', '');
    check('svuotato, torna il testo predefinito',
        str_contains(Mailer::enrollmentRejected('nessuno@esempio.invalid', 'Giulia', 'Yoga')->body, 'non è stata accolta'));

    $conMessaggio = Mailer::enrollmentRequested('nessuno@esempio.invalid', 'Tutor', 'Anna Rossi', 'Yoga', 'https://x.invalid', 'Vorrei venire.');
    check('la richiesta riporta quello che lo studente ha scritto', str_contains($conMessaggio->body, 'Ha scritto: «Vorrei venire.»'));
    $senza = Mailer::enrollmentRequested('nessuno@esempio.invalid', 'Tutor', 'Anna Rossi', 'Yoga', 'https://x.invalid');
    check('senza messaggio non resta una riga vuota in piu\'', !str_contains($senza->body, "\n\n\n") && !str_contains($senza->body, 'Ha scritto'));

    $prova = Modelli::prova('accesso', 'nessuno@esempio.invalid', 'Admin', 'Oggetto {email}', '');
    check('la prova usa il testo dei campi, anche non salvato', $prova->subject === '[Prova] Oggetto nome@esempio.invalid');
    check('e con il testo vuoto il predefinito, con i valori di esempio', str_contains($prova->body, 'Password provvisoria: Esempio2345678'));

    $ripristina();
}

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
