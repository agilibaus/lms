<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Google\GoogleException;
use App\Core\Google\MeetCalendar;
use App\Core\Google\ServiceAccountClient;
use App\Core\Mail\LiveSessionMail;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use App\Core\Settings;
use App\Core\View;

/**
 * Configurazione di posta elettronica e Google Meet dal pannello.
 *
 * Quello che si salva qui finisce nella tabella `settings` e ha la
 * precedenza sul .env; svuotare un campo cancella la riga e restituisce il
 * comando al file. Il .env non viene mai riscritto: contiene anche le
 * credenziali del database, e una scrittura sbagliata da una pagina web
 * lascerebbe la piattaforma senza accesso ai dati.
 */
class SettingsController extends AdminController
{
    private const MAIL_PAGE = '/admin/settings/posta';
    private const MEET_PAGE = '/admin/settings/meet';
    private const LIVE_MAIL_PAGE = '/admin/settings/inviti';

    /** Dove finisce la chiave dell'account di servizio, fuori dal document root. */
    private const KEY_DIR = __DIR__ . '/../../../storage/google';

    // ---------------------------------------------------------------
    // Posta elettronica
    // ---------------------------------------------------------------

    public function mail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        View::render('admin/settings/mail', [
            'pageTitle' => 'Posta elettronica',
            'transport' => strtolower((string) Settings::get('MAIL_TRANSPORT', 'log')),
            'values' => [
                'MAIL_FROM_ADDRESS' => (string) Settings::get('MAIL_FROM_ADDRESS', ''),
                'MAIL_FROM_NAME' => (string) Settings::get('MAIL_FROM_NAME', ''),
                'MAIL_HOST' => (string) Settings::get('MAIL_HOST', ''),
                'MAIL_PORT' => (string) Settings::get('MAIL_PORT', ''),
                'MAIL_USERNAME' => (string) Settings::get('MAIL_USERNAME', ''),
                'MAIL_ENCRYPTION' => strtolower((string) Settings::get('MAIL_ENCRYPTION', 'tls')),
            ],
            // La password non torna mai al browser: si dice solo se c'è.
            'hasPassword' => (string) Settings::get('MAIL_PASSWORD', '') !== '',
            'sources' => self::sourcesFor(Settings::MAIL_KEYS),
            'lastUpdate' => Settings::lastUpdate(Settings::MAIL_KEYS),
            'myEmail' => Auth::email(),
        ]);
    }

    public function updateMail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $transport = strtolower(trim((string) ($_POST['MAIL_TRANSPORT'] ?? 'log')));

        if (!in_array($transport, ['log', 'mail', 'smtp'], true)) {
            $this->fail('Modalità di invio non riconosciuta.', self::MAIL_PAGE);
        }

        $from = trim((string) ($_POST['MAIL_FROM_ADDRESS'] ?? ''));

        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('L\'indirizzo del mittente non è valido.', self::MAIL_PAGE);
        }

        $port = trim((string) ($_POST['MAIL_PORT'] ?? ''));

        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            $this->fail('La porta deve essere un numero fra 1 e 65535.', self::MAIL_PAGE);
        }

        $encryption = strtolower(trim((string) ($_POST['MAIL_ENCRYPTION'] ?? 'tls')));

        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $this->fail('Tipo di cifratura non riconosciuto.', self::MAIL_PAGE);
        }

        // Con SMTP senza server non si va da nessuna parte: meglio dirlo qui
        // che far fallire la prima email di registrazione di uno studente.
        if ($transport === 'smtp' && trim((string) ($_POST['MAIL_HOST'] ?? '')) === '') {
            $this->fail('Con l\'invio via SMTP serve l\'indirizzo del server.', self::MAIL_PAGE);
        }

        $userId = Auth::id();

        Settings::set('MAIL_TRANSPORT', $transport, $userId);
        Settings::set('MAIL_FROM_ADDRESS', $from, $userId);
        Settings::set('MAIL_FROM_NAME', trim((string) ($_POST['MAIL_FROM_NAME'] ?? '')), $userId);
        Settings::set('MAIL_HOST', trim((string) ($_POST['MAIL_HOST'] ?? '')), $userId);
        Settings::set('MAIL_PORT', $port, $userId);
        Settings::set('MAIL_USERNAME', trim((string) ($_POST['MAIL_USERNAME'] ?? '')), $userId);
        Settings::set('MAIL_ENCRYPTION', $encryption, $userId);

        // La password si tocca solo se è stato scritto qualcosa, altrimenti
        // aprire la pagina e salvare la cancellerebbe.
        if (($_POST['clear_password'] ?? '') === '1') {
            Settings::set('MAIL_PASSWORD', null, $userId);
        } elseif (trim((string) ($_POST['MAIL_PASSWORD'] ?? '')) !== '') {
            Settings::set('MAIL_PASSWORD', (string) $_POST['MAIL_PASSWORD'], $userId);
        }

        $this->resetMailer();

        $this->success('Impostazioni di posta salvate.', self::MAIL_PAGE);
    }

    /**
     * Invia un messaggio di prova a chi sta configurando: l'unico indirizzo
     * che si può usare senza chiedere il permesso a nessuno.
     */
    public function sendTestMail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $to = (string) Auth::email();

        if ($to === '') {
            $this->fail('Il tuo account non ha un indirizzo email.', self::MAIL_PAGE);
        }

        $this->resetMailer();

        $message = new Message(
            $to,
            (string) (Auth::name() ?? ''),
            'Prova di invio — Pistacchio LMS',
            "Questo messaggio conferma che l'invio della posta è configurato correttamente.\n\n"
                . 'Inviato il ' . date('d/m/Y \a\l\l\e H:i') . '.'
        );

        try {
            Mailer::send($message);
        } catch (MailException $e) {
            $this->fail('Invio non riuscito: ' . $e->getMessage(), self::MAIL_PAGE);
        }

        if (Mailer::isLogTransport()) {
            $this->success(
                'Messaggio scritto in storage/mail: in modalità registro non parte nulla verso l\'esterno.',
                self::MAIL_PAGE
            );
        }

        $this->success('Messaggio di prova inviato a ' . $to . '. Controlla la casella.', self::MAIL_PAGE);
    }

    // ---------------------------------------------------------------
    // Inviti alle sessioni live
    // ---------------------------------------------------------------

    public function liveMail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $values = [];

        foreach (Settings::LIVE_MAIL_KEYS as $key) {
            // Nel campo si mette quello che e' stato scritto qui, non il
            // predefinito: cosi' un campo vuoto si legge come "vale quello
            // di fabbrica", che e' esattamente quello che significa.
            $values[$key] = (string) (Settings::stored($key) ?? '');
        }

        View::render('admin/settings/live_mail', [
            'pageTitle' => 'Inviti alle sessioni live',
            'values' => $values,
            'defaults' => LiveSessionMail::DEFAULTS,
            'placeholders' => LiveSessionMail::placeholders(),
            'lastUpdate' => Settings::lastUpdate(Settings::LIVE_MAIL_KEYS),
            'logTransport' => Mailer::isLogTransport(),
        ]);
    }

    public function updateLiveMail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $userId = Auth::id();

        foreach (Settings::LIVE_MAIL_KEYS as $key) {
            $value = trim(str_replace("\r\n", "\n", (string) ($_POST[$key] ?? '')));

            // Un oggetto su piu' righe spezzerebbe l'intestazione del
            // messaggio: si rifiuta qui, dove si puo' ancora correggere.
            if (in_array($key, LiveSessionMail::SUBJECT_KEYS, true) && str_contains($value, "\n")) {
                $this->fail('L\'oggetto deve stare su una riga sola.', self::LIVE_MAIL_PAGE);
            }

            Settings::set($key, $value, $userId);
        }

        $this->success('Testi degli inviti salvati.', self::LIVE_MAIL_PAGE);
    }

    // ---------------------------------------------------------------
    // Google Meet
    // ---------------------------------------------------------------

    public function meet(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $keyPath = (string) Settings::get('GOOGLE_SERVICE_ACCOUNT_JSON', '');

        View::render('admin/settings/meet', [
            'pageTitle' => 'Google Meet',
            'values' => [
                'GOOGLE_IMPERSONATE_EMAIL' => (string) Settings::get('GOOGLE_IMPERSONATE_EMAIL', ''),
                'GOOGLE_CALENDAR_ID' => (string) Settings::get('GOOGLE_CALENDAR_ID', ''),
                'GOOGLE_CALENDAR_TIMEZONE' => (string) Settings::get('GOOGLE_CALENDAR_TIMEZONE', ''),
            ],
            'keyPath' => $keyPath,
            'keyPresent' => $keyPath !== '' && is_file($keyPath),
            'keyAccount' => self::keyAccount($keyPath),
            'configured' => MeetCalendar::isConfigured(),
            'sources' => self::sourcesFor(Settings::GOOGLE_KEYS),
            'lastUpdate' => Settings::lastUpdate(Settings::GOOGLE_KEYS),
            'defaultTimezone' => date_default_timezone_get(),
        ]);
    }

    public function updateMeet(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $impersonate = trim((string) ($_POST['GOOGLE_IMPERSONATE_EMAIL'] ?? ''));

        if ($impersonate !== '' && filter_var($impersonate, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('L\'indirizzo da impersonare non è valido.', self::MEET_PAGE);
        }

        $timezone = trim((string) ($_POST['GOOGLE_CALENDAR_TIMEZONE'] ?? ''));

        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $this->fail('Fuso orario non riconosciuto: usa un nome come Europe/Rome.', self::MEET_PAGE);
        }

        $userId = Auth::id();

        Settings::set('GOOGLE_IMPERSONATE_EMAIL', $impersonate, $userId);
        Settings::set('GOOGLE_CALENDAR_ID', trim((string) ($_POST['GOOGLE_CALENDAR_ID'] ?? '')), $userId);
        Settings::set('GOOGLE_CALENDAR_TIMEZONE', $timezone, $userId);

        if (!empty($_FILES['key']['name'])) {
            $this->storeKey();
        }

        $this->success('Impostazioni di Google Meet salvate.', self::MEET_PAGE);
    }

    public function deleteMeetKey(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $current = (string) Settings::get('GOOGLE_SERVICE_ACCOUNT_JSON', '');

        Settings::set('GOOGLE_SERVICE_ACCOUNT_JSON', null, Auth::id());

        // Si cancella solo quello che abbiamo caricato noi: un percorso
        // scritto nel .env punta a un file che non ci appartiene.
        if ($current !== '' && is_file($current) && str_starts_with(realpath($current) ?: '', self::keyDir())) {
            @unlink($current);
        }

        $this->success('Chiave rimossa. Le sessioni live restano gestibili con link inseriti a mano.', self::MEET_PAGE);
    }

    /**
     * Legge il calendario configurato senza creare niente.
     */
    public function testMeet(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $calendar = MeetCalendar::fromEnv();

        if ($calendar === null) {
            $this->fail('Configurazione incompleta: manca la chiave dell\'account di servizio.', self::MEET_PAGE);
        }

        try {
            $resource = $calendar->checkAccess();
        } catch (GoogleException $e) {
            $this->fail('Google ha risposto: ' . $e->getMessage(), self::MEET_PAGE);
        }

        $name = (string) ($resource['summary'] ?? $resource['id'] ?? 'calendario');

        $this->success('Connessione riuscita. Calendario raggiunto: ' . $name . '.', self::MEET_PAGE);
    }

    // ---------------------------------------------------------------

    /**
     * Salva il file di chiave caricato, dopo aver controllato che sia davvero
     * una chiave di account di servizio.
     */
    private function storeKey(): void
    {
        $file = $_FILES['key'];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->fail('Caricamento della chiave non riuscito.', self::MEET_PAGE);
        }

        if ((int) $file['size'] > 64 * 1024) {
            $this->fail('Il file è troppo grande per essere una chiave di account di servizio.', self::MEET_PAGE);
        }

        $credentials = json_decode((string) file_get_contents($file['tmp_name']), true);

        if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            $this->fail('Il file non contiene client_email e private_key: non è la chiave giusta.', self::MEET_PAGE);
        }

        $directory = self::KEY_DIR;

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->fail('Impossibile creare la cartella delle credenziali sul server.', self::MEET_PAGE);
        }

        $previous = (string) Settings::get('GOOGLE_SERVICE_ACCOUNT_JSON', '');
        $path = $directory . '/' . bin2hex(random_bytes(16)) . '.json';

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            $this->fail('Impossibile salvare la chiave sul server.', self::MEET_PAGE);
        }

        // Una chiave privata non deve essere leggibile da altri utenti della
        // macchina; su Windows il permesso viene ignorato, ma la cartella e'
        // comunque fuori dal document root.
        @chmod($path, 0600);

        Settings::set('GOOGLE_SERVICE_ACCOUNT_JSON', $path, Auth::id());

        if ($previous !== '' && is_file($previous) && str_starts_with(realpath($previous) ?: '', self::keyDir())) {
            @unlink($previous);
        }
    }

    /**
     * Da quale chiave arriva ogni valore: tabella, .env o niente.
     *
     * @param string[] $keys
     * @return array<string, string>
     */
    private static function sourcesFor(array $keys): array
    {
        $sources = [];

        foreach ($keys as $key) {
            $sources[$key] = Settings::source($key);
        }

        return $sources;
    }

    /**
     * Indirizzo dell'account di servizio, per far vedere quale chiave è in
     * uso senza mostrare il file.
     */
    private static function keyAccount(string $path): ?string
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        return is_array($credentials) && !empty($credentials['client_email'])
            ? (string) $credentials['client_email']
            : null;
    }

    private static function keyDir(): string
    {
        return realpath(self::KEY_DIR) ?: self::KEY_DIR;
    }

    /**
     * Il trasporto della posta è memorizzato per tutta la richiesta: dopo un
     * salvataggio va ricostruito, altrimenti la prova userebbe i valori vecchi.
     */
    private function resetMailer(): void
    {
        Settings::forget();
        Mailer::setTransport(null);
    }
}
