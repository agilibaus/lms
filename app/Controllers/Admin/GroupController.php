<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\TutorWelcome;
use App\Auth\Auth;
use App\Core\CampoContato;
use App\Core\GroupLogo;
use App\Core\Upload;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\GroupModel;
use App\Models\GroupModuleDateModel;
use App\Models\ModuleModel;
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
    /** La descrizione del gruppo: 1.000 caratteri (10/10, Alessandro). */
    public const MAX_DESCRIPTION_CHARS = 1000;

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
        // Crea ed elimina i gruppi, e assegna loro i corsi, solo l'admin
        // (26/10, Alessandro): il gruppo e' la classe, e i suoi corsi
        // decidono il perimetro del tutor.
        Auth::requirePermission('group.manage');

        View::render('admin/groups/form', [
            'pageTitle' => 'Nuovo gruppo',
            'group' => null,
            'tutors' => UserModel::byRoles(['tutor']),
            'canChooseTutor' => Auth::can('group.manage'),
        ]);
    }

    public function store(array $params = []): void
    {
        Auth::requirePermission('group.manage');

        $data = $this->dataFromPost();

        if ($data['name'] === '') {
            $this->fail('Il nome del gruppo è obbligatorio.', '/admin/groups/create');
        }

        try {
            $whatsapp = TutorWelcome::whatsappUrl((string) ($_POST['whatsapp_url'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage(), '/admin/groups/create');
        }

        $groupId = GroupModel::create($data['name'], $data['description'], $data['tutor_id']);
        GroupModel::updateWhatsapp($groupId, $whatsapp);

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
            // Dal 26/10: crea, elimina e assegna corsi solo l'admin; i membri
            // l'admin e, con «Membri dei gruppi» acceso, il tutor.
            'gestisceTutto' => Auth::can('group.manage'),
            'gestisceMembri' => Auth::can('group.manage') || Auth::can('group.members_own'),
            // Candidati: per l'admin chi non e' gia' dentro; per il tutor solo
            // gli iscritti a un corso del gruppo senza un gruppo in quel corso.
            'availableStudents' => Auth::can('group.manage')
                ? array_values(array_filter(
                    UserModel::byRoles(['studente']),
                    static fn (array $u): bool => !in_array((int) $u['id'], $memberIds, true)
                ))
                : (Auth::can('group.members_own') ? GroupModel::candidati((int) $group['id']) : []),
            'availableCourses' => !Auth::can('group.manage') ? [] : array_values(array_filter(
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

        try {
            $whatsapp = TutorWelcome::whatsappUrl((string) ($_POST['whatsapp_url'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage(), $redirect);
        }

        GroupModel::update((int) $group['id'], $data['name'], $data['description'], $tutorId);
        GroupModel::updateWhatsapp((int) $group['id'], $whatsapp);

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
        Auth::requirePermission('group.manage');

        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        GroupModel::delete((int) $group['id']);

        // Il logo va via con il gruppo (13/10), come fa «Rimuovi immagine» e
        // come la foto di un utente eliminato: nessuna riga lo nomina piu'.
        GroupLogo::delete(!empty($group['logo_path']) ? (string) $group['logo_path'] : null);

        $this->success('Gruppo eliminato. Le iscrizioni ai corsi restano attive.', '/admin/groups');
    }

    // ---------------------------------------------------------------
    // Membri
    // ---------------------------------------------------------------

    public function addMember(array $params): void
    {
        $group = $this->gruppoPerIMembri((int) $params['id']);

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

        // Il tutor aggiunge solo chi e' gia' iscritto a un corso del gruppo e
        // in quel corso non ha ancora un gruppo (26/10): non sceglie fra tutti
        // gli studenti della piattaforma.
        if (!Auth::can('group.manage')
            && !in_array($userId, array_map(static fn (array $u): int => (int) $u['id'], GroupModel::candidati($groupId)), true)) {
            $this->fail('Puoi aggiungere solo studenti già iscritti a un corso del gruppo e senza un gruppo in quel corso.', $redirect);
        }

        GroupModel::addMember($groupId, $userId);

        // Chi entra nel gruppo viene iscritto ai corsi gia' assegnati.
        // L'«Iscrizione confermata» la manda bin/avvisi (12/10).
        $created = EnrollmentModel::enrollMany([$userId], GroupModel::courseIds($groupId), true);

        $this->success(
            $created > 0
                ? 'Membro aggiunto e iscritto a ' . $created . ' corsi del gruppo.'
                : 'Membro aggiunto.',
            $redirect
        );
    }

    public function removeMember(array $params): void
    {
        $group = $this->gruppoPerIMembri((int) $params['id']);

        if ($group === null) {
            return;
        }

        GroupModel::removeMember((int) $group['id'], (int) $params['userId']);

        $this->success(
            Auth::can('group.manage')
                ? 'Membro rimosso dal gruppo. Le iscrizioni ai corsi restano attive: rimuovile dalla scheda del corso se necessario.'
                : 'Membro rimosso dal gruppo. Le iscrizioni ai corsi restano attive: le gestisce l’amministrazione.',
            '/admin/groups/' . (int) $group['id'] . '/edit'
        );
    }

    /**
     * Il gruppo di cui chi chiede gestisce i membri: l'admin tutti, il tutor
     * i suoi se l'interruttore «Membri dei gruppi» e' acceso (26/10,
     * `group.members_own`). Altrimenti 403, o 404 se il gruppo non c'e'.
     *
     * @return array<string, mixed>|null
     */
    private function gruppoPerIMembri(int $groupId): ?array
    {
        $this->requireGroupAccess();

        if (!Auth::can('group.manage') && !Auth::can('group.members_own')) {
            $this->forbidden('I membri del gruppo li gestisce l\'amministrazione.');

            return null;
        }

        return $this->findManageableGroup($groupId);
    }

    // ---------------------------------------------------------------
    // Corsi assegnati
    // ---------------------------------------------------------------

    public function addCourse(array $params): void
    {
        Auth::requirePermission('group.manage');

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

        // Le «Iscrizione confermata» le manda bin/avvisi, a scaglioni: un
        // gruppo grande non le spedisce tutte insieme da questa pagina (12/10).
        $created = EnrollmentModel::enrollMany(GroupModel::memberIds($groupId), [$courseId], true);

        $this->success(
            $created > 0
                ? 'Corso assegnato: iscritti ' . $created . ' membri.'
                : 'Corso assegnato.',
            $redirect
        );
    }

    public function removeCourse(array $params): void
    {
        Auth::requirePermission('group.manage');

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

    /**
     * «Date dei moduli» (26/10, Alessandro, conflitto 3): le date di apertura
     * dei moduli di un corso per questo gruppo. Le gestisce chi gestisce il
     * gruppo, solo per i corsi assegnati al gruppo.
     */
    public function moduleDatesForm(array $params): void
    {
        [$group, $course] = $this->groupCourse($params);

        if ($group === null) {
            return;
        }

        View::render('admin/groups/module_dates', [
            'pageTitle' => 'Date dei moduli',
            'group' => $group,
            'course' => $course,
            'modules' => ModuleModel::forCourse((int) $course['id']),
            'dates' => GroupModuleDateModel::forGroupCourse((int) $group['id'], (int) $course['id']),
        ]);
    }

    public function saveModuleDates(array $params): void
    {
        [$group, $course] = $this->groupCourse($params);

        if ($group === null) {
            return;
        }

        $date = [];

        foreach ((array) ($_POST['date'] ?? []) as $moduleId => $valore) {
            $date[(int) $moduleId] = self::dataDalCampo((string) $valore);
        }

        GroupModuleDateModel::save((int) $group['id'], (int) $course['id'], $date);
        $this->success('Date dei moduli salvate.', '/admin/groups/' . (int) $group['id'] . '/edit');
    }

    /**
     * Il gruppo, se chi chiede lo gestisce, e il corso, se e' assegnato al
     * gruppo; altrimenti risponde (403 o 404) e torna [null, null].
     *
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function groupCourse(array $params): array
    {
        $this->requireGroupAccess();
        $group = $this->findManageableGroup((int) $params['id']);

        if ($group === null) {
            return [null, null];
        }

        $courseId = (int) $params['courseId'];
        $course = in_array($courseId, GroupModel::courseIds((int) $group['id']), true) ? CourseModel::find($courseId) : null;

        if ($course === null) {
            $this->notFound('Corso non assegnato a questo gruppo.');

            return [null, null];
        }

        return [$group, $course];
    }

    /**
     * Il valore di un campo `datetime-local`, o null se vuoto: il gruppo
     * segue la data del corso. Come per «Disponibile dal» del modulo, una
     * data incomprensibile (un POST costruito a mano) diventa null.
     */
    private static function dataDalCampo(string $valore): ?string
    {
        $valore = trim($valore);

        if ($valore === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $valore)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $valore);

        return $data === false ? null : $data->format('Y-m-d H:i:s');
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
        $description = CampoContato::taglia((string) ($_POST['description'] ?? ''), self::MAX_DESCRIPTION_CHARS);
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
