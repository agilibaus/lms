<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Upload;
use App\Core\View;
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
    private const MIN_PASSWORD_LENGTH = 8;

    public function index(array $params = []): void
    {
        $this->requireUserAccess();

        View::render('admin/users/index', [
            'pageTitle' => 'Utenti',
            'users' => $this->visibleUsers(),
            'canManageAll' => Auth::can('user.manage'),
        ]);
    }

    public function createForm(array $params = []): void
    {
        $this->requireUserAccess();

        View::render('admin/users/form', [
            'pageTitle' => 'Nuovo utente',
            'user' => null,
            'tutors' => UserModel::byRoles(['tutor']),
            'roles' => $this->assignableRoles(),
            'minPasswordLength' => self::MIN_PASSWORD_LENGTH,
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireUserAccess();

        $data = $this->dataFromPost();
        $password = (string) ($_POST['password'] ?? '');

        if ($data['email'] === '' || $data['full_name'] === '') {
            $this->fail('Nome ed email sono obbligatori.', '/admin/users/create');
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->fail('Indirizzo email non valido.', '/admin/users/create');
        }

        if (UserModel::emailExists($data['email'])) {
            $this->fail('Esiste già un utente con questa email.', '/admin/users/create');
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->fail('La password deve avere almeno ' . self::MIN_PASSWORD_LENGTH . ' caratteri.', '/admin/users/create');
        }

        UserModel::create(
            $data['email'],
            $password,
            $data['full_name'],
            $data['role'],
            $data['supervising_tutor_id'],
            $data['is_active']
        );

        $this->success('Utente creato.', '/admin/users');
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
            'roles' => $this->assignableRoles(),
            'minPasswordLength' => self::MIN_PASSWORD_LENGTH,
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
        $data = $this->dataFromPost();

        if ($data['email'] === '' || $data['full_name'] === '') {
            $this->fail('Nome ed email sono obbligatori.', $redirect);
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

        UserModel::update(
            $id,
            $data['email'],
            $data['full_name'],
            $data['role'],
            $data['supervising_tutor_id'],
            $data['is_active']
        );

        $this->success('Utente aggiornato.', '/admin/users');
    }

    /**
     * Reimpostazione password da parte dello staff.
     */
    public function resetPassword(array $params): void
    {
        $this->requireUserAccess();

        $user = $this->findManageableUser((int) $params['id']);

        if ($user === null) {
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $redirect = '/admin/users/' . (int) $user['id'] . '/edit';

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->fail('La password deve avere almeno ' . self::MIN_PASSWORD_LENGTH . ' caratteri.', $redirect);
        }

        UserModel::updatePassword((int) $user['id'], $password);

        $this->success('Password aggiornata.', $redirect);
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
                && (int) ($user['supervising_tutor_id'] ?? 0) === (int) Auth::id();

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
     * @return array{email: string, full_name: string, role: string, supervising_tutor_id: int|null, is_active: bool}
     */
    private function dataFromPost(): array
    {
        $role = (string) ($_POST['role'] ?? 'studente');
        $roles = $this->assignableRoles();

        if (!in_array($role, $roles, true)) {
            $role = $roles[0];
        }

        $tutorId = (int) ($_POST['supervising_tutor_id'] ?? 0);

        // Un tutor che gestisce i propri assistenti li tiene necessariamente sotto di se'.
        if (!Auth::can('user.manage')) {
            $tutorId = (int) Auth::id();
        }

        return [
            'email' => trim((string) ($_POST['email'] ?? '')),
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'role' => $role,
            'supervising_tutor_id' => $tutorId > 0 ? $tutorId : null,
            'is_active' => isset($_POST['is_active']),
        ];
    }
}
