<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\View;
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

        View::render('admin/users/form', [
            'pageTitle' => 'Modifica utente',
            'user' => $user,
            'tutors' => UserModel::byRoles(['tutor']),
            'roles' => $this->assignableRoles(),
            'minPasswordLength' => self::MIN_PASSWORD_LENGTH,
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

        // Iscrizioni, progressi, tentativi e certificati vengono eliminati a
        // cascata insieme all'utente: per conservarli, disattivarlo e' preferibile.
        UserModel::delete($id);

        $this->success('Utente eliminato.', '/admin/users');
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
