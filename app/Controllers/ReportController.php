<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\CourseRights;
use App\Core\Csv;
use App\Core\View;
use App\Core\Xlsx;
use App\Models\CourseModel;
use App\Models\GroupModel;
use App\Models\LiveSessionAttendanceModel;
use App\Models\LiveSessionModel;
use App\Models\QuizAttemptModel;
use App\Models\ReportModel;
use App\Models\UserModel;
use App\Models\VideoProgressModel;

/**
 * Report di avanzamento: per corso, per studente, per gruppo — con export CSV.
 *
 * Permessi: admin/tutor hanno `report.view` (tutto); l'assistente ha
 * `report.view_assigned` e vede solo gli studenti dei gruppi del proprio tutor.
 */
class ReportController
{
    public function index(array $params = []): void
    {
        $this->requireReportAccess();

        $allowed = $this->allowedStudentIds();

        $students = ReportModel::studentsOverview();

        if ($allowed !== null) {
            $students = array_values(array_filter(
                $students,
                static fn (array $s): bool => in_array((int) $s['id'], $allowed, true)
            ));
        }

        View::render('reports/index', [
            'pageTitle' => 'Report',
            'courses' => $this->filterCourses(ReportModel::coursesOverview()),
            'students' => $students,
            'groups' => $this->visibleGroups(),
            'liveSessions' => $this->filterSessions(LiveSessionModel::overview()),
            'corsiConVideo' => $this->filterCourses(VideoProgressModel::coursesWithVideo()),
            'restricted' => $allowed !== null,
        ]);
    }

    // ---------------------------------------------------------------
    // Report per incontro dal vivo
    // ---------------------------------------------------------------

    public function liveSession(array $params): void
    {
        $this->requireReportAccess();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session !== null && !$this->sessionVisible($session)) {
            http_response_code(403);
            exit('Accesso negato: questo incontro non riguarda i corsi o i gruppi che segui.');
        }

        if ($session === null) {
            http_response_code(404);
            echo 'Incontro non trovato.';
            return;
        }

