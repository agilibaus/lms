<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\CertificateModel;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\LessonModel;
use App\Models\LessonProgressModel;
use App\Models\QuizAttemptModel;
use App\Models\QuizModel;
use App\Models\UserModel;

/**
 * Idoneita' ed emissione dei certificati.
 *
 * Regola di idoneita' (scelta in fase di progetto): lo studente deve aver
 * completato tutte le lezioni del corso E superato tutti i quiz presenti.
 * Admin/tutor possono comunque emettere o revocare manualmente.
 */
class CertificateService
{
    private const STORAGE_SUBDIR = 'certificates';

    /**
     * @return array{eligible: bool, lessons_total: int, lessons_done: int, quizzes_total: int, quizzes_passed: int}
     */
    public static function eligibility(int $userId, int $courseId): array
    {
        $lessonsTotal = LessonModel::countForCourse($courseId);
        $lessonsDone = LessonProgressModel::countCompletedForCourse($userId, $courseId);
        $quizIds = QuizModel::idsForCourse($courseId);
        $quizzesPassed = count(array_intersect(
            $quizIds,
            QuizAttemptModel::passedQuizIdsForCourse($userId, $courseId)
        ));

        $enrolled = EnrollmentModel::find($userId, $courseId) !== null;

        return [
            'eligible' => $enrolled
                && $lessonsTotal > 0
                && $lessonsDone >= $lessonsTotal
                && $quizzesPassed >= count($quizIds),
            'lessons_total' => $lessonsTotal,
            'lessons_done' => $lessonsDone,
            'quizzes_total' => count($quizIds),
            'quizzes_passed' => $quizzesPassed,
        ];
    }

    public static function isEligible(int $userId, int $courseId): bool
    {
        return self::eligibility($userId, $courseId)['eligible'];
    }

    /**
     * Emissione automatica: genera il certificato se lo studente e' idoneo e
     * non ne ha gia' uno valido. Un certificato revocato non viene rigenerato
     * automaticamente (serve un'emissione manuale da parte dello staff).
     *
     * @return array|null il certificato emesso, oppure null se non dovuto
     */
    public static function issueIfEligible(int $userId, int $courseId): ?array
    {
        $existing = CertificateModel::findForUserAndCourse($userId, $courseId);

        if ($existing !== null) {
            return null;
        }

        if (!self::isEligible($userId, $courseId)) {
            return null;
        }

        return self::issue($userId, $courseId, null);
    }

    /**
     * Emissione manuale da parte di admin/tutor (anche in deroga ai requisiti).
     * Se esiste un certificato revocato per la stessa coppia utente/corso,
     * viene riemesso mantenendo il codice di verifica originale.
     */
    public static function issueManually(int $userId, int $courseId, int $issuedBy): array
    {
        $existing = CertificateModel::findForUserAndCourse($userId, $courseId);

        if ($existing !== null && $existing['revoked_at'] === null) {
            return $existing;
        }

        return self::issue($userId, $courseId, $issuedBy);
    }

    public static function revoke(int $certificateId, ?string $reason): void
    {
        CertificateModel::revoke($certificateId, $reason);
    }

    public static function dompdfAvailable(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    // ---------------------------------------------------------------

    private static function issue(int $userId, int $courseId, ?int $issuedBy): array
    {
        $user = UserModel::find($userId);
        $course = CourseModel::find($courseId);

        if ($user === null || $course === null) {
            throw new \RuntimeException('Utente o corso non trovato: certificato non emesso.');
        }

        $existing = CertificateModel::findForUserAndCourse($userId, $courseId);
        $code = $existing['certificate_code'] ?? CertificateModel::generateUniqueCode();

        $filePath = self::renderPdf($code, (string) $user['full_name'], (string) $course['title'], $courseId);

        if ($existing !== null) {
            CertificateModel::reinstate((int) $existing['id'], $filePath, $issuedBy);
        } else {
            CertificateModel::create($userId, $courseId, $code, $filePath, $issuedBy);
        }

        return CertificateModel::findForUserAndCourse($userId, $courseId);
    }

    /**
     * Genera il PDF con Dompdf e restituisce il percorso relativo a /storage.
     */
    private static function renderPdf(string $code, string $fullName, string $courseTitle, int $courseId): string
    {
        if (!self::dompdfAvailable()) {
            throw new \RuntimeException(
                'Dompdf non è installato: esegui "composer install" (o "composer require dompdf/dompdf") per generare i certificati.'
            );
        }

        $html = self::renderTemplate([
            'code' => $code,
            'fullName' => $fullName,
            'courseTitle' => $courseTitle,
            'issuedAt' => new \DateTimeImmutable('now'),
            'verifyUrl' => rtrim((string) Env::get('APP_URL', ''), '/') . '/verify/' . $code,
        ]);

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $relativePath = self::STORAGE_SUBDIR . '/' . $courseId . '/' . $code . '.pdf';
        $absolutePath = Upload::absolutePath($relativePath);

        if (!is_dir(dirname($absolutePath)) && !mkdir(dirname($absolutePath), 0775, true) && !is_dir(dirname($absolutePath))) {
            throw new \RuntimeException('Impossibile creare la cartella dei certificati.');
        }

        file_put_contents($absolutePath, $dompdf->output());

        return $relativePath;
    }

    private static function renderTemplate(array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require __DIR__ . '/../Views/certificates/template.php';

        return (string) ob_get_clean();
    }
}
