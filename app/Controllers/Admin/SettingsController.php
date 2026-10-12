<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\CampoContato;
use App\Core\AuthLayout;
use App\Core\BunnyToken;
use App\Core\ElementStyle;
use App\Core\FontLibrary;
use App\Core\Google\GoogleException;
use App\Core\Google\MeetCalendar;
use App\Core\Google\ServiceAccountClient;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use App\Core\Mail\Modelli;
use App\Core\Settings;
use App\Core\Welcome;
use App\Core\Theme;
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
    private const EMAIL_PAGE = '/admin/settings/email';
    private const APPEARANCE_PAGE = '/admin/settings/aspetto';
    private const BUNNY_PAGE = '/admin/settings/bunny';

    /** Dove finisce la chiave dell'account di servizio, fuori dal document root. */
    private const KEY_DIR = __DIR__ . '/../../../storage/google';

    // ---------------------------------------------------------------
    // Raccolta delle impostazioni
    // ---------------------------------------------------------------

    /**
     * Pagina di raccolta: una scheda per sezione.
     *
     * Non un sottomenu che si apre nella barra laterale: una pagina funziona
     * senza JavaScript (Sezione 4) e puo' dire a cosa serve ciascuna sezione,
     * cosa che dai soli nomi non e' ovvia — "Server di posta elettronica" e "Messaggi email"
     * sembrano la stessa cosa e non lo sono: la prima dice come partono, la
     * seconda che cosa dicono.
     */
    public function index(array $params = []): void
    {
        // Le schede non hanno tutte lo stesso permesso: quattro chiedono
        // `settings.manage`, i permessi solo il ruolo admin. La pagina si apre
        // a chi ha almeno una delle due cose, e mostra solo cio' che puo'
        // davvero aprire.
        // Prima il login, come ogni altra pagina interna: senza, chi non e'
        // collegato riceverebbe un 403 invece di essere portato all'accesso.
        Auth::requireLogin();

        if (!Auth::can('settings.manage') && !Auth::hasRole('admin')) {
            http_response_code(403);
            exit('Accesso negato: permessi insufficienti per questa pagina.');
        }

        View::render('admin/settings/index', [
            'pageTitle' => 'Impostazioni',
            'canSettings' => Auth::can('settings.manage'),
            'canPermissions' => Auth::hasRole('admin'),
        ]);
    }

    // ---------------------------------------------------------------
    // Server di posta elettronica
    // ---------------------------------------------------------------

    public function mail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        View::render('admin/settings/mail', [
            'pageTitle' => 'Server di posta elettronica',
            'transport' => strtolower((string) Settings::get('MAIL_TRANSPORT', 'log')),
            'values' => [
                'MAIL_HOST' => (string) Settings::get('MAIL_HOST', ''),
                'MAIL_PORT' => (string) Settings::get('MAIL_PORT', ''),
                'MAIL_USERNAME' => (string) Settings::get('MAIL_USERNAME', ''),
                'MAIL_ENCRYPTION' => strtolower((string) Settings::get('MAIL_ENCRYPTION', 'tls')),
            ],
            // La password non torna mai al browser: si dice solo se c'è.
            'hasPassword' => (string) Settings::get('MAIL_PASSWORD', '') !== '',
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
            'Prova di invio — ' . Modelli::PIATTAFORMA,
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
    // Email: mittente, chiusura e testo di ogni email (12/10)
    // ---------------------------------------------------------------

    public function email(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        // Nei campi si mette quello che e' stato scritto qui, non il
        // predefinito: cosi' un campo vuoto si legge come «vale il testo in
        // grigio», che e' esattamente quello che significa.
        $testi = [];

        foreach (Modelli::elenco() as $chiave => $voce) {
            $testi[$chiave] = [
                'oggetto' => (string) (Settings::stored($voce['oggetto']) ?? ''),
                'testo' => (string) (Settings::stored($voce['testo']) ?? ''),
            ];
        }

        // Un testo rifiutato torna nei campi com'era stato scritto.
        $daRiprendere = $_SESSION['email_da_riprendere'] ?? null;
        unset($_SESSION['email_da_riprendere']);

        if (is_array($daRiprendere) && isset($testi[$daRiprendere['chiave'] ?? ''])) {
            $testi[$daRiprendere['chiave']] = [
                'oggetto' => (string) $daRiprendere['oggetto'],
                'testo' => (string) $daRiprendere['testo'],
            ];
        }

        View::render('admin/settings/email', [
            'pageTitle' => 'Messaggi email',
            'comuni' => [
                Modelli::ORGANIZZAZIONE => (string) (Settings::stored(Modelli::ORGANIZZAZIONE) ?? ''),
                'MAIL_FROM_NAME' => (string) Settings::get('MAIL_FROM_NAME', ''),
                'MAIL_FROM_ADDRESS' => (string) Settings::get('MAIL_FROM_ADDRESS', ''),
                Modelli::RISPONDI_A => (string) (Settings::stored(Modelli::RISPONDI_A) ?? ''),
                Modelli::CHIUSURA => (string) (Settings::stored(Modelli::CHIUSURA) ?? ''),
            ],
            'mittenteDaFile' => Settings::source('MAIL_FROM_ADDRESS') === 'file',
            'testi' => $testi,
            'lastUpdate' => Settings::lastUpdate(array_merge(['MAIL_FROM_NAME', 'MAIL_FROM_ADDRESS'], Modelli::chiaviSettings())),
            'logTransport' => Mailer::isLogTransport(),
            'myEmail' => Auth::email(),
        ]);
    }

    /** Organizzazione, mittente, «Rispondi a» e chiusura: una volta per tutte le email. */
    public function updateEmail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $organizzazione = trim((string) ($_POST[Modelli::ORGANIZZAZIONE] ?? ''));
        $nomeMittente = trim((string) ($_POST['MAIL_FROM_NAME'] ?? ''));
        $mittente = trim((string) ($_POST['MAIL_FROM_ADDRESS'] ?? ''));
        $rispondiA = trim((string) ($_POST[Modelli::RISPONDI_A] ?? ''));

        if ($mittente !== '' && filter_var($mittente, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('L\'indirizzo del mittente non è valido.', self::EMAIL_PAGE);
        }

        if ($rispondiA !== '' && filter_var($rispondiA, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('L\'indirizzo «Rispondi a» non è valido.', self::EMAIL_PAGE);
        }

        $chiusura = CampoContato::taglia((string) ($_POST[Modelli::CHIUSURA] ?? ''), Modelli::MAX_CHIUSURA);
        $problema = $this->problemaSegnaposto($chiusura, array_keys(Modelli::segnapostoComuni()), []);

        $userId = Auth::id();
        Settings::set(Modelli::ORGANIZZAZIONE, mb_substr($organizzazione, 0, 100), $userId);
        Settings::set('MAIL_FROM_NAME', mb_substr($nomeMittente, 0, 100), $userId);
        Settings::set('MAIL_FROM_ADDRESS', $mittente, $userId);
        Settings::set(Modelli::RISPONDI_A, $rispondiA, $userId);
        Settings::set(Modelli::CHIUSURA, $chiusura, $userId);

        $this->resetMailer();

        $this->success('Mittente e chiusura salvati.' . ($problema !== null ? ' ' . $problema : ''), self::EMAIL_PAGE);
    }

    /** Oggetto e testo di un'email. */
    public function updateModello(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        [$chiave, $voce, $oggetto, $testo] = $this->modelloDalModulo();
        $ancora = self::EMAIL_PAGE . '#email-' . $chiave;

        $controllo = Modelli::controlla($oggetto . "\n" . $testo, Modelli::ammessi($chiave), []);
        $mancanti = Modelli::controlla($testo, Modelli::ammessi($chiave), $voce['obbligatori'])['mancanti'];

        if ($mancanti !== []) {
            $_SESSION['email_da_riprendere'] = ['chiave' => $chiave, 'oggetto' => $oggetto, 'testo' => $testo];
            $this->fail(
                '«' . $voce['titolo'] . '» non è stata salvata: nel testo '
                . (count($mancanti) === 1 ? 'manca ' : 'mancano ') . implode(', ', $mancanti)
                . ', senza ' . (count($mancanti) === 1 ? 'il quale' : 'i quali') . ' l’email non serve a chi la riceve.',
                $ancora
            );
        }

        $userId = Auth::id();
        Settings::set($voce['oggetto'], $oggetto, $userId);
        Settings::set($voce['testo'], $testo, $userId);

        $avviso = $controllo['sconosciuti'] !== []
            ? ' Attenzione: ' . implode(', ', $controllo['sconosciuti'])
                . (count($controllo['sconosciuti']) === 1 ? ' non è un segnaposto di questa email e resterà scritto così.'
                    : ' non sono segnaposto di questa email e resteranno scritti così.')
            : '';

        $this->success('«' . $voce['titolo'] . '» salvata.' . $avviso, $ancora);
    }

    /**
     * La prova di un'email al proprio indirizzo, con quello che e' scritto
     * nei campi anche se non e' salvato: si prova prima di salvare.
     */
    public function provaModello(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        [$chiave, , $oggetto, $testo] = $this->modelloDalModulo();
        $ancora = self::EMAIL_PAGE . '#email-' . $chiave;
        $to = (string) Auth::email();

        // I campi tornano come erano, salvati o no.
        $_SESSION['email_da_riprendere'] = ['chiave' => $chiave, 'oggetto' => $oggetto, 'testo' => $testo];

        if ($to === '') {
            $this->fail('Il tuo account non ha un indirizzo email.', $ancora);
        }

        $this->resetMailer();

        try {
            Mailer::send(Modelli::prova($chiave, $to, (string) (Auth::name() ?? ''), $oggetto, $testo));
        } catch (MailException $e) {
            $this->fail('Invio non riuscito: ' . $e->getMessage(), $ancora);
        }

        $this->success(
            Mailer::isLogTransport()
                ? 'Prova scritta in storage/mail: in modalità registro non parte nulla verso l\'esterno.'
                : 'Prova inviata a ' . $to . ', con i valori di esempio al posto dei segnaposto.',
            $ancora
        );
    }

    /** La vecchia pagina dei testi degli incontri: ora e' un gruppo della pagina Messaggi email. */
    public function liveMail(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        header('Location: ' . self::EMAIL_PAGE . '#incontri', true, 301);
        exit;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function modelloDalModulo(): array
    {
        $chiave = (string) ($_POST['chiave'] ?? '');
        $elenco = Modelli::elenco();

        if (!isset($elenco[$chiave])) {
            $this->fail('Email sconosciuta.', self::EMAIL_PAGE);
        }

        // Un oggetto su piu' righe spezzerebbe l'intestazione del messaggio:
        // gli a capo diventano spazi.
        $oggetto = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['oggetto'] ?? ''))), 0, 200);
        $testo = CampoContato::taglia((string) ($_POST['testo'] ?? ''), Modelli::MAX_TESTO);

        return [$chiave, $elenco[$chiave], $oggetto, $testo];
    }

    /**
     * @param list<string> $ammessi
     * @param list<string> $obbligatori
     */
    private function problemaSegnaposto(string $testo, array $ammessi, array $obbligatori): ?string
    {
        $sconosciuti = Modelli::controlla($testo, $ammessi, $obbligatori)['sconosciuti'];

        if ($sconosciuti === []) {
            return null;
        }

        return 'Attenzione: ' . implode(', ', $sconosciuti)
            . (count($sconosciuti) === 1 ? ' non è un segnaposto comune e resterà scritto così.'
                : ' non sono segnaposto comuni e resteranno scritti così.');
    }

    // ---------------------------------------------------------------
    // Aspetto delle pagine pubbliche
    // ---------------------------------------------------------------

    public function appearance(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $values = [];

        foreach (['AUTH_SPLIT_TITLE', 'AUTH_SPLIT_TEXT'] as $key) {
            // Nel campo si mette quello che e' stato scritto qui, non il
            // predefinito: un campo vuoto si legge come "vale quello di
            // fabbrica", che e' esattamente quello che significa.
            $values[$key] = (string) (Settings::stored($key) ?? '');
        }

        View::render('admin/settings/aspetto', [
            'pageTitle' => 'Aspetto',
            'layout' => AuthLayout::current(),
            'choices' => AuthLayout::CHOICES,
            'values' => $values,
            'defaults' => AuthLayout::DEFAULTS,
            'palette' => Theme::paletteCorrente(),
            'tavolozze' => Theme::TAVOLOZZE,
            // Nel campo va quello che e' stato scritto, non il colore in
            // vigore: vuoto significa "quello della tavolozza", ed e'
            // esattamente cio' che il campo vuoto deve dire.
            'primario' => (string) (Settings::stored(Theme::KEY_PRIMARY) ?? ''),
            'primarioInVigore' => Theme::valori()['primary'],
            'raggio' => Theme::raggioCorrente(),
            'raggi' => Theme::RAGGI,
            'misuraTesto' => Theme::misuraTestoCorrente(),
            'misureTesto' => Theme::MISURE_TESTO,
            'coloreTesto' => (string) (Settings::stored(Theme::KEY_TEXT_COLOR) ?? ''),
            'coloreTestoInVigore' => Theme::coloriTesto()[0],
            'misuraScena' => Theme::misuraScenaCorrente(),
            'misureScena' => Theme::MISURE_SCENA,
            'catalogoFont' => array_keys(FontLibrary::catalogo()),
            'fontPubbliche' => (string) (Settings::stored(Theme::KEY_FONT_AUTH) ?? ''),
            'fontInterno' => (string) (Settings::stored(Theme::KEY_FONT_APP) ?? ''),
            'elementi' => ElementStyle::ELEMENTI,
            'proprieta' => ElementStyle::PROPRIETA,
            'ritocchi' => ElementStyle::valori(),
            'lastUpdate' => Settings::lastUpdate(Settings::APPEARANCE_KEYS),
        ]);
    }

    public function updateAppearance(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $userId = Auth::id();
        $layout = (string) ($_POST['AUTH_LAYOUT'] ?? '');

        // Elenco chiuso: un POST costruito a mano non deve poter scrivere un
        // aspetto che non esiste e lasciare le pagine pubbliche senza vestito.
        if (!array_key_exists($layout, AuthLayout::CHOICES)) {
            $this->fail('Aspetto non valido.', self::APPEARANCE_PAGE);
        }

        // Stesso criterio dell'aspetto: elenco chiuso, perche' una chiave
        // inventata lascerebbe la piattaforma senza colori.
        $palette = (string) ($_POST[Theme::KEY_PALETTE] ?? Theme::PREDEFINITA);

        if (!array_key_exists($palette, Theme::TAVOLOZZE)) {
            $this->fail('Tavolozza non valida.', self::APPEARANCE_PAGE);
        }

        // Il colore personalizzato: vuoto vuol dire "quello della
        // tavolozza". Se c'e', deve leggersi — e il motivo per cui non va
        // bene si dice adesso, non dopo averlo salvato.
        $primario = trim((string) ($_POST[Theme::KEY_PRIMARY] ?? ''));

        if ($primario !== '') {
            $motivo = Theme::perche($primario);

            if ($motivo !== null) {
                $this->fail($motivo, self::APPEARANCE_PAGE);
            }

            $primario = (string) Theme::coloreValido($primario);
        }

        $raggio = (string) ($_POST[Theme::KEY_RADIUS] ?? Theme::RAGGIO_PREDEFINITO);

        if (!array_key_exists($raggio, Theme::RAGGI)) {
            $this->fail('Arrotondamento non valido.', self::APPEARANCE_PAGE);
        }

        $misura = (string) ($_POST[Theme::KEY_TEXT_SIZE] ?? Theme::MISURA_TESTO_PREDEFINITA);

        if (!array_key_exists($misura, Theme::MISURE_TESTO)) {
            $this->fail('Dimensione del testo non valida.', self::APPEARANCE_PAGE);
        }

        // Il colore del testo, come quello principale: vuoto vuol dire
        // quello di fabbrica, e se c'e' deve leggersi — su tutti i fondi su
        // cui il testo finisce, tavolozze comprese.
        $coloreTesto = trim((string) ($_POST[Theme::KEY_TEXT_COLOR] ?? ''));

        if ($coloreTesto !== '') {
            $motivo = Theme::percheTesto($coloreTesto);

            if ($motivo !== null) {
                $this->fail($motivo, self::APPEARANCE_PAGE);
            }

            $coloreTesto = (string) Theme::coloreValido($coloreTesto);
        }

        $scena = (string) ($_POST[Theme::KEY_SCENE_SIZE] ?? Theme::MISURA_SCENA_PREDEFINITA);

        if (!array_key_exists($scena, Theme::MISURE_SCENA)) {
            $this->fail('Misura della presentazione non valida.', self::APPEARANCE_PAGE);
        }

        Settings::set('AUTH_LAYOUT', $layout, $userId);
        Settings::set('AUTH_SPLIT_TITLE', CampoContato::taglia((string) ($_POST['AUTH_SPLIT_TITLE'] ?? ''), AuthLayout::MAX_TITOLO), $userId);
        Settings::set('AUTH_SPLIT_TEXT', CampoContato::taglia((string) ($_POST['AUTH_SPLIT_TEXT'] ?? ''), AuthLayout::MAX_TESTO), $userId);
        Settings::set(Theme::KEY_PALETTE, $palette, $userId);
        Settings::set(Theme::KEY_PRIMARY, $primario, $userId);
        Settings::set(Theme::KEY_RADIUS, $raggio, $userId);
        Settings::set(Theme::KEY_TEXT_SIZE, $misura, $userId);
        Settings::set(Theme::KEY_TEXT_COLOR, $coloreTesto, $userId);
        // I ritocchi sui singoli elementi: arrivano come
        // `elemento[chiave][proprieta]`, si ripuliscono contro gli elenchi
        // chiusi e si controllano i colori prima di salvare.
        $ritocchi = ElementStyle::ripulisci((array) ($_POST['elemento'] ?? []));
        $motivo = ElementStyle::perche($ritocchi);

        if ($motivo !== null) {
            $this->fail($motivo, self::APPEARANCE_PAGE);
        }

        Settings::set(Theme::KEY_SCENE_SIZE, $scena, $userId);
        Settings::set(
            ElementStyle::KEY,
            $ritocchi === [] ? '' : (string) json_encode($ritocchi, JSON_UNESCAPED_SLASHES),
            $userId
        );

        // I caratteri per ultimi, perche' sono gli unici che possono
        // fallire per una ragione fuori dal nostro controllo: se il server
        // non riesce a scaricarli, tutto il resto e' gia' salvato e si
        // perde solo quella scelta, invece di perdere l'intero modulo.
        foreach ([Theme::KEY_FONT_AUTH => 'font_pubbliche', Theme::KEY_FONT_APP => 'font_interno'] as $chiave => $campo) {
            $famiglia = trim((string) ($_POST[$campo] ?? ''));

            if ($famiglia === '') {
                Settings::set($chiave, '', $userId);
                continue;
            }

            if (!FontLibrary::esiste($famiglia)) {
                $this->fail('Il carattere "' . $famiglia . '" non è nel catalogo.', self::APPEARANCE_PAGE);
            }

            // Gia' sul nostro server: niente da scaricare, si salva e basta.
            if (!FontLibrary::presente($famiglia)) {
                try {
                    FontLibrary::scarica($famiglia);
                } catch (\RuntimeException $e) {
                    // **L'impostazione non si salva.** Il nome di un
                    // carattere il cui file non esiste vorrebbe dire pagine
                    // che chiedono un file inesistente a ogni caricamento.
                    $this->fail($e->getMessage(), self::APPEARANCE_PAGE);
                }
            }

            Settings::set($chiave, $famiglia, $userId);
        }

        $this->success('Aspetto salvato.', self::APPEARANCE_PAGE);
    }

    // ---------------------------------------------------------------
    // Bunny Stream
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Video di benvenuto
    // ---------------------------------------------------------------

    private const WELCOME_PAGE = '/admin/settings/benvenuto';

    public function welcome(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        View::render('admin/settings/benvenuto', [
            'pageTitle' => 'Video di benvenuto',
            'provider' => Welcome::provider(),
            'videoRef' => Welcome::videoRef(),
            'configurato' => Welcome::configurato(),
            'sources' => self::sourcesFor(Settings::WELCOME_KEYS),
            'lastUpdate' => Settings::lastUpdate(Settings::WELCOME_KEYS),
        ]);
    }

    public function updateWelcome(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $userId = Auth::id();
        $provider = (string) ($_POST['WELCOME_VIDEO_PROVIDER'] ?? 'none');

        // Un provider fuori elenco non diventa mai un valore salvato: la
        // tendina e' una promessa all'utente, non una difesa.
        if (!isset(Welcome::PROVIDERS[$provider])) {
            $this->fail('Provider video non riconosciuto.', self::WELCOME_PAGE);
        }

        $ref = trim((string) ($_POST['WELCOME_VIDEO_REF'] ?? ''));

        // Un provider scelto senza identificativo non accende niente e non
        // lo dice: meglio fermarsi qui che lasciare l'admin convinto di
        // aver pubblicato un video che nessuno vedra'.
        if ($provider !== 'none' && $ref === '') {
            $this->fail(
                'Hai scelto un provider ma non hai indicato l\'identificativo del video.',
                self::WELCOME_PAGE
            );
        }

        Settings::set('WELCOME_VIDEO_PROVIDER', $provider, $userId);
        Settings::set('WELCOME_VIDEO_REF', $ref, $userId);

        $this->success(
            $provider === 'none'
                ? 'Video di benvenuto disattivato: la pagina non comparirà più.'
                : 'Video di benvenuto salvato.',
            self::WELCOME_PAGE
        );
    }

    public function bunny(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        View::render('admin/settings/bunny', [
            'pageTitle' => 'Bunny Stream',
            'libraryId' => (string) Settings::get('BUNNY_LIBRARY_ID', ''),
            // La chiave non torna mai al browser: si dice solo se c'e'.
            'hasKey' => (string) Settings::get('BUNNY_TOKEN_KEY', '') !== '',
            'ttlHours' => BunnyToken::ttlHours(),
            'minTtl' => BunnyToken::MIN_TTL_HOURS,
            'maxTtl' => BunnyToken::MAX_TTL_HOURS,
            'signing' => BunnyToken::isConfigured(),
            'sources' => self::sourcesFor(Settings::BUNNY_KEYS),
            'lastUpdate' => Settings::lastUpdate(Settings::BUNNY_KEYS),
        ]);
    }

    public function updateBunny(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $userId = Auth::id();
        $ore = (int) ($_POST['BUNNY_TOKEN_TTL_HOURS'] ?? BunnyToken::DEFAULT_TTL_HOURS);

        if ($ore < BunnyToken::MIN_TTL_HOURS || $ore > BunnyToken::MAX_TTL_HOURS) {
            $this->fail(
                'La durata del token deve stare fra ' . BunnyToken::MIN_TTL_HOURS
                . ' e ' . BunnyToken::MAX_TTL_HOURS . ' ore.',
                self::BUNNY_PAGE
            );
        }

        Settings::set('BUNNY_LIBRARY_ID', trim((string) ($_POST['BUNNY_LIBRARY_ID'] ?? '')), $userId);
        Settings::set('BUNNY_TOKEN_TTL_HOURS', (string) $ore, $userId);

        // La chiave si tocca solo se e' stato scritto qualcosa: aprire la
        // pagina e salvare non deve cancellarla.
        if (($_POST['clear_key'] ?? '') === '1') {
            Settings::set('BUNNY_TOKEN_KEY', null, $userId);
        } elseif (trim((string) ($_POST['BUNNY_TOKEN_KEY'] ?? '')) !== '') {
            Settings::set('BUNNY_TOKEN_KEY', trim((string) $_POST['BUNNY_TOKEN_KEY']), $userId);
        }

        $this->success('Impostazioni di Bunny Stream salvate.', self::BUNNY_PAGE);
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
