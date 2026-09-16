<?php

declare(strict_types=1);

/**
 * Procedura di installazione guidata di Pistacchio LMS.
 *
 * Cinque passi: requisiti, database, amministratore, impostazioni, riepilogo.
 * Al termine scrive .env e storage/installed.lock; da quel momento questa
 * pagina si rifiuta di ripartire.
 */

// Marcatore usato dai file interni per rifiutare l'accesso diretto via browser,
// anche dove .htaccess non viene letto (server integrato di PHP, Nginx).
define('LMS_INSTALLER', true);

require __DIR__ . '/Installer.php';

session_start();

$projectRoot = dirname(__DIR__, 2);
$installer = new Installer($projectRoot);

// ---------------------------------------------------------------
// Auto-blocco
// ---------------------------------------------------------------
// Eccezione: chi ha appena completato l'installazione in questa sessione deve
// poter vedere il riepilogo finale, che il lock appena scritto bloccherebbe.
$justFinished = ($_GET['step'] ?? '') === 'done' && isset($_SESSION['install_env_written']);

if (!$justFinished && $installer->isInstalled()) {
    render('locked', ['installer' => $installer]);
    exit;
}

$step = $_GET['step'] ?? 'requirements';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$error = null;

// Token CSRF anche qui: i campi contengono credenziali del database.
if (empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(32));
}

if ($method === 'POST' && !hash_equals($_SESSION['install_token'], (string) ($_POST['_token'] ?? ''))) {
    http_response_code(419);
    exit('Sessione scaduta. Ricarica la pagina e riprova.');
}

$data = $_SESSION['install_data'] ?? [];

switch ($step) {
    // -----------------------------------------------------------
    case 'database':
        if ($method === 'POST') {
            $data['DB_HOST'] = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
            $data['DB_NAME'] = trim((string) ($_POST['db_name'] ?? ''));
            $data['DB_USER'] = trim((string) ($_POST['db_user'] ?? ''));
            $data['DB_PASS'] = (string) ($_POST['db_pass'] ?? '');
            $_SESSION['install_data'] = $data;

            $error = connectAndPrepare($installer, $data);

            if ($error === null) {
                redirect('admin');
            }
        }

        render('database', ['data' => $data, 'error' => $error, 'installer' => $installer]);
        break;

    // -----------------------------------------------------------
    case 'admin':
        requireDatabase($data);

        if ($method === 'POST') {
            $data['admin_name'] = trim((string) ($_POST['full_name'] ?? ''));
            $data['admin_email'] = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? '');

            if ($data['admin_name'] === '' || $data['admin_email'] === '') {
                $error = 'Nome ed email sono obbligatori.';
            } elseif (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Indirizzo email non valido.';
            } elseif (strlen($password) < Installer::MIN_PASSWORD) {
                $error = 'La password deve avere almeno ' . Installer::MIN_PASSWORD . ' caratteri.';
            } elseif ($password !== $confirm) {
                $error = 'Le due password non coincidono.';
            } else {
                $data['admin_password'] = $password;
            }

            $_SESSION['install_data'] = $data;

            if ($error === null) {
                redirect('settings');
            }
        }

        render('admin', ['data' => $data, 'error' => $error]);
        break;

    // -----------------------------------------------------------
    case 'settings':
        requireDatabase($data);

        if ($method === 'POST') {
            foreach ([
                'APP_URL' => 'app_url',
                'APP_TIMEZONE' => 'app_timezone',
                'BUNNY_LIBRARY_ID' => 'bunny_library_id',
                'CLOUDFLARE_STREAM_CUSTOMER_CODE' => 'cloudflare_customer_code',
                'GOOGLE_SERVICE_ACCOUNT_JSON' => 'google_json',
                'GOOGLE_IMPERSONATE_EMAIL' => 'google_impersonate',
                'GOOGLE_CALENDAR_ID' => 'google_calendar_id',
                'MAIL_TRANSPORT' => 'mail_transport',
                'MAIL_HOST' => 'mail_host',
                'MAIL_PORT' => 'mail_port',
                'MAIL_USERNAME' => 'mail_username',
                'MAIL_PASSWORD' => 'mail_password',
                'MAIL_ENCRYPTION' => 'mail_encryption',
                'MAIL_FROM_ADDRESS' => 'mail_from_address',
                'MAIL_FROM_NAME' => 'mail_from_name',
            ] as $envKey => $field) {
                $data[$envKey] = trim((string) ($_POST[$field] ?? ''));
            }

            $data['GOOGLE_CALENDAR_TIMEZONE'] = $data['APP_TIMEZONE'] ?: 'Europe/Rome';
            $data['APP_DEBUG'] = isset($_POST['app_debug']) ? '1' : '0';
            $_SESSION['install_data'] = $data;

            $error = finish($installer, $data);

            if ($error === null) {
                redirect('done');
            }
        }

        render('settings', ['data' => $data, 'error' => $error, 'guessedUrl' => guessBaseUrl()]);
        break;

    // -----------------------------------------------------------
    case 'done':
        render('done', [
            'envWritten' => $_SESSION['install_env_written'] ?? false,
            'envContents' => $_SESSION['install_env_contents'] ?? '',
            'baseUrl' => $_SESSION['install_data']['APP_URL'] ?? guessBaseUrl(),
        ]);

        // Da qui in poi i dati raccolti non servono più.
        unset($_SESSION['install_data']);
        break;

    // -----------------------------------------------------------
    default:
        $checks = $installer->requirements();

        render('requirements', [
            'checks' => $checks,
            'blocked' => $installer->hasBlockingProblems($checks),
        ]);
}

