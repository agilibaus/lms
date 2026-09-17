<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\GroupLogo;
use App\Core\Upload;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\GroupModel;
use App\Models\UserModel;

/**
 * Gestione gruppi (classi/coorti): membri e corsi assegnati.
 *
 * `group.manage` vede e modifica tutti i gruppi; `group.manage_own` solo quelli
 * di cui l'utente e' tutor. Assegnare un corso al gruppo iscrive i membri al
 * corso, e chi entra dopo viene iscritto ai corsi gia' assegnati.
 */
class GroupController extends AdminController
{
    public function index(array $params = []): void
    {
        $this->requireGroupAccess();

        View::render('admin/groups/index', [
            'pageTitle' => 'Gruppi',
            'groups' => $this->visibleGroups(),
            'canManageAll' => Auth::can('group.manage'),
        ]);
    }

    public function createForm(array $params = []): void
    {
        $this->requireGroupAccess();

        View::render('admin/groups/form', [
            'pageTitle' => 'Nuovo gruppo',
            'group' => null,
            'tutors' => UserModel::byRoles(['tutor']),
            'canChooseTutor' => Auth::can('group.manage'),
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireGroupAccess();

        $data = $this->dataFromPost();

        if ($data['name'] === '') {
            $this->fail('Il nome del gruppo è obbligatorio.', '/admin/groups/create');
        }

        $groupId = GroupModel::create($data['name'], $data['description'], $data['tutor_id']);

        // Il logo si salva dopo la creazione: il percorso contiene l'id, che
        // prima di questo momento non esiste.
        $errore = $this->storeLogo($groupId, null);

        if ($errore !== null) {
            $this->fail('Gruppo creato, ma l\'immagine non è stata caricata: ' . $errore,
                '/admin/groups/' . $groupId . '/edit');
        }

        $this->success('Gruppo creato.', '/admin/groups/' . $groupId . '/edit');
    }

    public function editForm(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        $memberIds = GroupModel::memberIds((int) $group['id']);
        $courseIds = GroupModel::courseIds((int) $group['id']);

        View::render('admin/groups/edit', [
            'pageTitle' => $group['name'],
            'group' => $group,
            'tutors' => UserModel::byRoles(['tutor']),
            'canChooseTutor' => Auth::can('group.manage'),
            'members' => GroupModel::members((int) $group['id']),
            'courses' => GroupModel::courses((int) $group['id']),
            // Candidati: solo chi non e' gia' dentro.
            'availableStudents' => array_values(array_filter(
                UserModel::byRoles(['studente']),
                static fn (array $u): bool => !in_array((int) $u['id'], $memberIds, true)
            )),
            'availableCourses' => array_values(array_filter(
                CourseModel::allForStaff(),
                static fn (array $c): bool => !in_array((int) $c['id'], $courseIds, true)
            )),
        ]);
    }

    public function update(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        $redirect = '/admin/groups/' . (int) $group['id'] . '/edit';
        $data = $this->dataFromPost();

        if ($data['name'] === '') {
            $this->fail('Il nome del gruppo è obbligatorio.', $redirect);
        }

        // Chi gestisce solo i propri gruppi non puo' cederne la titolarita'.
        $tutorId = Auth::can('group.manage') ? $data['tutor_id'] : (int) $group['tutor_id'];

        GroupModel::update((int) $group['id'], $data['name'], $data['description'], $tutorId);

        $errore = $this->storeLogo((int) $group['id'], $group['logo_path'] ?? null);

        if ($errore !== null) {
            $this->fail('Gruppo aggiornato, ma l\'immagine non è stata caricata: ' . $errore, $redirect);
        }

        $this->success('Gruppo aggiornato.', $redirect);
    }

    /**
     * Serve il logo. Sta in /storage come ogni altro file caricato, quindi
     * passa di qui invece che da Apache. Basta aver fatto accesso: il logo
     * compare accanto al nome anche a chi il gruppo non lo gestisce.
     */
    public function logo(array $params): void
    {
        Auth::requireLogin();

        $group = GroupModel::find((int) $params['id']);
        $stored = (string) ($group['logo_path'] ?? '');

        if ($group === null || $stored === '') {
            http_response_code(404);
            echo 'Nessun logo per questo gruppo.';
            return;
        }

        $absolute = Upload::absolutePath($stored);

        if (!is_file($absolute)) {
            http_response_code(404);
            echo 'Logo non trovato.';
            return;
        }

        header('Content-Type: ' . GroupLogo::mimeFor($stored));
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        // Il nome del file cambia a ogni caricamento, quindi la cache lunga
        // non fa mai vedere il logo vecchio.
        header('Cache-Control: private, max-age=604800');
        readfile($absolute);
        exit;
    }

    public function deleteLogo(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        $current = (string) ($group['logo_path'] ?? '');

        GroupModel::updateLogo((int) $group['id'], null);
        GroupLogo::delete($current === '' ? null : $current);

        $this->success('Immagine rimossa.', '/admin/groups/' . (int) $group['id'] . '/edit');
    }

    /**
     * Salva l'immagine se ne e' stata scelta una, eliminando la precedente.
     *
     * @return string|null il messaggio d'errore, oppure null se e' andata
     */
    private function storeLogo(int $groupId, ?string $previous): ?string
    {
        if (empty($_FILES['logo']['name'])) {
            return null;
        }

        try {
            $path = GroupLogo::store($_FILES['logo'], $groupId);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        // Prima la riga, poi il file vecchio: al contrario, un errore in mezzo
        // lascerebbe il gruppo a puntare a un file che non c'e' piu'.
        GroupModel::updateLogo($groupId, $path);
        GroupLogo::delete($previous);

        return null;
    }

    public function destroy(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        GroupModel::delete((int) $group['id']);

        $this->success('Gruppo eliminato. Le iscrizioni ai corsi restano attive.', '/admin/groups');
    }

    // ---------------------------------------------------------------
    // Membri
    // ---------------------------------------------------------------

    public function addMember(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        $groupId = (int) $group['id'];
        $redirect = '/admin/groups/' . $groupId . '/edit';
        $userId = (int) ($_POST['user_id'] ?? 0);
        $user = UserModel::find($userId);

        if ($user === null) {
            $this->fail('Utente non trovato.', $redirect);
        }

        GroupModel::addMember($groupId, $userId);

        // Chi entra nel gruppo viene iscritto ai corsi gia' assegnati.
        $created = EnrollmentModel::enrollMany([$userId], GroupModel::courseIds($groupId));

        $this->success(
            $created > 0
                ? 'Membro aggiunto e iscritto a ' . $created . ' corsi del gruppo.'
                : 'Membro aggiunto.',
            $redirect
        );
    }

    public function removeMember(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        GroupModel::removeMember((int) $group['id'], (int) $params['userId']);

        $this->success(
            'Membro rimosso dal gruppo. Le iscrizioni ai corsi restano attive: rimuovile dalla scheda del corso se necessario.',
            '/admin/groups/' . (int) $group['id'] . '/edit'
        );
    }

    // ---------------------------------------------------------------
    // Corsi assegnati
    // ---------------------------------------------------------------

    public function addCourse(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        $groupId = (int) $group['id'];
        $redirect = '/admin/groups/' . $groupId . '/edit';
        $courseId = (int) ($_POST['course_id'] ?? 0);

        if (CourseModel::find($courseId) === null) {
            $this->fail('Corso non trovato.', $redirect);
        }

        GroupModel::addCourse($groupId, $courseId);

        $created = EnrollmentModel::enrollMany(GroupModel::memberIds($groupId), [$courseId]);

        $this->success(
            $created > 0
                ? 'Corso assegnato: iscritti ' . $created . ' membri.'
                : 'Corso assegnato.',
            $redirect
        );
    }

    public function removeCourse(array $params): void
    {
        $this->requireGroupAccess();

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        GroupModel::removeCourse((int) $group['id'], (int) $params['courseId']);

        $this->success(
            'Corso rimosso dal gruppo. Gli iscritti restano tali: rimuovili dalla scheda del corso se necessario.',
            '/admin/groups/' . (int) $group['id'] . '/edit'
        );
    }

    // ---------------------------------------------------------------

    private function requireGroupAccess(): void
    {
        Auth::requirePermission('group.manage', 'group.manage_own');
    }

    private function visibleGroups(): array
    {
        return Auth::can('group.manage')
            ? GroupModel::all()
            : GroupModel::forTutor((int) Auth::id());
    }

    private function findManageableGroup(int $id): ?array
    {
        $group = GroupModel::find($id);

        if ($group === null) {
            $this->notFound('Gruppo non trovato.');

            return null;
        }

        if (!Auth::can('group.manage') && (int) ($group['tutor_id'] ?? 0) !== (int) Auth::id()) {
            $this->forbidden('Puoi gestire soltanto i gruppi di cui sei tutor.');

            return null;
        }

        return $group;
    }

    /**
     * @return array{name: string, description: string|null, tutor_id: int|null}
     */
    private function dataFromPost(): array
    {
        $description = trim((string) ($_POST['description'] ?? ''));
        $tutorId = (int) ($_POST['tutor_id'] ?? 0);

        // Un tutor che crea un gruppo ne diventa automaticamente responsabile.
        if (!Auth::can('group.manage')) {
            $tutorId = (int) Auth::id();
        }

        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => $description === '' ? null : $description,
            'tutor_id' => $tutorId > 0 ? $tutorId : null,
        ];
    }
}
