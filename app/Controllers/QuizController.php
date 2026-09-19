<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\CertificateService;
use App\Core\CourseAccess;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\ModuleModel;
use App\Models\QuizAttemptModel;
use App\Models\QuizModel;
use App\Models\QuizOptionModel;
use App\Models\QuizQuestionModel;

/**
 * Gestione quiz (admin/tutor) e svolgimento (studente).
 * I tentativi sono illimitati: vale il punteggio migliore.
 */
class QuizController
{
    private const MIN_OPTIONS = 2;
    private const MAX_OPTIONS = 6;

    // ---------------------------------------------------------------
    // Gestione quiz — admin/tutor
    // ---------------------------------------------------------------

    public function createForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $module = ModuleModel::find((int) $params['moduleId']);

        if (!$module) {
            $this->notFound('Modulo non trovato.');
            return;
        }

        if (QuizModel::forModule((int) $module['id']) !== null) {
            $_SESSION['flash_error'] = 'Questo modulo ha già un quiz.';
            $this->redirect('/courses/' . $module['course_id']);
        }

        View::render('quizzes/form', [
            'pageTitle' => 'Nuovo quiz',
            'module' => $module,
            'course' => CourseModel::find((int) $module['course_id']),
            'quiz' => null,
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $module = ModuleModel::find((int) $params['moduleId']);

        if (!$module) {
            $this->notFound('Modulo non trovato.');
            return;
        }

        if (QuizModel::forModule((int) $module['id']) !== null) {
            $_SESSION['flash_error'] = 'Questo modulo ha già un quiz.';
            $this->redirect('/courses/' . $module['course_id']);
        }

        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            $_SESSION['flash_error'] = 'Il titolo del quiz è obbligatorio.';
            $this->redirect('/modules/' . $module['id'] . '/quiz/create');
        }

        $quizId = QuizModel::create((int) $module['id'], $title, $this->passingScoreFromPost());

        $this->redirect('/quizzes/' . $quizId . '/edit');
    }

