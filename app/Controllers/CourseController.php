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

        $isStaff = Auth::hasRole('admin', 'tutor', 'assistente');

        if ($isStaff) {
            // Staff: vede tutti i corsi, pubblicati e in bozza.
            $courses = CourseModel::allForStaff();
        } else {
            // Studente: solo i corsi a cui è iscritto, con progresso.
            $courses = CourseModel::enrolledForUser((int) Auth::id());
        }

        // Il titolo segue il contenuto: per lo staff qui c'è tutto il
        // catalogo, per lo studente solo le sue iscrizioni — e "Corsi"
        // accanto a "Esplora corsi" non diceva quale fosse quale.
        $title = $isStaff ? 'Corsi' : 'I miei corsi';

        View::render('courses/index', [
            'pageTitle' => $title,
            'heading' => $title,
            'isStaff' => $isStaff,
            'courses' => $courses,
            // La percentuale dice quanto manca alla fine del corso; questa
            // dice se lo studente e' in pari con quello che puo' fare oggi.
            // Sono due domande diverse e vanno mostrate come due cose diverse.
            'availability' => $isStaff ? [] : $this->availability((int) Auth::id()),
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
            'moduleLocks' => $isStudent ? CourseAccess::locks($userId, $courseId) : [],
            'certificate' => $isStudent ? CertificateModel::findForUserAndCourse($userId, $courseId) : null,
            'eligibility' => $isStudent ? CertificateService::eligibility($userId, $courseId) : null,
        ]);
    }

    /**
     * Lo stato del rilascio progressivo per le schede dei corsi (§8.7).
     *
     * Il grosso del conto viene da due query sole, per tutti i corsi
     * insieme. Quelle pero' sanno guardare solo le date, perche' la data e'
     * una condizione che sta in SQL; la catena dei quiz dipende dai
     * tentativi dello studente. Senza questa correzione la frase direbbe
     * «0 di 2 lezioni disponibili» dove una delle due e' chiusa da un quiz,
     * cioe' prometterebbe una lezione che poi non si apre.
     *
     * Il costo in piu' si paga **solo sui corsi che il rilascio lo usano
     * davvero**: su un corso tutto aperto la frase non compare, e qui non si
     * entra nemmeno.
     *
     * @return array<int, array{disponibili: int, fatte: int, prossima: ?string}>
     */
    private function availability(int $userId): array
    {
        $riepilogo = LessonProgressModel::availabilitySummary($userId);

        foreach ($riepilogo as $courseId => $stato) {
            if ($stato['prossima'] === null) {
                continue;
            }

            $perQuiz = [];

            foreach (CourseAccess::locks($userId, $courseId) as $moduleId => $lock) {
                if ($lock['motivo'] === CourseAccess::MOTIVO_QUIZ) {
                    $perQuiz[] = $moduleId;
                }
            }

            if ($perQuiz === []) {
                continue;
            }

            $tolti = LessonProgressModel::countsForModules($userId, $perQuiz);

            $riepilogo[$courseId]['disponibili'] = max(0, $stato['disponibili'] - $tolti['lezioni']);
            $riepilogo[$courseId]['fatte'] = max(0, $stato['fatte'] - $tolti['fatte']);
        }

        return $riepilogo;
    }
}
