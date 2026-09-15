<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Csv;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\GroupModel;
use App\Models\QuizAttemptModel;
use App\Models\ReportModel;
use App\Models\UserModel;

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
            'courses' => ReportModel::coursesOverview(),
            'students' => $students,
            'groups' => $this->visibleGroups(),
            'restricted' => $allowed !== null,
        ]);
    }

    // ---------------------------------------------------------------
    // Report per corso
    // ---------------------------------------------------------------

    public function course(array $params): void
    {
        $this->requireReportAccess();

        $course = CourseModel::find((int) $params['id']);

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

    public function courseCsv(array $params): void
    {
        $this->requireReportAccess();

        $course = CourseModel::find((int) $params['id']);

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        $totals = ReportModel::courseTotals((int) $course['id']);
        $rows = [];

        foreach ($this->courseRows((int) $course['id']) as $row) {
            $rows[] = [
                $row['full_name'],
                $row['email'],
                $row['enrolled_at'],
                number_format((float) $row['progress_pct'], 2, ',', ''),
                $row['lessons_completed'] . '/' . $totals['lessons'],
                $row['quizzes_passed'] . '/' . $totals['quizzes'],
                $row['completed_at'] ?? '',
                $this->certificateLabel($row),
            ];
        }

        Csv::send(
            'report-corso-' . Csv::slug((string) $course['title']) . '.csv',
            ['Studente', 'Email', 'Iscritto il', 'Progresso %', 'Lezioni completate', 'Quiz superati', 'Completato il', 'Certificato'],
            $rows
        );
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
        ]);
    }

    public function studentCsv(array $params): void
    {
        $this->requireReportAccess();

        $student = $this->findVisibleStudent((int) $params['id']);

        if ($student === null) {
            return;
        }

        $rows = [];

        foreach (ReportModel::studentDetail((int) $student['id']) as $row) {
            $rows[] = [
                $row['course_title'],
                $row['enrolled_at'],
                number_format((float) $row['progress_pct'], 2, ',', ''),
                $row['lessons_completed'] . '/' . $row['lessons_total'],
                $row['quizzes_passed'] . '/' . $row['quizzes_total'],
                $row['completed_at'] ?? '',
                $this->certificateLabel($row),
            ];
        }

        Csv::send(
            'report-studente-' . Csv::slug((string) $student['full_name']) . '.csv',
            ['Corso', 'Iscritto il', 'Progresso %', 'Lezioni completate', 'Quiz superati', 'Completato il', 'Certificato'],
            $rows
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

    public function groupCsv(array $params): void
    {
        $this->requireReportAccess();

        $group = $this->findVisibleGroup((int) $params['id']);

        if ($group === null) {
            return;
        }

        $rows = [];

        foreach (ReportModel::groupDetail((int) $group['id']) as $row) {
            $rows[] = [
                $row['full_name'],
                $row['email'],
                $row['course_title'],
                $row['progress_pct'] === null ? 'non iscritto' : number_format((float) $row['progress_pct'], 2, ',', ''),
                $row['quizzes_passed'] . '/' . $row['quizzes_total'],
                $row['completed_at'] ?? '',
                $this->certificateLabel($row),
            ];
        }

        Csv::send(
            'report-gruppo-' . Csv::slug((string) $group['name']) . '.csv',
            ['Studente', 'Email', 'Corso', 'Progresso %', 'Quiz superati', 'Completato il', 'Certificato'],
            $rows
        );
    }

    // ---------------------------------------------------------------
    // Helper privati
    // ---------------------------------------------------------------

    private function requireReportAccess(): void
    {
        Auth::requireLogin();

        if (!Auth::can('report.view') && !Auth::can('report.view_assigned')) {
            http_response_code(403);
            exit('Accesso negato: non hai i permessi per consultare i report.');
        }
    }

    /**
     * Perimetro visibile: null = nessuna restrizione (admin/tutor),
     * altrimenti gli id degli studenti dei gruppi del tutor supervisore.
     *
     * @return int[]|null
     */
    private function allowedStudentIds(): ?array
    {
        if (Auth::can('report.view')) {
            return null;
        }

        $me = UserModel::find((int) Auth::id());
        $tutorId = (int) ($me['supervising_tutor_id'] ?? 0);

        return $tutorId > 0 ? GroupModel::memberIdsForTutor($tutorId) : [];
    }

    private function visibleGroups(): array
    {
        if (Auth::can('report.view')) {
            return GroupModel::all();
        }

        $me = UserModel::find((int) Auth::id());
        $tutorId = (int) ($me['supervising_tutor_id'] ?? 0);

        return $tutorId > 0 ? GroupModel::forTutor($tutorId) : [];
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
