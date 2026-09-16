<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Mail\Mailer;
use App\Core\Url;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\EnrollmentRequestModel;
use App\Models\UserModel;

/**
 * Gestione corsi e iscrizioni individuali.
 *
 * Creazione con `course.create`, modifica con `course.edit`,
 * eliminazione con `course.delete`.
 */
class CourseController extends AdminController
{
    public function index(array $params = []): void
    {
        Auth::requirePermission('course.create', 'course.edit', 'course.delete');

        View::render('admin/courses/index', [
            'pageTitle' => 'Gestione corsi',
            'courses' => CourseModel::allForStaff(),
            'canCreate' => Auth::can('course.create'),
            'canDelete' => Auth::can('course.delete'),
            'pendingRequests' => EnrollmentRequestModel::countPending(),
        ]);
    }

    public function createForm(array $params = []): void
    {
        Auth::requirePermission('course.create');

        View::render('admin/courses/form', [
            'pageTitle' => 'Nuovo corso',
            'course' => null,
        ]);
    }

    public function store(array $params = []): void
    {
        Auth::requirePermission('course.create');

        $data = $this->dataFromPost();

        if ($data['title'] === '') {
            $this->fail('Il titolo del corso è obbligatorio.', '/admin/courses/create');
        }

        $courseId = CourseModel::create(
            $data['title'],
            CourseModel::uniqueSlug($data['slug'] !== '' ? $data['slug'] : $data['title']),
            $data['description'],
            $data['is_published'],
            (int) Auth::id(),
            $data['enrollment_mode']
        );

        $this->success('Corso creato.', '/admin/courses/' . $courseId . '/edit');
    }

    public function editForm(array $params): void
    {
        Auth::requirePermission('course.edit');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $enrolled = EnrollmentModel::forCourse((int) $course['id']);
        $enrolledIds = array_map(static fn (array $row): int => (int) $row['user_id'], $enrolled);

        View::render('admin/courses/edit', [
            'pageTitle' => $course['title'],
            'course' => $course,
            'requests' => EnrollmentRequestModel::pendingForCourse((int) $course['id']),
            'enrollments' => $enrolled,
            'availableStudents' => array_values(array_filter(
                UserModel::byRoles(['studente']),
                static fn (array $u): bool => !in_array((int) $u['id'], $enrolledIds, true)
            )),
            'canDelete' => Auth::can('course.delete'),
        ]);
    }

    public function update(array $params): void
    {
        Auth::requirePermission('course.edit');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $id = (int) $course['id'];
        $redirect = '/admin/courses/' . $id . '/edit';
        $data = $this->dataFromPost();

        if ($data['title'] === '') {
            $this->fail('Il titolo del corso è obbligatorio.', $redirect);
        }

        CourseModel::update(
            $id,
            $data['title'],
            CourseModel::uniqueSlug($data['slug'] !== '' ? $data['slug'] : $data['title'], $id),
            $data['description'],
            $data['is_published'],
            $data['enrollment_mode']
        );

        $this->success('Corso aggiornato.', $redirect);
    }

    public function destroy(array $params): void
    {
        Auth::requirePermission('course.delete');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        // Moduli, lezioni, iscrizioni, tentativi e certificati vengono eliminati
        // a cascata: i file caricati in /storage restano invece su disco.
        CourseModel::delete((int) $course['id']);

        $this->success('Corso eliminato.', '/admin/courses');
    }

    // ---------------------------------------------------------------
    // Iscrizioni individuali
    // ---------------------------------------------------------------

    public function enroll(array $params): void
    {
        Auth::requirePermission('course.edit');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $redirect = '/admin/courses/' . (int) $course['id'] . '/edit';
        $userId = (int) ($_POST['user_id'] ?? 0);

        if (UserModel::find($userId) === null) {
            $this->fail('Utente non trovato.', $redirect);
        }

        EnrollmentModel::enroll($userId, (int) $course['id']);

        $this->success('Studente iscritto.', $redirect);
    }

    public function unenroll(array $params): void
    {
        Auth::requirePermission('course.edit');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        EnrollmentModel::remove((int) $params['userId'], (int) $course['id']);

        $this->success(
            'Iscrizione rimossa, insieme al progresso e all’eventuale certificato per questo corso.',
            '/admin/courses/' . (int) $course['id'] . '/edit'
        );
    }

    // ---------------------------------------------------------------
    // Richieste di iscrizione
    // ---------------------------------------------------------------

    public function decideRequest(array $params): void
    {
        Auth::requirePermission('course.edit');

        $request = EnrollmentRequestModel::findById((int) $params['requestId']);

        if ($request === null) {
            $this->notFound('Richiesta non trovata.');
            return;
        }

        $courseId = (int) $request['course_id'];
        $userId = (int) $request['user_id'];
        $redirect = '/admin/courses/' . $courseId . '/edit';
        $approve = ($_POST['decision'] ?? '') === 'approve';

        if ($request['status'] !== 'pending') {
            $this->fail('Questa richiesta è già stata valutata.', $redirect);
        }

        EnrollmentRequestModel::decide((int) $request['id'], $approve ? 'approved' : 'rejected', (int) Auth::id());

        if ($approve) {
            EnrollmentModel::enroll($userId, $courseId);

            Mailer::sendQuietly(Mailer::enrollmentConfirmed(
                (string) $request['email'],
                (string) $request['full_name'],
                (string) $request['course_title'],
                Url::to('/courses/' . $courseId)
            ));

            $this->success('Richiesta approvata: lo studente è iscritto ed è stato avvisato.', $redirect);
        }

        Mailer::sendQuietly(Mailer::enrollmentRejected(
            (string) $request['email'],
            (string) $request['full_name'],
            (string) $request['course_title']
        ));

        $this->success('Richiesta rifiutata: lo studente è stato avvisato.', $redirect);
    }

    // ---------------------------------------------------------------

    /**
     * @return array{title: string, slug: string, description: string|null, is_published: bool, enrollment_mode: string}
     */
    private function dataFromPost(): array
    {
        $description = trim((string) ($_POST['description'] ?? ''));
        $mode = (string) ($_POST['enrollment_mode'] ?? 'closed');

        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'description' => $description === '' ? null : $description,
            'is_published' => isset($_POST['is_published']),
            'enrollment_mode' => in_array($mode, ['open', 'request', 'closed'], true) ? $mode : 'closed',
        ];
    }
}