    public function editForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Quiz non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);
        $questions = QuizQuestionModel::forQuiz((int) $quiz['id']);
        $optionsByQuestion = [];

        foreach ($questions as $question) {
            $optionsByQuestion[$question['id']] = QuizOptionModel::forQuestion((int) $question['id']);
        }

        View::render('quizzes/edit', [
            'pageTitle' => 'Modifica quiz',
            'quiz' => $quiz,
            'module' => $module,
            'course' => CourseModel::find((int) $module['course_id']),
            'questions' => $questions,
            'optionsByQuestion' => $optionsByQuestion,
            'incompleteQuestions' => QuizOptionModel::countQuestionsWithoutCorrectOption((int) $quiz['id']),
            'maxOptions' => self::MAX_OPTIONS,
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Quiz non trovato.');
            return;
        }

        $title = trim($_POST['title'] ?? '');

        if ($title !== '') {
            QuizModel::update((int) $quiz['id'], $title, $this->passingScoreFromPost());
        }

        $this->redirect('/quizzes/' . $quiz['id'] . '/edit');
    }

    public function destroy(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Quiz non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);
        QuizModel::delete((int) $quiz['id']);

        $this->redirect('/courses/' . ($module['course_id'] ?? ''));
    }

    // ---------------------------------------------------------------
    // Domande — admin/tutor
    // ---------------------------------------------------------------

    public function storeQuestion(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Quiz non trovato.');
            return;
        }

        try {
            [$text, $type, $options] = $this->questionFromPost();
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $this->redirect('/quizzes/' . $quiz['id'] . '/edit');
        }

        $questionId = QuizQuestionModel::create((int) $quiz['id'], $text, $type);
        QuizOptionModel::replaceForQuestion($questionId, $options);

        $this->redirect('/quizzes/' . $quiz['id'] . '/edit');
    }

    public function editQuestionForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $question = QuizQuestionModel::find((int) $params['id']);

        if (!$question) {
            $this->notFound('Domanda non trovata.');
            return;
        }

        View::render('quizzes/question_form', [
            'pageTitle' => 'Modifica domanda',
            'question' => $question,
            'options' => QuizOptionModel::forQuestion((int) $question['id']),
            'quiz' => QuizModel::find((int) $question['quiz_id']),
            'maxOptions' => self::MAX_OPTIONS,
        ]);
    }

    public function updateQuestion(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $question = QuizQuestionModel::find((int) $params['id']);

        if (!$question) {
            $this->notFound('Domanda non trovata.');
            return;
        }

        try {
            [$text, $type, $options] = $this->questionFromPost();
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $this->redirect('/questions/' . $question['id'] . '/edit');
        }

        QuizQuestionModel::update((int) $question['id'], $text, $type);
        QuizOptionModel::replaceForQuestion((int) $question['id'], $options);

        $this->redirect('/quizzes/' . $question['quiz_id'] . '/edit');
    }

    public function destroyQuestion(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $question = QuizQuestionModel::find((int) $params['id']);

        if (!$question) {
            $this->notFound('Domanda non trovata.');
            return;
        }

        QuizQuestionModel::delete((int) $question['id']);

        $this->redirect('/quizzes/' . $question['quiz_id'] . '/edit');
    }

    // ---------------------------------------------------------------
    // Svolgimento — studente
    // ---------------------------------------------------------------

    public function show(array $params): void
    {
        Auth::requireLogin();

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Quiz non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);
        $course = CourseModel::find((int) $module['course_id']);

        if (!$this->guardQuizAccess($module)) {
            return;
        }

        $questions = QuizQuestionModel::forQuiz((int) $quiz['id']);
        $optionsByQuestion = [];

        foreach ($questions as $question) {
            // Senza il flag is_correct: la soluzione non deve finire nell'HTML.
            $optionsByQuestion[$question['id']] = QuizOptionModel::forQuestionWithoutAnswers((int) $question['id']);
        }

        View::render('quizzes/take', [
            'pageTitle' => $quiz['title'],
            'quiz' => $quiz,
            'module' => $module,
            'course' => $course,
            'questions' => $questions,
            'optionsByQuestion' => $optionsByQuestion,
            'attempts' => QuizAttemptModel::forUserAndQuiz((int) Auth::id(), (int) $quiz['id']),
            'best' => QuizAttemptModel::bestForUserAndQuiz((int) Auth::id(), (int) $quiz['id']),
        ]);
    }

    public function submit(array $params): void
    {
        Auth::requireLogin();

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Quiz non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);

        if (!$this->guardQuizAccess($module)) {
            return;
        }

        $questions = QuizQuestionModel::forQuiz((int) $quiz['id']);

        if ($questions === []) {
            $_SESSION['flash_error'] = 'Questo quiz non ha ancora domande.';
            $this->redirect('/quizzes/' . $quiz['id']);
        }

        $submitted = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
        $answers = [];
        $correct = 0;

        foreach ($questions as $question) {
            $questionId = (int) $question['id'];
            $selectedId = (int) ($submitted[$questionId] ?? 0);
            $option = $selectedId > 0 ? QuizOptionModel::find($selectedId) : null;

            // L'opzione deve esistere e appartenere davvero a questa domanda
            // (altrimenti il punteggio sarebbe manipolabile dal client).
            if ($option === null || (int) $option['question_id'] !== $questionId) {
                $_SESSION['flash_error'] = 'Rispondi a tutte le domande prima di inviare il quiz.';
                $this->redirect('/quizzes/' . $quiz['id']);
            }

            $isCorrect = (bool) $option['is_correct'];
            $correct += $isCorrect ? 1 : 0;

            $answers[] = [
                'question_id' => $questionId,
                'selected_option_id' => $selectedId,
                'is_correct' => $isCorrect,
            ];
        }

        $scorePct = round(($correct / count($questions)) * 100, 2);
        $passed = $scorePct >= (float) $quiz['passing_score_pct'];

        $attemptId = QuizAttemptModel::create((int) Auth::id(), (int) $quiz['id'], $scorePct, $passed, $answers);

        if ($passed) {
            // Superare l'ultimo quiz mancante puo' completare i requisiti del certificato.
            CertificateService::issueIfEligible((int) Auth::id(), (int) $module['course_id']);
        }

        $this->redirect('/attempts/' . $attemptId);
    }

    public function result(array $params): void
    {
        Auth::requireLogin();

        $attempt = QuizAttemptModel::find((int) $params['id']);

        if (!$attempt) {
            $this->notFound('Tentativo non trovato.');
            return;
        }

        // Lo studente vede solo i propri tentativi; lo staff puo' consultarli tutti.
        if ((int) $attempt['user_id'] !== (int) Auth::id() && !Auth::hasRole('admin', 'tutor', 'assistente')) {
            http_response_code(403);
            echo 'Non puoi consultare questo tentativo.';
            return;
        }

        $quiz = QuizModel::find((int) $attempt['quiz_id']);
        $module = ModuleModel::find((int) $quiz['module_id']);

        View::render('quizzes/result', [
            'pageTitle' => 'Esito quiz',
            'attempt' => $attempt,
            'quiz' => $quiz,
            'module' => $module,
            'course' => CourseModel::find((int) $module['course_id']),
            'questionCount' => QuizModel::countQuestions((int) $quiz['id']),
            'best' => QuizAttemptModel::bestForUserAndQuiz((int) $attempt['user_id'], (int) $quiz['id']),
        ]);
    }

    // ---------------------------------------------------------------
    // Helper privati
    // ---------------------------------------------------------------

    /**
     * Iscrizione al corso + modulo sbloccato. Restituisce false se ha gia'
     * emesso una risposta HTTP di errore.
     */
    private function guardQuizAccess(array $module): bool
    {
        $courseId = (int) $module['course_id'];

        if (!Auth::hasRole('admin', 'tutor', 'assistente')) {
            if (EnrollmentModel::find((int) Auth::id(), $courseId) === null) {
                http_response_code(403);
                echo 'Non sei iscritto a questo corso.';

                return false;
            }

            if (CourseAccess::isModuleLocked((int) Auth::id(), (int) $module['id'])) {
                http_response_code(403);
                echo 'Questo modulo è bloccato: supera prima il quiz del modulo precedente.';

                return false;
            }
        }

        return true;
    }

    private function passingScoreFromPost(): int
    {
        return max(1, min(100, (int) ($_POST['passing_score_pct'] ?? 70)));
    }

    /**
     * Estrae e valida testo, tipo e opzioni di una domanda dal POST.
     *
     * @return array{0: string, 1: string, 2: array<int, array{text: string, is_correct: bool}>}
     * @throws \RuntimeException se i dati non sono validi
     */
    private function questionFromPost(): array
    {
        $text = trim($_POST['question_text'] ?? '');

        if ($text === '') {
            throw new \RuntimeException('Il testo della domanda è obbligatorio.');
        }

        $type = ($_POST['question_type'] ?? 'single_choice') === 'true_false' ? 'true_false' : 'single_choice';
        $correctIndex = (int) ($_POST['correct_option'] ?? -1);

        if ($type === 'true_false') {
            if (!in_array($correctIndex, [0, 1], true)) {
                throw new \RuntimeException('Indica se la risposta corretta è Vero o Falso.');
            }

            return [$text, $type, [
                ['text' => 'Vero', 'is_correct' => $correctIndex === 0],
                ['text' => 'Falso', 'is_correct' => $correctIndex === 1],
            ]];
        }

        $rawOptions = is_array($_POST['options'] ?? null) ? array_values($_POST['options']) : [];
        $options = [];

        foreach ($rawOptions as $index => $optionText) {
            $optionText = trim((string) $optionText);

            if ($optionText === '') {
                continue;
            }

            $options[] = ['text' => $optionText, 'is_correct' => $index === $correctIndex];
        }

        if (count($options) < self::MIN_OPTIONS) {
            throw new \RuntimeException('Servono almeno ' . self::MIN_OPTIONS . ' opzioni di risposta.');
        }

        $options = array_slice($options, 0, self::MAX_OPTIONS);

        if (!array_filter($options, static fn (array $o): bool => $o['is_correct'])) {
            throw new \RuntimeException('Seleziona quale opzione è la risposta corretta.');
        }

        return [$text, $type, $options];
    }

    private function notFound(string $message): void
    {
        http_response_code(404);
        echo $message;
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }
}
