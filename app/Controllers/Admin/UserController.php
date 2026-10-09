<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\PersonName;
use App\Auth\Auth;
use App\Core\AvatarImage;
use App\Core\Csv;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\PasswordGenerator;
use App\Core\Upload;
use App\Core\Url;
use App\Core\Invites;
use App\Core\Settings;
use App\Core\View;
use App\Core\Xlsx;
use App\Models\EnrollmentModel;
use App\Models\GroupModel;
use App\Models\UserModel;

/**
 * Gestione utenti.
 *
 * Accesso con `user.manage` (admin). Un tutor con `assistant.manage` puo'
 * gestire soltanto i propri assistenti: vede solo loro e non puo' assegnare
 * altri ruoli ne' spostarli sotto un altro tutor.
 */
class UserController extends AdminController
{
    public function index(array $params = []): void
    {
        $this->requireUserAccess();

        View::render('admin/users/index', [
            'pageTitle' => 'Utenti',
            'users' => $this->visibleUsers(),
            'canManageAll' => Auth::can('user.manage'),
            // Gli inviti ancora da mandare. Si mostrano perche' un cron
            // fermo non si lamenta, e qui il silenzio vuol dire persone che
            // non riescono a entrare.
            'invitiInAttesa' => Auth::can('user.manage') ? UserModel::countPendingInvites() : 0,
            'ultimoInvio' => Settings::get(Invites::KEY_LAST_RUN),
            // Il pulsante XLSX non compare dove il server non puo' produrlo:
            // meglio non offrirlo che offrirlo e fallire.
            'canExportXlsx' => Xlsx::disponibile(),
        ]);
    }

    /**
     * Manda a mano il prossimo scaglione di inviti.
     *
     * E' la rete di sicurezza del cron: se la pianificazione non e' mai
     * stata creata, o si e' fermata, il numero in fondo alla pagina Utenti
     * non scende e da li' si puo' far partire uno scaglione con un clic.
     * Senza, un cron fermo non si lamenta — e qui non si tratta di un
     * avviso mancato ma di persone che non possono entrare.
     */
    public function sendInvites(array $params = []): void
    {
        Auth::requirePermission('user.manage');

        $esito = Invites::mandaScaglione();

        if ($esito['mandati'] === 0 && $esito['falliti'] === []) {
            $this->success('Non c\'erano inviti da mandare.', '/admin/users');
        }

        $messaggio = $esito['mandati'] . ($esito['mandati'] === 1 ? ' invito mandato' : ' inviti mandati')
            . '. In coda ne restano ' . $esito['restano'] . '.';

        if ($esito['falliti'] !== []) {
            $this->fail(
                $messaggio . ' Non sono partiti: ' . implode(', ', $esito['falliti'])
                . ' — restano in coda e riproveranno.',
                '/admin/users'
            );
        }

        $this->success($messaggio, '/admin/users');
    }

