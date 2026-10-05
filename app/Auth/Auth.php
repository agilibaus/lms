<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Csrf;
use App\Core\Welcome;
use App\Models\RolePermissionModel;
use App\Models\UserModel;

/**
 * Autenticazione, sessione utente e permessi per ruolo.
 * L'accesso ai dati passa sempre dai Model (nessuna query diretta qui).
 */
class Auth
{
    public const ROLES = ['admin', 'tutor', 'assistente', 'studente'];

    /** Chiave di sessione con la copia di `users.password_changed_at`. */
    private const SESSION_PASSWORD_STAMP = 'password_changed_at';

    /** L'unica pagina interna raggiungibile con una password temporanea. */
    public const PASSWORD_PAGE = '/profilo/password';

    /**
     * Come il ruolo si scrive quando lo legge una persona.
     *
     * In tabella e nel codice i ruoli restano minuscoli: sono valori, non
     * testo. Le maiuscole servono solo alle pagine, e stanno qui perche'
     * cinque viste le stampavano ognuna a modo suo.
     */
    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            'admin' => 'Admin',
            'tutor' => 'Tutor',
            'assistente' => 'Assistente',
            'studente' => 'Studente',
            // Un ruolo aggiunto in futuro non resta minuscolo per dimenticanza.
            null, '' => '',
            default => mb_strtoupper(mb_substr($role, 0, 1)) . mb_substr($role, 1),
        };
    }

    /** @var array<string, string[]> cache dei permessi per ruolo, valida per la singola richiesta */
    private static array $permissionCache = [];

    /** Motivo dell'ultimo tentativo di accesso fallito (per un messaggio utile). */
    private static ?string $failureReason = null;

    /**
     * Stato della password per la richiesta in corso: `false` finche' non e'
     * stato letto, poi la riga oppure null se l'utente non esiste piu'.
     *
     * @var array{password_changed_at: ?string, must_change_password: int, welcome_seen_at: ?string}|null|false
     */
    private static array|null|false $passwordState = false;

    public const FAILURE_CREDENTIALS = 'credentials';
    public const FAILURE_UNVERIFIED = 'unverified';
    public const FAILURE_INACTIVE = 'inactive';

    public static function attempt(string $email, string $password): bool
    {
        $user = UserModel::findByEmail($email);
        self::$failureReason = null;

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::$failureReason = self::FAILURE_CREDENTIALS;

            return false;
        }

        // Le credenziali sono giuste: da qui i motivi del rifiuto si possono
        // dire senza rivelare nulla a chi sta tirando a indovinare.
        if (!(bool) $user['is_active']) {
            self::$failureReason = self::FAILURE_INACTIVE;

            return false;
        }

        if (($user['email_verified_at'] ?? null) === null) {
            self::$failureReason = self::FAILURE_UNVERIFIED;

            return false;
        }

        session_regenerate_id(true);
        Csrf::rotate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_email'] = $user['email'];
        // Copia dell'istante dell'ultimo cambio password: e' il riferimento
        // con cui `guardSession()` riconosce una sessione aperta con una
        // password che nel frattempo e' stata cambiata.
        $_SESSION[self::SESSION_PASSWORD_STAMP] = $user['password_changed_at'] ?? null;

        return true;
    }

    public static function failureReason(): ?string
    {
        return self::$failureReason;
    }

    public static function logout(): void
    {
        Csrf::rotate();
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    public static function name(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }

    public static function email(): ?string
    {
        return $_SESSION['user_email'] ?? null;
    }

    /**
     * Percorso dell'immagine del profilo (relativo a /storage), oppure null.
     *
     * Tenuto in sessione perche' la barra laterale lo chiede a ogni pagina:
     * viene letto dal database una volta sola, alla prima richiesta della
     * sessione, cosi' anche le sessioni aperte prima di questa funzione
     * trovano l'immagine.
     */
    public static function avatar(): ?string
    {
        if (!self::check()) {
            return null;
        }

        if (!array_key_exists('user_avatar', $_SESSION)) {
            $user = UserModel::find((int) self::id());
            $_SESSION['user_avatar'] = $user['avatar_path'] ?? null;
        }

        return $_SESSION['user_avatar'];
    }

    /**
     * Allinea la sessione dopo che l'utente ha cambiato la propria immagine.
     */
    public static function setAvatar(?string $path): void
    {
        $_SESSION['user_avatar'] = $path;
    }

    public static function hasRole(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }

        self::guardSession();
    }

    /**
     * Due controlli che valgono su ogni pagina di chi e' collegato.
     *
     * 1. **La sessione e' ancora buona?** Cambiare la password aggiorna
     *    `users.password_changed_at`; la sessione ne porta la copia presa
     *    all'accesso. Se le due non combaciano, quella sessione e' stata
     *    aperta con la password vecchia e viene chiusa. E' cosi' che le altre
     *    sessioni dello stesso utente cadono: senza, chi fosse gia' dentro da
     *    un altro browser ci resterebbe, e cambiare la password in fretta non
     *    servirebbe a niente.
     *
     * 2. **La password va cambiata?** Con `must_change_password` a 1 l'unica
     *    pagina raggiungibile e' quella di cambio password. L'uscita resta
     *    sempre aperta: senza, chi entra per sbaglio con un account altrui non
     *    potrebbe nemmeno andarsene.
     *
     * Costa una query per richiesta, deliberatamente ristretta a due colonne.
     */
    private static function guardSession(): void
    {
        $stato = self::passwordState();

        if ($stato === null) {
            // L'utente non esiste piu': la sessione non ha piu' un titolare.
            self::logout();
            header('Location: /login?motivo=sessione');
            exit;
        }

        if (array_key_exists(self::SESSION_PASSWORD_STAMP, $_SESSION)) {
            if ($_SESSION[self::SESSION_PASSWORD_STAMP] !== $stato['password_changed_at']) {
                self::logout();
                header('Location: /login?motivo=password');
                exit;
            }
        } else {
            // Sessione aperta prima che questa funzione esistesse: si allinea
            // invece di buttare fuori tutti al momento dell'aggiornamento.
            $_SESSION[self::SESSION_PASSWORD_STAMP] = $stato['password_changed_at'];
        }

        if ((int) $stato['must_change_password'] === 1 && !self::onPasswordPage()) {
            header('Location: ' . self::PASSWORD_PAGE);
            exit;
        }

        // 3. Il benvenuto, **dopo** il cambio password e non prima: chi entra
        //    con una password temporanea deve prima sceglierne una sua, e due
        //    schermate obbligate che si contendono la stessa persona sarebbero
        //    una di troppo. Il controllo e' qui sotto proprio per questo.
        //
        //    Solo gli studenti: lo staff entra per lavorare, e un video di
        //    benvenuto davanti all'amministratore che deve sistemare un corso
        //    e' un ostacolo, non un'accoglienza.
        if (
            $stato['welcome_seen_at'] === null
            && self::hasRole('studente')
            && !Welcome::paginaEsente(self::percorsoCorrente())
            && Welcome::configurato()
        ) {
            header('Location: ' . Welcome::PAGE);
            exit;
        }
    }

    /**
     * Le sole pagine raggiungibili da chi deve cambiare la password.
     */
    private static function onPasswordPage(): bool
    {
        $percorso = self::percorsoCorrente();

        return $percorso === self::PASSWORD_PAGE || $percorso === '/logout';
    }

    /** Il percorso della richiesta, senza la stringa di ricerca. */
    private static function percorsoCorrente(): string
    {
        return parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    }

    /**
     * Vero se questa persona non ha ancora visto il video di benvenuto.
     *
     * Legge lo stato gia' caricato per la sessione, quindi non costa una
     * query in piu'. Non dice se un video esista: quella e' una domanda di
     * `Welcome::configurato()`, e tenerle separate serve perche' il profilo
     * offre di **rivedere** il video anche a chi l'ha gia' visto.
     */
    public static function welcomePending(): bool
    {
        if (!self::check()) {
            return false;
        }

        $stato = self::passwordState();

        return $stato !== null && $stato['welcome_seen_at'] === null;
    }

    /**
     * Vero se l'utente sta usando una password generata da un admin e deve
     * ancora sceglierne una sua. Serve alla pagina di cambio password per
     * spiegare perche' l'utente e' finito li'.
     */
    public static function mustChangePassword(): bool
    {
        if (!self::check()) {
            return false;
        }

        $stato = self::passwordState();

        return $stato !== null && (int) $stato['must_change_password'] === 1;
    }

    /**
     * Stato della password, letto una volta sola per richiesta: la stessa
     * pagina lo chiede al controllo della sessione e poi alla vista.
     *
     * @return array{password_changed_at: ?string, must_change_password: int, welcome_seen_at: ?string}|null
     */
    private static function passwordState(): ?array
    {
        if (self::$passwordState === false) {
            self::$passwordState = UserModel::passwordState((int) self::id());
        }

        return self::$passwordState;
    }

    /**
     * Gli stessi controlli di `guardSession()`, ma come risposta invece che
     * come rimando.
     *
     * Serve alle richieste che non sono navigazioni: un `fetch` che riceve
     * un 302 verso `/login` segue il rimando e si ritrova in mano la pagina
     * di accesso, senza capire che la sessione e' scaduta. Cosi' chi chiama
     * puo' rispondere 401 e lasciare che sia lo script a decidere.
     *
     * Non e' una scorciatoia intorno a `guardSession()`: chiede le stesse
     * cose — l'utente esiste ancora, la sessione non e' stata aperta con una
     * password poi cambiata, non c'e' un cambio password obbligatorio in
     * sospeso — e per le pagine vale sempre `requireLogin()`.
     */
    public static function sessionIsCurrent(): bool
    {
        if (!self::check()) {
            return false;
        }

        $stato = self::passwordState();

        if ($stato === null) {
            return false;
        }

        if (
            array_key_exists(self::SESSION_PASSWORD_STAMP, $_SESSION)
            && $_SESSION[self::SESSION_PASSWORD_STAMP] !== $stato['password_changed_at']
        ) {
            return false;
        }

        return (int) $stato['must_change_password'] !== 1;
    }

    /**
     * Allinea la sessione dopo che l'utente ha cambiato la propria password,
     * cosi' il browser da cui l'ha cambiata non viene chiuso insieme agli altri.
     */
    public static function refreshPasswordStamp(): void
    {
        self::$passwordState = false;
        $stato = self::passwordState();

        if ($stato !== null) {
            $_SESSION[self::SESSION_PASSWORD_STAMP] = $stato['password_changed_at'];
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();

        if (!self::hasRole(...$roles)) {
            http_response_code(403);
            exit('Accesso negato: permessi insufficienti per questa pagina.');
        }
    }

    /**
     * Verifica un permesso puntuale letto da role_permissions
     * (es. 'course.edit', 'quiz.grade_assigned').
     * Cache statica per richiesta: una sola query per ruolo.
     */
    public static function can(string $permissionKey): bool
    {
        if (!self::check()) {
            return false;
        }

        $role = self::role();

        if (!isset(self::$permissionCache[$role])) {
            self::$permissionCache[$role] = RolePermissionModel::keysForRole($role);
        }

        return in_array($permissionKey, self::$permissionCache[$role], true);
    }

    /**
     * Vero se l'utente ha almeno uno dei permessi indicati.
     */
    public static function canAny(string ...$permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if (self::can($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Richiede il login e almeno uno dei permessi indicati.
     */
    public static function requirePermission(string ...$permissionKeys): void
    {
        self::requireLogin();

        if (!self::canAny(...$permissionKeys)) {
            http_response_code(403);
            exit('Accesso negato: permessi insufficienti per questa azione.');
        }
    }

    /**
     * Svuota la cache dei permessi (dopo un salvataggio dal pannello admin).
     */
    public static function flushPermissionCache(): void
    {
        self::$permissionCache = [];
    }
}