// ---------------------------------------------------------------
// Funzioni di supporto
// ---------------------------------------------------------------

/**
 * Verifica le credenziali, crea il database se manca e importa lo schema.
 *
 * @param array<string, string> $data
 * @return string|null messaggio d'errore, oppure null se è andata
 */
function connectAndPrepare(Installer $installer, array $data): ?string
{
    if (($data['DB_NAME'] ?? '') === '' || ($data['DB_USER'] ?? '') === '') {
        return 'Nome del database e utente sono obbligatori.';
    }

    try {
        try {
            $pdo = $installer->connect($data['DB_HOST'], $data['DB_NAME'], $data['DB_USER'], $data['DB_PASS']);
        } catch (\PDOException $e) {
            // 1049 = database inesistente: proviamo a crearlo con le stesse credenziali.
            if (!str_contains($e->getMessage(), '1049')) {
                throw $e;
            }

            $server = $installer->connectServer($data['DB_HOST'], $data['DB_USER'], $data['DB_PASS']);
            $installer->createDatabase($server, $data['DB_NAME']);
            $pdo = $installer->connect($data['DB_HOST'], $data['DB_NAME'], $data['DB_USER'], $data['DB_PASS']);
        }
    } catch (\PDOException $e) {
        return 'Connessione al database non riuscita: ' . friendlyPdoMessage($e);
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }

    if ($installer->hasUsers($pdo)) {
        return 'In questo database esiste già un\'installazione con utenti registrati: '
            . 'usa un database vuoto, oppure accedi con le credenziali esistenti.';
    }

    if (!$installer->isEmptyDatabase($pdo)) {
        return 'Il database contiene già delle tabelle. Per sicurezza la procedura si ferma: '
            . 'svuotalo o indicane uno nuovo.';
    }

    try {
        $installer->importSchema($pdo);
    } catch (\PDOException $e) {
        return 'Importazione dello schema non riuscita: ' . friendlyPdoMessage($e);
    }

    return null;
}

/**
 * Ultimo passo: crea l'amministratore, scrive .env e il file di lock.
 *
 * @param array<string, string> $data
 */
function finish(Installer $installer, array $data): ?string
{
    try {
        $pdo = $installer->connect($data['DB_HOST'], $data['DB_NAME'], $data['DB_USER'], $data['DB_PASS']);

        if (!$installer->hasUsers($pdo)) {
            $installer->createAdmin($pdo, $data['admin_email'], $data['admin_password'], $data['admin_name']);
        }
    } catch (\PDOException $e) {
        return 'Creazione dell\'amministratore non riuscita: ' . friendlyPdoMessage($e);
    }

    $contents = $installer->buildEnv($data);
    $written = $installer->canWriteEnv() && $installer->writeEnv($contents);

    $_SESSION['install_env_written'] = $written;
    $_SESSION['install_env_contents'] = $contents;

    // Il lock ha senso solo se la configurazione è stata scritta davvero:
    // altrimenti l'utente deve poter ripetere la procedura.
    if ($written) {
        $installer->writeLock();
    }

    return null;
}

/**
 * @param array<string, string> $data
 */
function requireDatabase(array $data): void
{
    if (($data['DB_NAME'] ?? '') === '') {
        redirect('database');
    }
}

function friendlyPdoMessage(\PDOException $e): string
{
    $message = $e->getMessage();

    if (str_contains($message, '1045')) {
        return 'utente o password non corretti.';
    }

    if (str_contains($message, '2002') || str_contains($message, '1049')) {
        return 'server o database non raggiungibili all\'indirizzo indicato.';
    }

    return $message;
}

function guessBaseUrl(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return ($https ? 'https://' : 'http://') . $host;
}

function redirect(string $step): never
{
    header('Location: ?step=' . $step);
    exit;
}

/**
 * @param array<string, mixed> $vars
 */
function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $token = $_SESSION['install_token'] ?? '';

    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();

    require __DIR__ . '/views/layout.php';
}