        View::render('reports/live_session', [
            'pageTitle' => 'Report · ' . $session['title'],
            'session' => $session,
            'rows' => $this->liveSessionRows((int) $session['id']),
            'restricted' => $this->allowedStudentIds() !== null,
        ]);
    }

    public function liveSessionDownload(array $params): void
    {
        $this->requireReportAccess();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session !== null && !$this->sessionVisible($session)) {
            http_response_code(403);
            exit('Accesso negato: questo incontro non riguarda i corsi o i gruppi che segui.');
        }

        if ($session === null) {
            http_response_code(404);
            echo 'Incontro non trovato.';
            return;
        }

        $rows = [];

        foreach ($this->liveSessionRows((int) $session['id']) as $row) {
            $rows[] = [
                $row['full_name'],
                $row['email'],
                $row['joined_at'] === null ? 'assente' : 'presente',
                self::dateTimeLabel($row['joined_at']),
                self::delayLabel($row),
                self::sourceLabel($row['source']),
            ];
        }

        $this->inviaReport(
            self::formato($params),
            'report-incontro-' . Csv::slug((string) $session['title']),
            ['Partecipante', 'Email', 'Presenza', 'Ingresso', 'Ritardo (minuti)', 'Origine'],
            $rows,
            [4 => 'numero'],
            'Incontro'
        );
    }

    // ---------------------------------------------------------------
    // Report per corso
    // ---------------------------------------------------------------

    public function course(array $params): void
    {
        $this->requireReportAccess();

        $course = CourseModel::find((int) $params['id']);

        if ($course) {
            $this->requireVisibleCourse((int) $course['id']);
        }

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        View::render('reports/course', [
            'pageTitle' => 'Report · ' . $course['title'],
            'course' => $course,
            'rows' => $this->courseRows((int) $course['id']),
            'totals' => ReportModel::courseTotals((int) $course['id']),
        ]);
    }

    public function courseDownload(array $params): void
    {
        $this->requireReportAccess();

        $course = CourseModel::find((int) $params['id']);

        if ($course) {
            $this->requireVisibleCourse((int) $course['id']);
        }

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        $formato = self::formato($params);
        $xlsx = $formato === 'xlsx';

        $totals = ReportModel::courseTotals((int) $course['id']);
        $rows = [];

        foreach ($this->courseRows((int) $course['id']) as $row) {
            $rows[] = [
                $row['full_name'],
                $row['email'],
                $row['enrolled_at'],
                self::decimale((float) $row['progress_pct'], $xlsx),
                $row['lessons_completed'] . '/' . $totals['lessons'],
                $row['quizzes_passed'] . '/' . $totals['quizzes'],
                $row['completed_at'] ?? '',
                $this->certificateLabel($row),
            ];
        }

        $this->inviaReport(
            $formato,
            'report-corso-' . Csv::slug((string) $course['title']),
            ['Studente', 'Email', 'Iscritto il', 'Progresso %', 'Lezioni completate', 'Quiz superati', 'Completato il', 'Certificato'],
            $rows,
            [3 => 'numero'],
            'Corso'
        );
    }

    // ---------------------------------------------------------------
    // Fruizione dei video
    // ---------------------------------------------------------------

    /**
     * GET /reports/fruizione/{id} — quanto ha guardato ciascuno studente di
     * ogni lezione con video del corso.
     *
     * E' il taglio che serve a rendicontare: una persona per riga, tutte le
     * lezioni in fila. La pagina della singola lezione
     * (`/lessons/{id}/fruizione`) guarda invece una lezione alla volta —
     * stessi dati, due letture.
     */
    public function videoCourse(array $params): void
    {
        [$course, $lezioni, $righe] = $this->videoCourseData((int) $params['id']);

        View::render('reports/fruizione', [
            'pageTitle' => 'Fruizione video · ' . $course['title'],
            'course' => $course,
            'lezioni' => $lezioni,
            'righe' => $righe,
            // Il dettaglio di una lezione mostra *tutti* gli iscritti al
            // corso: chi vede solo gli studenti del proprio tutor non ci
            // puo' entrare, e il titolo resta senza collegamento invece di
            // portare a un 403.
            'dettaglioApribile' => CourseRights::canEdit((int) $course['id']) || Auth::can('report.view'),
        ]);
    }

    /**
     * GET /reports/fruizione/{id}/csv e .../xlsx.
     */
    public function videoCourseDownload(array $params): void
    {
        [$course, $lezioni, $righe] = $this->videoCourseData((int) $params['id']);

        $intestazioni = ['Studente', 'Email', 'Corso'];
        $tipi = [];

        foreach ($lezioni as $lezione) {
            $intestazioni[] = $lezione['title'] . ' (%)';
            $tipi[count($intestazioni) - 1] = 'numero';
        }

        $intestazioni[] = 'Media sulle lezioni con video (%)';
        $tipi[count($intestazioni) - 1] = 'numero';
        $intestazioni[] = 'Tempo guardato in totale (secondi)';
        $tipi[count($intestazioni) - 1] = 'numero';
        $intestazioni[] = 'Lezioni avviate';
        $tipi[count($intestazioni) - 1] = 'numero';

        $dati = [];

        foreach ($righe as $riga) {
            $cella = [$riga['full_name'], $riga['email'], $course['title']];

            foreach ($lezioni as $lezione) {
                $valore = $riga['per_lezione'][$lezione['id']]['percentuale'] ?? null;
                $cella[] = $valore === null ? '' : $valore;
            }

            $cella[] = $riga['percentuale_media'] === null ? '' : $riga['percentuale_media'];
            // Secondi, quindi un intero: esce uguale nei due formati.
            $cella[] = VideoProgressController::secondi((int) $riga['secondi_totali']);
            $cella[] = (int) $riga['lezioni_avviate'];

            $dati[] = $cella;
        }

        $this->inviaReport(
            self::formato($params),
            'fruizione-video-' . Csv::slug((string) $course['title']) . '-' . date('Y-m-d'),
            $intestazioni,
            $dati,
            $tipi,
            'Fruizione'
        );
    }

    /**
     * Corso, lezioni con video e matrice, con i controlli di sempre: accesso
     * ai report, corso dentro il perimetro, righe ristrette agli studenti
     * che chi guarda puo' vedere.
     *
     * @return array{0: array, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function videoCourseData(int $courseId): array
    {
        $this->requireReportAccess();

        $course = CourseModel::find($courseId);

        if ($course === null) {
            http_response_code(404);
            exit('Corso non trovato.');
        }

        $this->requireVisibleCourse($courseId);

        $lezioni = VideoProgressModel::videoLessonsForCourse($courseId);
        $righe = VideoProgressModel::courseMatrix($courseId, $lezioni);

        $allowed = $this->allowedStudentIds();

        if ($allowed !== null) {
            $righe = array_values(array_filter(
                $righe,
                static fn (array $r): bool => in_array((int) $r['user_id'], $allowed, true)
            ));
        }

        return [$course, $lezioni, $righe];
    }

    // ---------------------------------------------------------------
    // Report per studente
    // ---------------------------------------------------------------

    public function student(array $params): void
    {
        $this->requireReportAccess();

        $student = $this->findVisibleStudent((int) $params['id']);

        if ($student === null) {
            return;
        }

        $courses = ReportModel::studentDetail((int) $student['id']);
        $quizzesByCourse = [];

        foreach ($courses as $course) {
            $quizzesByCourse[$course['course_id']] = QuizAttemptModel::summaryForUserAndCourse(
                (int) $student['id'],
                (int) $course['course_id']
            );
        }

        View::render('reports/student', [
            'pageTitle' => 'Report · ' . $student['full_name'],
            'student' => $student,
            'courses' => $courses,
            'quizzesByCourse' => $quizzesByCourse,
            'liveAttendance' => LiveSessionAttendanceModel::summaryForUser((int) $student['id']),
            'liveSessions' => LiveSessionModel::forUserWithAttendance((int) $student['id']),
        ]);
    }

    public function studentDownload(array $params): void
    {
        $this->requireReportAccess();

        $student = $this->findVisibleStudent((int) $params['id']);

        if ($student === null) {
            return;
        }

        $formato = self::formato($params);
        $xlsx = $formato === 'xlsx';

        $rows = [];

        foreach (ReportModel::studentDetail((int) $student['id']) as $row) {
            $rows[] = [
                $row['course_title'],
                $row['enrolled_at'],
                self::decimale((float) $row['progress_pct'], $xlsx),
                $row['lessons_completed'] . '/' . $row['lessons_total'],
                $row['quizzes_passed'] . '/' . $row['quizzes_total'],
                $row['completed_at'] ?? '',
                $this->certificateLabel($row),
            ];
        }

        // Gli incontri dal vivo hanno colonne diverse dai corsi: invece di
        // allargare la tabella con campi che per i corsi resterebbero vuoti,
        // si aggiungono in fondo, dopo una riga bianca e una loro intestazione.
        // Un foglio di calcolo li legge come un secondo blocco.
        $sessions = LiveSessionModel::forUserWithAttendance((int) $student['id']);

        if ($sessions !== []) {
            $rows[] = [];
            $rows[] = ['Incontro dal vivo', 'Quando', 'Corso o gruppo', 'Presenza', 'Ingresso', 'Ritardo (minuti)'];

            foreach ($sessions as $session) {
                $rows[] = [
                    $session['title'],
                    self::dateTimeLabel($session['starts_at']),
                    $session['course_title'] ?? $session['group_name'] ?? '',
                    $session['joined_at'] === null ? 'assente' : 'presente',
                    self::dateTimeLabel($session['joined_at']),
                    self::delayLabel($session),
                ];
            }
        }

        /*
         * Niente colonna dichiarata come numero qui: sotto le righe dei
         * corsi ce ne sono altre con un significato diverso — gli incontri
         * dal vivo — e nella terza colonna hanno un testo. Dire al foglio
         * di calcolo che quella colonna e' numerica la farebbe litigare con
         * meta' del proprio contenuto.
         */
        $this->inviaReport(
            $formato,
            'report-studente-' . Csv::slug((string) $student['full_name']),
            ['Corso', 'Iscritto il', 'Progresso %', 'Lezioni completate', 'Quiz superati', 'Completato il', 'Certificato'],
            $rows,
            [],
            'Studente'
        );
    }

    // ---------------------------------------------------------------
    // Report per gruppo
    // ---------------------------------------------------------------

    public function group(array $params): void
    {
        $this->requireReportAccess();

        $group = $this->findVisibleGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        View::render('reports/group', [
            'pageTitle' => 'Report · ' . $group['name'],
            'group' => $group,
            'courses' => GroupModel::courses((int) $group['id']),
            'members' => GroupModel::members((int) $group['id']),
            'rows' => ReportModel::groupDetail((int) $group['id']),
        ]);
    }

    public function groupDownload(array $params): void
    {
        $this->requireReportAccess();

        $group = $this->findVisibleGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        $formato = self::formato($params);
        $xlsx = $formato === 'xlsx';

        $rows = [];

        foreach (ReportModel::groupDetail((int) $group['id']) as $row) {
            $rows[] = [
                $row['full_name'],
                $row['email'],
                $row['course_title'],
                $row['progress_pct'] === null
                    ? 'non iscritto'
                    : self::decimale((float) $row['progress_pct'], $xlsx),
                $row['quizzes_passed'] . '/' . $row['quizzes_total'],
                $row['completed_at'] ?? '',
                $this->certificateLabel($row),
            ];
        }

        // Colonna del progresso non dichiarata numerica: dove lo studente
        // non e' iscritto al corso c'e' scritto «non iscritto», ed e'
        // un'informazione, non uno zero.
        $this->inviaReport(
            $formato,
            'report-gruppo-' . Csv::slug((string) $group['name']),
            ['Studente', 'Email', 'Corso', 'Progresso %', 'Quiz superati', 'Completato il', 'Certificato'],
            $rows,
            [],
            'Gruppo'
        );
    }

    // ---------------------------------------------------------------
    // Helper privati
    // ---------------------------------------------------------------

    /**
     * Manda un report nel formato chiesto. Tutti i report passano di qui,
     * cosi' CSV e XLSX escono per forza dagli stessi dati: se un giorno si
     * aggiunge una colonna, non c'e' un secondo posto da ricordarsi.
     *
     * `$tipi` vale solo per l'XLSX e dice quali colonne sono numeri — senza,
     * un foglio di calcolo le tratta come testo e non si sommano.
     *
     * @param list<string>                                  $intestazioni
     * @param array<int, array<int, string|int|float|null>> $righe
     * @param array<int, string>                            $tipi
     */
    private function inviaReport(
        string $formato,
        string $nomeSenzaEstensione,
        array $intestazioni,
        array $righe,
        array $tipi = [],
        string $foglio = 'Report'
    ): void {
        if ($formato === 'xlsx') {
            if (!Xlsx::disponibile()) {
                http_response_code(500);
                exit('Il formato XLSX richiede l\'estensione zip di PHP, che qui non c\'è. Scarica il CSV.');
            }

            Xlsx::send($nomeSenzaEstensione . '.xlsx', $intestazioni, $righe, $tipi, $foglio);
            return;
        }

        Csv::send($nomeSenzaEstensione . '.csv', $intestazioni, $righe);
    }

    /**
     * Il formato chiesto nell'indirizzo, con il CSV come ripiego: un
     * segmento inventato non deve produrre un file a sorpresa.
     *
     * @param array<string, mixed> $params
     */
    private static function formato(array $params): string
    {
        return ($params['formato'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    }

    /**
     * Un numero per il foglio di calcolo, la sua scrittura italiana per il
     * CSV: Excel in italiano legge «12.5» come una data e «12,5» come un
     * numero. Gli interi non hanno questo problema e passano com'e'.
     */
    private static function decimale(float $valore, bool $xlsx): string|float
    {
        return $xlsx ? round($valore, 2) : number_format($valore, 2, ',', '');
    }

    private function requireReportAccess(): void
    {
        Auth::requireLogin();

        if (!Auth::can('report.view') && !Auth::can('report.view_assigned')) {
            http_response_code(403);
            exit('Accesso negato: non hai i permessi per consultare i report.');
        }
    }

    /**
     * I tutor di cui l'utente vede il lavoro: null per l'admin (tutto), il
     * tutor stesso per un tutor, i suoi tutor — anche piu' d'uno — per un
     * assistente. E' da qui che derivano studenti, gruppi e corsi visibili.
     *
     * @return int[]|null
     */
    private function scopeTutorIds(): ?array
    {
        if (Auth::hasRole('admin')) {
            return null;
        }

        if (Auth::hasRole('tutor')) {
            return [(int) Auth::id()];
        }

        return UserModel::tutorIdsForAssistant((int) Auth::id());
    }

    /**
     * Perimetro visibile: null = nessuna restrizione (admin), altrimenti gli
     * id degli studenti dei gruppi dei tutor del perimetro.
     *
     * @return int[]|null
     */
    private function allowedStudentIds(): ?array
    {
        $tutors = $this->scopeTutorIds();

        if ($tutors === null) {
            return null;
        }

        $ids = [];

        foreach ($tutors as $tutorId) {
            $ids = array_merge($ids, GroupModel::memberIdsForTutor($tutorId));
        }

        return array_values(array_unique($ids));
    }

    private function visibleGroups(): array
    {
        $tutors = $this->scopeTutorIds();

        if ($tutors === null) {
            return GroupModel::all();
        }

        $groups = [];

        foreach ($tutors as $tutorId) {
            foreach (GroupModel::forTutor($tutorId) as $group) {
                $groups[(int) $group['id']] = $group;
            }
        }

        return array_values($groups);
    }

    /**
     * Corsi del perimetro: quelli assegnati ai gruppi dei tutor del perimetro.
     * null = tutti.
     *
     * @return int[]|null
     */
    private function visibleCourseIds(): ?array
    {
        if ($this->scopeTutorIds() === null) {
            return null;
        }

        $ids = [];

        foreach ($this->visibleGroups() as $group) {
            foreach (GroupModel::courses((int) $group['id']) as $course) {
                $ids[] = (int) $course['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Ferma chi apre a mano il report di un corso fuori dal suo perimetro.
     */
    private function requireVisibleCourse(int $courseId): void
    {
        $visible = $this->visibleCourseIds();

        if ($visible !== null && !in_array($courseId, $visible, true)) {
            http_response_code(403);
            exit('Accesso negato: questo corso non è assegnato ai gruppi che segui.');
        }
    }

    /**
     * Una sessione e' visibile se riguarda un corso del perimetro, attraverso
     * il suo modulo, oppure uno dei gruppi del perimetro.
     */
    private function sessionVisible(array $session): bool
    {
        $courses = $this->visibleCourseIds();

        if ($courses === null) {
            return true;
        }

        if (!empty($session['course_id']) && in_array((int) $session['course_id'], $courses, true)) {
            return true;
        }

        $groups = array_map(static fn (array $g): int => (int) $g['id'], $this->visibleGroups());

        return !empty($session['group_id']) && in_array((int) $session['group_id'], $groups, true);
    }

    /**
     * La panoramica non porta modulo e gruppo: gli id visibili si ricavano
     * dall'elenco completo, che li ha.
     */
    private function filterSessions(array $overview): array
    {
        if ($this->visibleCourseIds() === null) {
            return $overview;
        }

        $visibili = [];

        foreach (LiveSessionModel::all() as $session) {
            if ($this->sessionVisible($session)) {
                $visibili[(int) $session['id']] = true;
            }
        }

        return array_values(array_filter(
            $overview,
            static fn (array $row): bool => isset($visibili[(int) $row['id']])
        ));
    }

    private function filterCourses(array $courses): array
    {
        $visible = $this->visibleCourseIds();

        if ($visible === null) {
            return $courses;
        }

        return array_values(array_filter(
            $courses,
            static fn (array $c): bool => in_array((int) $c['id'], $visible, true)
        ));
    }

    private function courseRows(int $courseId): array
    {
        $rows = ReportModel::courseDetail($courseId);
        $allowed = $this->allowedStudentIds();

        if ($allowed === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((int) $row['user_id'], $allowed, true)
        ));
    }

    /**
     * Restituisce lo studente se visibile all'utente corrente, altrimenti
     * emette 404/403 e restituisce null.
     */
    private function findVisibleStudent(int $userId): ?array
    {
        $student = UserModel::find($userId);

        if ($student === null) {
            http_response_code(404);
            echo 'Utente non trovato.';
            return null;
        }

        $allowed = $this->allowedStudentIds();

        if ($allowed !== null && !in_array($userId, $allowed, true)) {
            http_response_code(403);
            echo 'Questo studente non rientra nei gruppi che segui.';
            return null;
        }

        return $student;
    }

    private function findVisibleGroup(int $groupId): ?array
    {
        $group = GroupModel::find($groupId);

        if ($group === null) {
            http_response_code(404);
            echo 'Gruppo non trovato.';
            return null;
        }

        $visibleIds = array_map(static fn (array $g): int => (int) $g['id'], $this->visibleGroups());

        if (!in_array($groupId, $visibleIds, true)) {
            http_response_code(403);
            echo 'Questo gruppo non rientra in quelli che segui.';
            return null;
        }

        return $group;
    }

    /**
     * Partecipanti di un incontro, ristretti al perimetro di chi guarda:
     * l'assistente vede solo gli studenti dei gruppi del proprio tutor, come
     * gia' avviene per le righe del report di corso.
     */
    private function liveSessionRows(int $sessionId): array
    {
        $rows = LiveSessionModel::participantsWithAttendance($sessionId);
        $allowed = $this->allowedStudentIds();

        if ($allowed === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((int) $row['id'], $allowed, true)
        ));
    }

    /**
     * Ritardo in minuti rispetto all'inizio: solo se positivo, perche' entrare
     * cinque minuti prima non e' un ritardo di meno cinque.
     *
     * @param array<string, mixed> $row
     */
    public static function delayLabel(array $row): string
    {
        if ($row['joined_at'] === null || $row['delay_minutes'] === null) {
            return '';
        }

        $minutes = (int) $row['delay_minutes'];

        return $minutes > 0 ? (string) $minutes : '0';
    }

    public static function dateTimeLabel(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $moment = strtotime($value);

        return $moment === false ? '' : date('d/m/Y H:i', $moment);
    }

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            'platform' => 'piattaforma',
            'manual' => 'segnata dal tutor',
            default => '',
        };
    }

    private function certificateLabel(array $row): string
    {
        if (empty($row['certificate_code'])) {
            return 'no';
        }

        return ($row['certificate_revoked_at'] ?? null) !== null
            ? 'revocato (' . $row['certificate_code'] . ')'
            : $row['certificate_code'];
    }
}
