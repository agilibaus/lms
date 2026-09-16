<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Mail\Mailer;
use App\Core\Url;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\EnrollmentRequestModel;
use App\Models\UserModel;

/**
 * Catalogo dei corsi e auto-iscrizione degli studenti.
 *
 * Compaiono qui solo i corsi pubblicati con modalità `open` (iscrizione
 * immediata) o `request` (richiesta da approvare). I corsi `closed` restano
 * invisibili: a quelli si accede solo per iscrizione diretta dello staff o
 * tramite un gruppo.
 */
class CatalogController
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        View::render('catalog/index', [
            'pageTitle' => 'Esplora corsi',
            'courses' => CourseModel::catalogForUser((int) Auth::id()),
        ]);
    }

    /**
     * Iscrizione immediata (corsi `open`) o invio della richiesta (corsi `request`).
     */
    public function enroll(array $params): void
    {
        Auth::requireLogin();

        $course = CourseModel::find((int) $params['id']);
        $userId = (int) Auth::id();

        if ($course === null || (int) $course['is_published'] !== 1) {
            $this->fail('Corso non disponibile.');
        }

        if (EnrollmentModel::find($userId, (int) $course['id']) !== null) {
            $this->redirect('/courses/' . (int) $course['id']);
        }

        $courseId = (int) $course['id'];

        if ($course['enrollment_mode'] === 'open') {
            EnrollmentModel::enroll($userId, $courseId);

            Mailer::sendQuietly(Mailer::enrollmentConfirmed(
                (string) Auth::email(),
                (string) Auth::name(),
                (string) $course['title'],
                Url::to('/courses/' . $courseId)
            ));

            $_SESSION['flash_success'] = 'Iscrizione completata: buon lavoro.';

            $this->redirect('/courses/' . $courseId);
        }

        if ($course['enrollment_mode'] === 'request') {
            $message = trim((string) ($_POST['message'] ?? ''));
            EnrollmentRequestModel::create($userId, $courseId, $message === '' ? null : mb_substr($message, 0, 500));

            $this->notifyStaff((string) $course['title'], $courseId);

            $_SESSION['flash_success'] = 'Richiesta inviata: riceverai un\'email quando verrà valutata.';

            $this->redirect('/catalogo');
        }

        // enrollment_mode = 'closed': non compare nemmeno nel catalogo.
        $this->fail('Questo corso non prevede l\'iscrizione autonoma.');
    }

    // ---------------------------------------------------------------

    /**
     * Avvisa admin e tutor che c'è una richiesta da valutare.
     */
    private function notifyStaff(string $courseTitle, int $courseId): void
    {
        $link = Url::to('/admin/courses/' . $courseId . '/edit');

        foreach (UserModel::staffForNotifications() as $staff) {
            Mailer::sendQuietly(Mailer::enrollmentRequested(
                (string) $staff['email'],
                (string) $staff['full_name'],
                (string) Auth::name(),
                $courseTitle,
                $link
            ));
        }
    }

    private function fail(string $message): never
    {
        $_SESSION['flash_error'] = $message;

        $this->redirect('/catalogo');
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }
}
