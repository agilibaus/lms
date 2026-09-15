<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
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
            (int) Auth::id()
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
            $data['is_published']
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

    /**
     * @return array{title: string, slug: string, description: string|null, is_published: bool}
     */
    private function dataFromPost(): array
    {
        $description = trim((string) ($_POST['description'] ?? ''));

        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'description' => $description === '' ? null : $description,
            'is_published' => isset($_POST['is_published']),
        ];
    }
}