    public function createForm(array $params = []): void
    {
        $this->requireUserAccess();

        View::render('admin/users/form', [
            'pageTitle' => 'Nuovo utente',
            'user' => null,
            'tutors' => UserModel::byRoles(['tutor']),
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireUserAccess();

        $data = $this->dataFromPost('/admin/users/create');

        if ($data['email'] === '') {
            $this->fail('L\'email è obbligatoria.', '/admin/users/create');
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->fail('Indirizzo email non valido.', '/admin/users/create');
        }

        if (UserModel::emailExists($data['email'])) {
            $this->fail('Esiste già un utente con questa email.', '/admin/users/create');
        }

        $foto = $this->fotoNelModulo();
        $this->controllaFoto($data['role'], $foto, false, '/admin/users/create');

        // La password iniziale la genera la piattaforma e la manda all'utente,
        // come la temporanea di un account esistente: l'admin non la sceglie e
        // non la vede, e chi entra deve sceglierne una sua.
        $password = PasswordGenerator::genera();

        $newId = UserModel::create(
            $data['email'],
            $password,
            $data['first_name'],
            $data['last_name'],
            $data['role'],
            $data['is_active'],
            true,
            true
        );

        // La foto si salva nella cartella dell'utente, quindi dopo averlo
        // creato. Se non si salva, l'utente si toglie: un tutor senza foto non
        // deve esistere nemmeno per un momento, e nessuna email e' ancora
        // partita.
        if ($foto) {
            try {
                UserModel::updateAvatar($newId, AvatarImage::store($_FILES['avatar'], $newId));
            } catch (\RuntimeException $e) {
                UserModel::delete($newId);
                $this->fail($e->getMessage() . ' L\'utente non è stato creato.', '/admin/users/create');
            }
        }

        UserModel::setAssistantTutors($newId, $data['role'], $data['tutor_ids']);

        // Qui l'ordine e' rovesciato rispetto alla password temporanea di un
        // account esistente, e per un motivo: li' un invio fallito avrebbe
        // bruciato una password funzionante, qui non c'e' niente da rovinare.
        // Perdere l'utente appena compilato per un'email non partita sarebbe
        // solo un fastidio: l'account resta, e l'invio si ripete dalla sua
        // scheda.
        try {
            Mailer::send(Mailer::temporaryPassword(
                $data['email'],
                $data['full_name'],
                $password,
                Url::to('/login')
            ));
        } catch (MailException $e) {
            error_log('[Mail] ' . $e->getMessage());

            $this->fail(
                'Utente creato, ma l\'email con la password non e\' partita: cosi\' com\'e\' '
                . 'non puo\' ancora entrare. Controlla la configurazione della posta e usa '
                . '"Genera e invia password temporanea" dalla sua scheda.',
                '/admin/users/' . $newId . '/edit'
            );
        }

        $this->success(
            'Utente creato. La password iniziale e\' stata inviata a ' . $data['email']
            . ', e al primo accesso dovra\' sceglierne una sua.',
            '/admin/users'
        );
    }

    public function editForm(array $params): void
    {
        $this->requireUserAccess();

        $user = $this->findManageableUser((int) $params['id']);

        if ($user === null) {
            return;
        }

        $groups = GroupModel::forUser((int) $user['id']);
        $currentIds = array_map(static fn (array $g): int => (int) $g['id'], $groups);

        View::render('admin/users/form', [
            'pageTitle' => 'Modifica utente',
            'user' => $user,
            'tutors' => UserModel::byRoles(['tutor']),
            'assistantTutorIds' => UserModel::tutorIdsForAssistant((int) $user['id']),
            'roles' => $this->assignableRoles(),
            'groups' => $groups,
            'canManageGroups' => Auth::canAny('group.manage', 'group.manage_own'),
            // Solo i gruppi in cui questo utente puo' essere messo da chi guarda:
            // un tutor con `group.manage_own` vede soltanto i propri.
            'availableGroups' => array_values(array_filter(
                $this->manageableGroups(),
                static fn (array $g): bool => !in_array((int) $g['id'], $currentIds, true)
            )),
        ]);
    }

    public function update(array $params): void
    {
        $this->requireUserAccess();

        $user = $this->findManageableUser((int) $params['id']);

        if ($user === null) {
            return;
        }

        $id = (int) $user['id'];
        $redirect = '/admin/users/' . $id . '/edit';
        $data = $this->dataFromPost($redirect);

        if ($data['email'] === '') {
            $this->fail('L\'email è obbligatoria.', $redirect);
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->fail('Indirizzo email non valido.', $redirect);
        }

        if (UserModel::emailExists($data['email'], $id)) {
            $this->fail('Esiste già un altro utente con questa email.', $redirect);
        }

        // Un amministratore non puo' togliersi da solo il ruolo o disattivarsi
        // (si chiuderebbe fuori dal pannello), ne' si puo' restare senza admin attivi.
        if ($id === (int) Auth::id() && ($data['role'] !== 'admin' || !$data['is_active'])) {
            $this->fail('Non puoi modificare il tuo ruolo o disattivare il tuo account.', $redirect);
        }

        if (
            $user['role'] === 'admin'
            && ($data['role'] !== 'admin' || !$data['is_active'])
            && UserModel::countActiveAdmins($id) === 0
        ) {
            $this->fail('Deve restare almeno un amministratore attivo.', $redirect);
        }

        $foto = $this->fotoNelModulo();
        $this->controllaFoto($data['role'], $foto, !empty($user['avatar_path']), $redirect);

        // Prima la foto nuova sul disco, poi la tabella, e solo alla fine si
        // toglie la vecchia: un errore in mezzo non lascia l'utente senza.
        $nuovaFoto = null;

        if ($foto) {
            try {
                $nuovaFoto = AvatarImage::store($_FILES['avatar'], $id);
            } catch (\RuntimeException $e) {
                $this->fail($e->getMessage(), $redirect);
            }
        }

        UserModel::update(
            $id,
            $data['email'],
            $data['first_name'],
            $data['last_name'],
            $data['role'],
            $data['is_active']
        );
        UserModel::setAssistantTutors($id, $data['role'], $data['tutor_ids']);

        if ($nuovaFoto !== null) {
            UserModel::updateAvatar($id, $nuovaFoto);

            if (!empty($user['avatar_path'])) {
                @unlink(Upload::absolutePath((string) $user['avatar_path']));
            }
        }

        $this->success('Utente aggiornato.', '/admin/users');
    }

    /**
     * Reimpostazione password da parte dello staff.
     */
    /**
     * Genera una password temporanea e la manda all'utente.
     *
     * L'admin non la sceglie e non la vede: l'unica copia e' quella
     * nell'email, e in piattaforma resta solo la sua impronta. Chi entra con
     * questa password deve sceglierne una sua prima di fare altro.
     *
     * **L'ordine conta.** Prima si manda l'email, poi si scrive nel database:
     * se la posta non parte, la password vecchia resta valida. Al contrario,
     * un'email mai arrivata lascerebbe l'utente fuori dal proprio account
     * senza che nessuno se ne accorga.
     */
    public function generateTemporaryPassword(array $params): void
    {
        $this->requireUserAccess();

        $user = $this->findManageableUser((int) $params['id']);

        if ($user === null) {
            return;
        }

        $redirect = '/admin/users/' . (int) $user['id'] . '/edit';
        $password = PasswordGenerator::genera();

        try {
            Mailer::send(Mailer::temporaryPassword(
                (string) $user['email'],
                (string) $user['full_name'],
                $password,
                Url::to('/login')
            ));
        } catch (MailException $e) {
            error_log('[Mail] ' . $e->getMessage());

            $this->fail(
                'Invio dell\'email non riuscito: la password non e\' stata cambiata e quella '
                . 'attuale resta valida. Controlla la configurazione della posta e riprova.',
                $redirect
            );
        }

        UserModel::updatePassword((int) $user['id'], $password, true);

        $this->success(
            'Password temporanea inviata a ' . $user['email'] . '. Le sessioni aperte di '
            . 'questo utente sono state chiuse, e al primo accesso dovra\' scegliere una '
            . 'password sua.',
            $redirect
        );
    }

    public function destroy(array $params): void
    {
        $this->requireUserAccess();

        $user = $this->findManageableUser((int) $params['id']);

        if ($user === null) {
            return;
        }

        $id = (int) $user['id'];

        if ($id === (int) Auth::id()) {
            $this->fail('Non puoi eliminare il tuo account.', '/admin/users');
        }

        if ($user['role'] === 'admin' && UserModel::countActiveAdmins($id) === 0) {
            $this->fail('Deve restare almeno un amministratore attivo.', '/admin/users');
        }

        if (UserModel::countCoursesCreated($id) > 0) {
            $this->fail(
                'Questo utente risulta autore di uno o più corsi e non può essere eliminato: disattivalo.',
                '/admin/users'
            );
        }

        // L'immagine del profilo sta su disco: la cascata del database non la
        // tocca, quindi va rimossa qui.
        if (!empty($user['avatar_path'])) {
            @unlink(Upload::absolutePath((string) $user['avatar_path']));
            @rmdir(Upload::absolutePath('avatars/' . $id));
        }

        // Iscrizioni, progressi, tentativi e certificati vengono eliminati a
        // cascata insieme all'utente: per conservarli, disattivarlo e' preferibile.
        UserModel::delete($id);

        $this->success('Utente eliminato.', '/admin/users');
    }

    // ---------------------------------------------------------------
    // Gruppi dell'utente
    // ---------------------------------------------------------------
    // Le stesse azioni esistono sulla scheda del gruppo: qui si parte
    // dall'utente, perche' e' da li' che si guarda quando ci si chiede a quali
    // gruppi appartiene.

    public function addGroup(array $params): void
    {
        $this->requireUserAccess();

        $user = $this->findManageableUser((int) $params['id']);

        if ($user === null) {
            return;
        }

        $userId = (int) $user['id'];
        $redirect = '/admin/users/' . $userId . '/edit';
        $group = $this->findManageableGroup((int) ($_POST['group_id'] ?? 0));

        if ($group === null) {
            $this->fail('Gruppo non disponibile.', $redirect);
        }

        $groupId = (int) $group['id'];
        GroupModel::addMember($groupId, $userId);

        // Come dalla scheda del gruppo: chi entra eredita i corsi assegnati.
        $created = EnrollmentModel::enrollMany([$userId], GroupModel::courseIds($groupId));

        $this->success(
            $created > 0
                ? 'Utente aggiunto al gruppo e iscritto a ' . $created . ' corsi.'
                : 'Utente aggiunto al gruppo.',
            $redirect
        );
    }

    public function removeGroup(array $params): void
    {
        $this->requireUserAccess();

        $user = $this->findManageableUser((int) $params['id']);

        if ($user === null) {
            return;
        }

        $redirect = '/admin/users/' . (int) $user['id'] . '/edit';
        $group = $this->findManageableGroup((int) $params['groupId']);

        if ($group === null) {
            $this->fail('Gruppo non disponibile.', $redirect);
        }

        GroupModel::removeMember((int) $group['id'], (int) $user['id']);

        $this->success(
            'Utente rimosso dal gruppo. Le iscrizioni ai corsi restano attive: rimuovile dalla scheda del corso se necessario.',
            $redirect
        );
    }

    // ---------------------------------------------------------------
    // Elenco scaricabile
    // ---------------------------------------------------------------

    /**
     * Solo `user.manage`, non `assistant.manage`: un tutor che gestisce i
     * propri assistenti non scarica l'anagrafica di tutta la piattaforma.
     */
    public function exportCsv(array $params = []): void
    {
        Auth::requirePermission('user.manage');

        [$intestazione, $righe, $tipi] = $this->exportTable();

        // Nel CSV le date diventano testo leggibile: non c'e' un tipo "data"
        // da dichiarare, e la forma ISO del database si legge male. Nel foglio
        // di calcolo restano date vere, cosi' l'ordinamento funziona.
        foreach ($righe as $indice => $riga) {
            foreach ($tipi as $colonna => $tipo) {
                if ($tipo === 'data' && ($riga[$colonna] ?? null) !== null) {
                    $righe[$indice][$colonna] = date('d/m/Y H:i', (int) strtotime((string) $riga[$colonna]));
                }
            }
        }

        Csv::send('utenti-' . date('Y-m-d') . '.csv', $intestazione, $righe);
    }

    public function exportXlsx(array $params = []): void
    {
        Auth::requirePermission('user.manage');

        if (!Xlsx::disponibile()) {
            $this->fail(
                'Il foglio di calcolo richiede l\'estensione zip di PHP, che su questo server non e\' attiva. L\'elenco in CSV funziona comunque.',
                '/admin/users'
            );
        }

        [$intestazione, $righe, $tipi] = $this->exportTable();

        Xlsx::send('utenti-' . date('Y-m-d') . '.xlsx', $intestazione, $righe, $tipi, 'Utenti');
    }

    /**
     * Una sola descrizione delle colonne per tutti e due i formati: due
     * elenchi separati finirebbero per divergere, come e' successo fra
     * pannello e catalogo (pistacchio-lms.md Sezione 4).
     *
     * @return array{0: string[], 1: array<int, array<int, string|int|null>>, 2: array<int, string>}
     */
    private function exportTable(): array
    {
        $intestazione = [
            'ID', 'Nome', 'Email', 'Ruolo', 'Stato', 'Email verificata',
            'Tutor di riferimento', 'Telefono', 'Citta', 'Immagine',
            'Registrato il', 'Ultima modifica',
        ];

        $tipi = [
            'numero', 'testo', 'testo', 'testo', 'testo', 'data',
            'testo', 'testo', 'testo', 'testo',
            'data', 'data',
        ];

        $righe = [];

        foreach (UserModel::allForExport() as $utente) {
            $righe[] = [
                (int) $utente['id'],
                (string) $utente['full_name'],
                (string) $utente['email'],
                Auth::roleLabel($utente['role'] ?? null),
                (int) $utente['is_active'] === 1 ? 'attivo' : 'disattivato',
                $utente['email_verified_at'],
                (string) ($utente['tutor_riferimento'] ?? ''),
                (string) ($utente['phone'] ?? ''),
                (string) ($utente['city'] ?? ''),
                ($utente['avatar_path'] ?? null) === null ? 'no' : 'si',
                $utente['created_at'],
                $utente['updated_at'],
            ];
        }

        return [$intestazione, $righe, $tipi];
    }

    // ---------------------------------------------------------------

    /**
     * Se il modulo porta una foto del profilo (09/10). La carica solo chi ha
     * `user.manage`: chi gestisce i propri assistenti non vede il campo, e
     * un file mandato lo stesso viene ignorato.
     */
    private function fotoNelModulo(): bool
    {
        return Auth::can('user.manage') && !empty($_FILES['avatar']['name']);
    }

    /**
     * Le due regole della foto dal pannello (09/10, decise da Alessandro):
     *
     *   - **un tutor ha sempre una foto del profilo** (`AvatarImage::obbligatoria()`):
     *     non si crea, e non si fa diventare tutor, un utente senza;
     *   - **dal pannello la foto si carica solo ai tutor.** Per gli altri ruoli
     *     e' facoltativa e la sceglie la persona dal proprio profilo: l'admin
     *     non mette una faccia a uno studente.
     */
    private function controllaFoto(string $ruolo, bool $haFile, bool $haGia, string $siSbaglia): void
    {
        if ($haFile && !AvatarImage::obbligatoria($ruolo)) {
            $this->fail(
                'Dal pannello la foto si carica solo ai tutor: per gli altri ruoli è facoltativa, '
                . 'e la persona la sceglie dal proprio profilo.',
                $siSbaglia
            );
        }

        if (AvatarImage::obbligatoria($ruolo) && !$haFile && !$haGia) {
            $this->fail(
                'Un tutor deve avere una foto del profilo: caricala nel campo «Foto del profilo». '
                . 'È quella che i suoi studenti vedono nella pagina del gruppo e nel benvenuto in cima ai corsi.',
                $siSbaglia
            );
        }
    }

    private function requireUserAccess(): void
    {
        Auth::requirePermission('user.manage', 'assistant.manage');
    }

    /**
     * Chi ha solo `assistant.manage` vede esclusivamente i propri assistenti.
     */
    private function visibleUsers(): array
    {
        return Auth::can('user.manage')
            ? UserModel::all()
            : UserModel::assistantsForTutor((int) Auth::id());
    }

    private function findManageableUser(int $id): ?array
    {
        $user = UserModel::find($id);

        if ($user === null) {
            http_response_code(404);
            echo 'Utente non trovato.';

            return null;
        }

        if (!Auth::can('user.manage')) {
            $isOwnAssistant = $user['role'] === 'assistente'
                && in_array((int) Auth::id(), UserModel::tutorIdsForAssistant((int) $user['id']), true);

            if (!$isOwnAssistant) {
                http_response_code(403);
                echo 'Puoi gestire soltanto i tuoi assistenti.';

                return null;
            }
        }

        return $user;
    }

    /**
     * Gruppi su cui chi guarda ha potere: tutti con `group.manage`, i propri
     * con `group.manage_own`, nessuno altrimenti.
     */
    private function manageableGroups(): array
    {
        if (Auth::can('group.manage')) {
            return GroupModel::all();
        }

        if (Auth::can('group.manage_own')) {
            return GroupModel::forTutor((int) Auth::id());
        }

        return [];
    }

    private function findManageableGroup(int $groupId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }

        foreach ($this->manageableGroups() as $group) {
            if ((int) $group['id'] === $groupId) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return string[] ruoli assegnabili dall'utente corrente
     */
    private function assignableRoles(): array
    {
        return Auth::can('user.manage') ? Auth::ROLES : ['assistente'];
    }

    /**
     * Nome e cognome si controllano qui (07/10): un errore rimanda a
     * `$siSbaglia` con la frase di `PersonName::clean()`.
     *
     * @return array{email: string, first_name: string, last_name: string, full_name: string,
     *               role: string, tutor_ids: int[], is_active: bool}
     */
    private function dataFromPost(string $siSbaglia): array
    {
        try {
            [$firstName, $lastName] = PersonName::clean(
                (string) ($_POST['first_name'] ?? ''),
                (string) ($_POST['last_name'] ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage(), $siSbaglia);
        }

        $role = (string) ($_POST['role'] ?? 'studente');
        $roles = $this->assignableRoles();

        if (!in_array($role, $roles, true)) {
            $role = $roles[0];
        }

        // Un assistente puo' affiancare piu' tutor: arrivano come elenco di caselle.
        $tutorIds = array_values(array_filter(
            array_map('intval', (array) ($_POST['tutor_ids'] ?? [])),
            static fn (int $v): bool => $v > 0
        ));

        // Chi non ha user.manage non sceglie i tutor: se mai qualcuno gestisse
        // assistenti con il solo assistant.manage, li terrebbe sotto di se'.
        if (!Auth::can('user.manage')) {
            $tutorIds = [(int) Auth::id()];
        }

        return [
            'email' => trim((string) ($_POST['email'] ?? '')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $firstName . ' ' . $lastName,
            'role' => $role,
            'tutor_ids' => $tutorIds,
            'is_active' => isset($_POST['is_active']),
        ];
    }
}
