<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\QuestionModel;
use App\Core\TutorWelcome;
use App\Models\TutorWelcomeModel;
use App\Auth\Auth;
use App\Auth\BachecaScope;
use App\Core\CertificateService;
use App\Core\CourseAccess;
use App\Core\CourseCover;
use App\Core\Obiettivo;
use App\Core\Percorso;
use App\Core\TestoBreve;
use App\Core\TreParole;
use App\Core\Upload;
use App\Core\View;
use App\Models\CertificateModel;
use App\Models\BoardModel;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\GroupModel;
use App\Models\LessonModel;
use App\Models\LessonProgressModel;
use App\Models\ModuleModel;
use App\Models\QuizAttemptModel;
use App\Models\QuizModel;
use App\Models\TreParoleModel;

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
            // Le proposte della bacheca da valutare, corso per corso (17/10):
            // la voce Bacheca sta nel ramo del corso, e da fuori il numero si
            // vede qui, sulla scheda del corso che le aspetta.
            'daValutare' => $isStaff ? self::daValutare($courses) : [],
        ]);
    }

    /**
     * Quante proposte della bacheca aspettano chi guarda, per corso; solo i
     * corsi che ne hanno.
     *
     * @param list<array<string, mixed>> $courses
     * @return array<int, int>
     */
    private static function daValutare(array $courses): array
    {
        $conti = [];

        foreach ($courses as $c) {
            $id = (int) $c['id'];
            $moderati = BachecaScope::moderati($id);
            $quante = $moderati === [] ? 0 : count(BoardModel::inAttesa($id, $moderati));

            if ($quante > 0) {
                $conti[$id] = $quante;
            }
        }

        return $conti;
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

        // Il benvenuto del tutor (07/10): solo per lo studente, e solo se il
        // tutor del suo gruppo ne ha uno. La visita si registra qui, e da
        // lei dipende se il benvenuto e' completo o ridotto a una riga.
        $welcome = null;

        if ($isStudent) {
            $dati = TutorWelcomeModel::forStudent($courseId, $userId);

            if ($dati !== null) {
                $stato = TutorWelcomeModel::recordVisit($userId, $courseId);
                $welcome = $dati + ['modo' => TutorWelcome::modo($stato['visits'], $stato['listened'])];
            }
        }

        // L'obiettivo del corso e dei moduli (11/10, `App\Core\Obiettivo`):
        // per lo studente il corso e' completo nelle prime tre visite e il
        // modulo solo se e' quello in corso; lo staff li vede sempre
        // completi. La visita si conta solo se l'obiettivo c'e'.
        $obiettivoCorso = null;

        if (TestoBreve::html((string) ($course['objective'] ?? '')) !== '') {
            $obiettivoCorso = $isStudent
                ? Obiettivo::modoCorso(CourseModel::registraVisitaObiettivo($userId, $courseId))
                : 'completo';
        }

        // «Vai al tuo gruppo» sotto il titolo (14/10, Alessandro): il gruppo e'
        // parte della didattica, e fin qui dalla pagina del corso ci si
        // arrivava solo dal benvenuto del tutor. Lo stesso gruppo del
        // benvenuto, quando c'e'; se no il primo dei suoi gruppi nel corso.
        $tuoGruppo = null;

        if ($isStudent) {
            $tuoGruppo = $welcome !== null && !empty($welcome['group_id'])
                ? (int) $welcome['group_id']
                : GroupModel::delloStudenteNelCorso($userId, $courseId);
        }

        $statiModuli = $isStudent
            ? Percorso::stati($modules, $lessonsByModule, $completedLessonIds, $quizByModule, $quizPassedByModule)
            : null;

        $chiusiPerLui = $isStudent ? array_map('intval', CourseAccess::lockedModuleIds($userId, $courseId)) : [];

        // I questionari ancora chiusi (27/10, `Percorso::questionarioChiuso`):
        // la stessa regola che applica la pagina del questionario.
        $questionariChiusi = [];

        if ($isStudent) {
            foreach ($modules as $module) {
                $moduleId = (int) $module['id'];

                if ($quizByModule[$moduleId] !== null) {
                    $questionariChiusi[$moduleId] = Percorso::questionarioChiuso(
                        $moduleId, $modules, $lessonsByModule, $completedLessonIds, $quizByModule, $quizPassedByModule
                    );
                }
            }
        }

        View::render('courses/show', [
            'pageTitle' => $course['title'],
            'welcome' => $welcome,
            'treParole' => $isStudent ? self::treParole($course, $modules, $statiModuli, $userId) : null,
            'tuoGruppo' => $tuoGruppo,
            'obiettivoCorso' => $obiettivoCorso,
            'tuttiCompleti' => !$isStudent,
            // La gerarchia dei moduli (11/10): lo stato di ciascuno per lo
            // studente; per lo staff null, cioe' tutto aperto.
            'statiModuli' => $statiModuli,
            'tuttiModuli' => ($_GET['moduli'] ?? '') === Percorso::TUTTI,
            'moduloInCorso' => $isStudent
                ? Obiettivo::moduloInCorso($modules, $lessonsByModule, $completedLessonIds, $quizByModule, $quizPassedByModule)
                : null,
            // «Fai una domanda al tutor» (dal 09/10 l'archivio ha una pagina
            // sua, «Domande e risposte»): solo per lo studente.
            'domande' => [
                'mie' => $isStudent ? QuestionModel::ofStudent($courseId, $userId) : [],
                'puoChiedere' => $isStudent,
                // Solo i moduli aperti per lui (25/10): la domanda su un
                // modulo chiuso non la leggerebbe nemmeno chi l'ha fatta.
                // Senza la verifica finale (28/10): non e' un contenuto.
                'moduli' => array_values(array_filter(
                    ModuleModel::contenuti($courseId),
                    static fn (array $m): bool => !in_array((int) $m['id'], $chiusiPerLui, true)
                )),
            ],
            'course' => $course,
            'modules' => $modules,
            'lessonsByModule' => $lessonsByModule,
            'completedLessonIds' => $completedLessonIds,
            'quizByModule' => $quizByModule,
            'quizPassedByModule' => $quizPassedByModule,
            'questionariChiusi' => $questionariChiusi,
            'moduleLocks' => $isStudent ? CourseAccess::locks($userId, $courseId) : [],
            'certificate' => $isStudent ? CertificateModel::findForUserAndCourse($userId, $courseId) : null,
        ]);
    }

    /**
     * «Tre parole» (21/10) per lo studente: il modulo di cui chiederle (il
     * completato piu' di recente fra quelli a cui non ha ancora risposto,
     * se il corso le chiede e il modulo e' finito da quando le chiede), le
     * parole gia' scritte per modulo, e l'esito dell'ultimo invio.
     *
     * @param list<array<string, mixed>> $modules
     * @param array<int, string>|null    $stati   da `Percorso::stati`
     * @return array{chiedi: ?array, mie: array<int, array>, esito: ?array}
     */
    private static function treParole(array $course, array $modules, ?array $stati, int $userId): array
    {
        $esito = $_SESSION['tre_parole'] ?? null;
        unset($_SESSION['tre_parole']);
        $chiesteDal = $course['three_words_since'] ?? null;
        $mie = TreParoleModel::delloStudente($userId, (int) $course['id']);
        $chiedi = null;

        if ($chiesteDal !== null && $stati !== null) {
            $completati = array_keys(array_filter($stati, static fn (string $s): bool => $s === Percorso::COMPLETATO));
            $date = TreParoleModel::completatiIl($userId, $completati);
            $ultima = '';

            foreach ($modules as $m) {
                $id = (int) $m['id'];
                $il = $date[$id] ?? null;

                // Le Tre parole non si chiedono sulla verifica finale (28/10).
                if (!empty($m['is_final'])) {
                    continue;
                }

                if (!isset($mie[$id]) && in_array($id, $completati, true)
                    && TreParole::daChiedere($il, (string) $chiesteDal) && (string) $il >= $ultima) {
                    $chiedi = $m;
                    $ultima = (string) $il;
                }
            }
        }

        return ['chiedi' => $chiedi, 'mie' => $mie, 'esito' => is_array($esito) ? $esito : null];
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
