<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\CertificateService;
use App\Core\CourseAccess;
use App\Core\CourseCover;
use App\Core\Upload;
use App\Core\View;
use App\Models\CertificateModel;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\LessonModel;
use App\Models\LessonProgressModel;
use App\Models\ModuleModel;
use App\Models\QuizAttemptModel;
use App\Models\QuizModel;

class CourseController
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            // Staff: vede tutti i corsi, pubblicati e in bozza.
            $courses = CourseModel::allForStaff();
        } else {
            // Studente: solo i corsi a cui è iscritto, con progresso.
            $courses = CourseModel::enrolledForUser((int) Auth::id());
        }

        View::render('courses/index', [
            'pageTitle' => 'Corsi',
            'courses' => $courses,
        ]);
    }

    /**
     * Serve la copertina. Sta in /storage come ogni altro file caricato, quindi
     * passa di qui invece che da Apache.
     *
     * A differenza delle immagini di lezione non si controlla l'iscrizione: la
     * copertina compare nel catalogo, cioe' davanti a chi ancora iscritto non e'.
     * Serve comunque aver fatto accesso, come per l'immagine del profilo.
     */
    public function cover(array $params): void
    {
        Auth::requireLogin();

        $course = CourseModel::find((int) $params['id']);
        $stored = (string) ($course['cover_image'] ?? '');

        if ($course === null || $stored === '' || CourseCover::isExternalUrl($stored)) {
            http_response_code(404);
            echo 'Copertina non impostata.';
            return;
        }

        $path = CourseCover::pathFor($stored, ($params['size'] ?? '') === 'piccola');

        if ($path === null) {
            http_response_code(404);
            echo 'Copertina non trovata.';
            return;
        }

        $absolute = Upload::absolutePath($path);

        header('Content-Type: ' . CourseCover::mimeFor($path));
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        // Il nome del file cambia a ogni caricamento, quindi tenerla in cache a
        // lungo non fa mai vedere la copertina vecchia. Serve: nel catalogo
        // queste immagini sono molte per pagina.
        header('Cache-Control: private, max-age=604800');
        readfile($absolute);
        exit;
    }

    public function show(array $params): void
    {
        Auth::requireLogin();

        $course = CourseModel::find((int) $params['id']);

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        if (!Auth::hasRole('admin', 'tutor', 'assistente')) {
            if (EnrollmentModel::find((int) Auth::id(), (int) $course['id']) === null) {
                http_response_code(403);
                echo 'Non sei iscritto a questo corso.';
                return;
            }
        }

        $userId = (int) Auth::id();
        $courseId = (int) $course['id'];
        $isStudent = Auth::hasRole('studente');

        $modules = ModuleModel::forCourse($courseId);
        $lessonsByModule = [];
        $quizByModule = [];
        $quizPassedByModule = [];

        foreach ($modules as $module) {
            $moduleId = (int) $module['id'];
            $lessonsByModule[$moduleId] = LessonModel::forModule($moduleId);

            $quiz = QuizModel::forModule($moduleId);
            $quizByModule[$moduleId] = $quiz;
            $quizPassedByModule[$moduleId] = $quiz !== null && $isStudent
                && QuizAttemptModel::hasPassed($userId, (int) $quiz['id']);
        }

        $completedLessonIds = $isStudent
            ? LessonProgressModel::completedLessonIdsForCourse($userId, $courseId)
            : [];

        // Il certificato puo' maturare anche solo completando le lezioni (se il corso
        // non ha quiz): la verifica qui copre quel caso, l'altro e' in QuizController.
        if ($isStudent) {
            try {
                CertificateService::issueIfEligible($userId, $courseId);
            } catch (\RuntimeException $e) {
                error_log('[Certificati] ' . $e->getMessage());
            }
        }

        View::render('courses/show', [
            'pageTitle' => $course['title'],
            'course' => $course,
            'modules' => $modules,
            'lessonsByModule' => $lessonsByModule,
            'completedLessonIds' => $completedLessonIds,
            'quizByModule' => $quizByModule,
            'quizPassedByModule' => $quizPassedByModule,
            'lockedModuleIds' => $isStudent ? CourseAccess::lockedModuleIds($userId, $courseId) : [],
            'certificate' => $isStudent ? CertificateModel::findForUserAndCourse($userId, $courseId) : null,
            'eligibility' => $isStudent ? CertificateService::eligibility($userId, $courseId) : null,
        ]);
    }
}
