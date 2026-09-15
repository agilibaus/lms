<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\CertificateService;
use App\Core\Upload;
use App\Core\View;
use App\Models\CertificateModel;
use App\Models\CourseModel;
use App\Models\UserModel;

class CertificateController
{
    /**
     * Studente: i propri certificati. Staff: tutti quelli emessi.
     */
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $isStaff = Auth::hasRole('admin', 'tutor', 'assistente');

        View::render('certificates/index', [
            'pageTitle' => 'Certificati',
            'isStaff' => $isStaff,
            'certificates' => $isStaff ? CertificateModel::all() : CertificateModel::forUser((int) Auth::id()),
            'dompdfAvailable' => CertificateService::dompdfAvailable(),
        ]);
    }

    public function download(array $params): void
    {
        Auth::requireLogin();

        $certificate = CertificateModel::find((int) $params['id']);

        if (!$certificate) {
            http_response_code(404);
            echo 'Certificato non trovato.';
            return;
        }

        if ((int) $certificate['user_id'] !== (int) Auth::id() && !Auth::hasRole('admin', 'tutor', 'assistente')) {
            http_response_code(403);
            echo 'Non puoi scaricare questo certificato.';
            return;
        }

        if ($certificate['revoked_at'] !== null) {
            http_response_code(410);
            echo 'Questo certificato è stato revocato e non è più scaricabile.';
            return;
        }

        $absolute = Upload::absolutePath((string) $certificate['file_path']);

        if (!is_file($absolute)) {
            http_response_code(404);
            echo 'File del certificato non trovato sul server.';
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="certificato-' . $certificate['certificate_code'] . '.pdf"');
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
        exit;
    }

    /**
     * Emissione manuale (permesso `certificate.issue`), anche in deroga ai
     * requisiti automatici.
     */
    public function issue(array $params = []): void
    {
        Auth::requirePermission('certificate.issue');

        $userId = (int) ($_POST['user_id'] ?? 0);
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $redirectTo = $this->safeRedirectTarget($_POST['redirect_to'] ?? '/certificates');

        if (UserModel::find($userId) === null || CourseModel::find($courseId) === null) {
            $_SESSION['flash_error'] = 'Utente o corso non validi.';
            $this->redirect($redirectTo);
        }

        try {
            CertificateService::issueManually($userId, $courseId, (int) Auth::id());
            $_SESSION['flash_success'] = 'Certificato emesso.';
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        $this->redirect($redirectTo);
    }

    public function revoke(array $params): void
    {
        Auth::requirePermission('certificate.issue');

        $certificate = CertificateModel::find((int) $params['id']);
        $redirectTo = $this->safeRedirectTarget($_POST['redirect_to'] ?? '/certificates');

        if (!$certificate) {
            http_response_code(404);
            echo 'Certificato non trovato.';
            return;
        }

        $reason = trim($_POST['reason'] ?? '');
        CertificateService::revoke((int) $certificate['id'], $reason === '' ? null : $reason);
        $_SESSION['flash_success'] = 'Certificato revocato.';

        $this->redirect($redirectTo);
    }

    /**
     * Verifica pubblica del certificato tramite codice — nessun login richiesto,
     * mostra solo nome, corso e data (nessun dato di contatto).
     */
    public function verify(array $params): void
    {
        $certificate = CertificateModel::findByCode((string) ($params['code'] ?? ''));

        View::render('certificates/verify', [
            'pageTitle' => 'Verifica certificato',
            'code' => (string) ($params['code'] ?? ''),
            'certificate' => $certificate,
        ], false);
    }

    // ---------------------------------------------------------------

    /**
     * Accetta solo redirect interni (nessun "open redirect" verso l'esterno).
     */
    private function safeRedirectTarget(string $target): string
    {
        return (str_starts_with($target, '/') && !str_starts_with($target, '//'))
            ? $target
            : '/certificates';
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit;
    }
}
