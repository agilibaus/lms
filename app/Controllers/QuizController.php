<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\CampoContato;
use App\Auth\CourseRights;
use App\Auth\StudentScope;
use App\Core\CertificateService;
use App\Core\CourseAccess;
use App\Core\Percorso;
use App\Core\H5PArchivio;
use App\Core\OrphanFiles;
use App\Core\QuizScoring;
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

    /**
     * Quanto puo' essere lunga una risposta aperta. Scelto da Elena.
     *
     * Vale in tre posti e per tre motivi diversi: `maxlength` sul campo, che
     * il browser fa rispettare **senza JavaScript**; il contatore che scala
     * mentre si scrive, che e' solo un di piu'; e il taglio in PHP piu'
     * sotto, che e' l'unico che conta davvero, perche' il corpo di una
     * richiesta lo scrive chi vuole.
     *
     * In caratteri, non in byte: `answer_text` e' un TEXT da 65.535 byte, e
     * 3.000 caratteri accentati in utf8mb4 ne occupano al massimo 12.000.
     * Nessuna migrazione.
     */
    public const MAX_OPEN_CHARS = 3000;

    /** Il testo di una domanda del questionario: 500 caratteri (10/10, Alessandro). */
    public const MAX_QUESTION_CHARS = 500;

    // ---------------------------------------------------------------
    // Gestione quiz — admin/tutor
    // ---------------------------------------------------------------

    public function createForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['moduleId']);

        $module = ModuleModel::find((int) $params['moduleId']);

        if (!$module) {
            $this->notFound('Modulo non trovato.');
            return;
        }

        if (QuizModel::forModule((int) $module['id']) !== null) {
            $_SESSION['flash_error'] = 'Questo modulo ha già un questionario.';
            $this->redirect('/courses/' . $module['course_id']);
        }

        View::render('quizzes/form', [
            'pageTitle' => 'Nuovo quiz',
            'module' => $module,
            'course' => CourseModel::find((int) $module['course_id']),
            'quiz' => null,
            'h5pDisponibile' => H5PArchivio::disponibile(),
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['moduleId']);

        $module = ModuleModel::find((int) $params['moduleId']);

        if (!$module) {
            $this->notFound('Modulo non trovato.');
            return;
        }

        if (QuizModel::forModule((int) $module['id']) !== null) {
            $_SESSION['flash_error'] = 'Questo modulo ha già un questionario.';
            $this->redirect('/courses/' . $module['course_id']);
        }

        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            $_SESSION['flash_error'] = 'Il titolo del questionario è obbligatorio.';
            $this->redirect('/modules/' . $module['id'] . '/quiz/create');
        }

        // Il questionario H5P (22/10): si crea con il suo contenuto, o non si
        // crea. Il file si controlla prima di creare il questionario, la
        // cartella si apre dopo (porta il suo numero).
        if (($_POST['kind'] ?? '') === 'h5p') {
            $file = $this->fileH5P('/modules/' . $module['id'] . '/quiz/create', true);
            $quizId = QuizModel::create((int) $module['id'], $title, $this->passingScoreFromPost());

            try {
                $aperto = H5PArchivio::apri($file['tmp_name'], $quizId);
            } catch (\RuntimeException $e) {
                QuizModel::delete($quizId);
                $_SESSION['flash_error'] = $e->getMessage();
                $this->redirect('/modules/' . $module['id'] . '/quiz/create');
            }

            QuizModel::impostaH5P($quizId, $aperto['dir'], $aperto['libreria'], (string) $file['name']);
            $_SESSION['flash_success'] = 'Questionario creato con il contenuto «' . ($aperto['titolo'] !== '' ? $aperto['titolo'] : $file['name']) . '».';
            $this->redirect('/quizzes/' . $quizId . '/edit');
        }

        $quizId = QuizModel::create((int) $module['id'], $title, $this->passingScoreFromPost());

        $this->redirect('/quizzes/' . $quizId . '/edit');
    }

    public function editForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditQuiz((int) $params['id']);

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Questionario non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);

        if ($module === null) {
            $this->notFound('Questionario non trovato.');
            return;
        }

        if (($quiz['kind'] ?? '') === 'h5p') {
            View::render('quizzes/edit_h5p', [
                'pageTitle' => 'Modifica quiz',
                'quiz' => $quiz,
                'module' => $module,
                'course' => CourseModel::find((int) $module['course_id']),
                'tentativi' => QuizAttemptModel::countForQuiz((int) $quiz['id']),
                'h5pDisponibile' => H5PArchivio::disponibile(),
            ]);

            return;
        }

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
        CourseRights::requireEditQuiz((int) $params['id']);

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Questionario non trovato.');
            return;
        }

        $title = trim($_POST['title'] ?? '');

        // Come per il modulo: un titolo vuoto non si salva, e si dice (13/10).
        if ($title === '') {
            $_SESSION['flash_error'] = 'Il titolo del questionario è obbligatorio: le modifiche non sono state salvate.';
            $this->redirect('/quizzes/' . $quiz['id'] . '/edit');
        }

        QuizModel::update((int) $quiz['id'], $title, $this->passingScoreFromPost());

        // Un contenuto H5P nuovo al posto del vecchio (22/10): la cartella
        // nuova prima, la vecchia si toglie solo quando la nuova e' a posto.
        // I tentativi gia' svolti restano.
        if (($quiz['kind'] ?? '') === 'h5p') {
            $file = $this->fileH5P('/quizzes/' . $quiz['id'] . '/edit', false);

            if ($file !== null) {
                try {
                    $aperto = H5PArchivio::apri($file['tmp_name'], (int) $quiz['id']);
                } catch (\RuntimeException $e) {
                    $_SESSION['flash_error'] = $e->getMessage() . ' Il contenuto di prima è rimasto.';
                    $this->redirect('/quizzes/' . $quiz['id'] . '/edit');
                }

                QuizModel::impostaH5P((int) $quiz['id'], $aperto['dir'], $aperto['libreria'], (string) $file['name']);
                // Quello di prima resta sul server (29/10), come un video sostituito.
                $_SESSION['flash_success'] = 'Contenuto sostituito con «' . ($aperto['titolo'] !== '' ? $aperto['titolo'] : $file['name']) . '».'
                    . (!empty($quiz['h5p_dir']) ? ' Quello di prima resta sul server, in storage/h5p/' . $quiz['h5p_dir'] . '.' : '');
            } else {
                $_SESSION['flash_success'] = 'Impostazioni salvate.';
            }
        }

        $this->redirect('/quizzes/' . $quiz['id'] . '/edit');
    }

    public function destroy(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditQuiz((int) $params['id']);

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Questionario non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);
        // Il contenuto H5P resta sul server (29/10), come i file di una
        // lezione eliminata, e chi elimina lo sa.
        $resta = OrphanFiles::cartellaH5P($quiz['h5p_dir'] ?? null);
        QuizModel::delete((int) $quiz['id']);

        if ($resta['count'] > 0) {
            $_SESSION['flash_success'] = 'Questionario eliminato. I file del suo contenuto H5P ('
                . OrphanFiles::humanSize($resta['bytes']) . ') restano sul server, in storage/h5p/' . $quiz['h5p_dir']
                . ', e non sono più collegati a nessun questionario.';
        }

        $this->redirect('/courses/' . ($module['course_id'] ?? ''));
    }

    // ---------------------------------------------------------------
    // Domande — admin/tutor
    // ---------------------------------------------------------------

    public function storeQuestion(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditQuiz((int) $params['id']);

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Questionario non trovato.');
            return;
        }

        // Un questionario H5P non ha domande: il contenuto e' l'esercizio.
        if (($quiz['kind'] ?? '') === 'h5p') {
            $_SESSION['flash_error'] = 'Questo è un questionario H5P: le domande stanno nel contenuto, non si aggiungono qui.';
            $this->redirect('/quizzes/' . $quiz['id'] . '/edit');
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
        CourseRights::requireEditQuestion((int) $params['id']);

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
        CourseRights::requireEditQuestion((int) $params['id']);

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

    /**
     * Sposta una domanda di un posto su o giu'. Come per lezioni e
     * materiali: due pulsanti, nessun trascinamento, funziona senza
     * JavaScript.
     */
    public function moveQuestion(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditQuestion((int) $params['id']);

        $question = QuizQuestionModel::find((int) $params['id']);

        if (!$question) {
            $this->notFound('Domanda non trovata.');
            return;
        }

        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
        QuizQuestionModel::move((int) $question['id'], $direction);

        $this->redirect('/quizzes/' . $question['quiz_id'] . '/edit');
    }

    public function destroyQuestion(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditQuestion((int) $params['id']);

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
            $this->notFound('Questionario non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);

        if ($module === null) {
            $this->notFound('Questionario non trovato.');
            return;
        }
        $course = CourseModel::find((int) $module['course_id']);

        if (!$this->guardQuizAccess($module, $quiz)) {
            return;
        }

        // Il questionario H5P (22/10): il riproduttore al posto delle
        // domande. Aprendo la pagina si segna in sessione che i file del
        // contenuto si possono servire (`H5PController::file`).
        if (($quiz['kind'] ?? '') === 'h5p') {
            $_SESSION[H5PController::SESSIONE][(int) $quiz['id']] = true;
            $best = QuizAttemptModel::bestForUserAndQuiz((int) Auth::id(), (int) $quiz['id']);

            View::render('quizzes/take_h5p', [
                'pageTitle' => $quiz['title'],
                'quiz' => $quiz,
                'module' => $module,
                'course' => $course,
                'attempts' => QuizAttemptModel::forUserAndQuiz((int) Auth::id(), (int) $quiz['id']),
                'best' => $best,
                'concluso' => Auth::hasRole('studente') && QuizScoring::concluso($best),
            ]);

            return;
        }

        $questions = QuizQuestionModel::forQuiz((int) $quiz['id']);
        $optionsByQuestion = [];
        $correctCountByQuestion = [];

        foreach ($questions as $question) {
            // Senza il flag is_correct: la soluzione non deve finire nell'HTML.
            $optionsByQuestion[$question['id']] = QuizOptionModel::forQuestionWithoutAnswers((int) $question['id']);

            /*
             * Quante risposte corrette ha la domanda. Il **numero** si puo'
             * dire — serve allo studente per sapere quante sceglierne — ma
             * quali siano no: nell'HTML arriva un conteggio, non un indizio
             * su chi e' la giusta.
             */
            $correctCountByQuestion[$question['id']] = count(
                QuizOptionModel::correctIdsForQuestion((int) $question['id'])
            );
        }

        $best = QuizAttemptModel::bestForUserAndQuiz((int) Auth::id(), (int) $quiz['id']);

        View::render('quizzes/take', [
            'pageTitle' => $quiz['title'],
            'quiz' => $quiz,
            'module' => $module,
            'course' => $course,
            'questions' => $questions,
            'optionsByQuestion' => $optionsByQuestion,
            'correctCountByQuestion' => $correctCountByQuestion,
            'attempts' => QuizAttemptModel::forUserAndQuiz((int) Auth::id(), (int) $quiz['id']),
            'best' => $best,
            // Superato al 100% (23/10): la pagina e' spenta. Solo per lo
            // studente: lo staff lo guarda in anteprima.
            'concluso' => Auth::hasRole('studente') && QuizScoring::concluso($best),
        ]);
    }

    public function submit(array $params): void
    {
        Auth::requireLogin();

        $quiz = QuizModel::find((int) $params['id']);

        if (!$quiz) {
            $this->notFound('Questionario non trovato.');
            return;
        }

        $module = ModuleModel::find((int) $quiz['module_id']);

        if ($module === null) {
            $this->notFound('Questionario non trovato.');
            return;
        }

        if (!$this->guardQuizAccess($module, $quiz)) {
            return;
        }

        // Un questionario H5P salva i tentativi da solo, a fine esercizio.
        if (($quiz['kind'] ?? '') === 'h5p') {
            $this->redirect('/quizzes/' . $quiz['id']);
        }

        $questions = QuizQuestionModel::forQuiz((int) $quiz['id']);

        // Superato al 100% (23/10): concluso, niente altri tentativi, anche
        // con un invio costruito a mano o da una pagina rimasta aperta.
        if (QuizScoring::concluso(QuizAttemptModel::bestForUserAndQuiz((int) Auth::id(), (int) $quiz['id']))) {
            $_SESSION['flash_error'] = QuizScoring::fraseConcluso(QuizScoring::conteggioValutate($questions))
                . ' Il nuovo tentativo non è stato salvato.';
            $this->redirect('/quizzes/' . $quiz['id']);
        }

        if ($questions === []) {
            $_SESSION['flash_error'] = 'Questo quiz non ha ancora domande.';
            $this->redirect('/quizzes/' . $quiz['id']);
        }

        $submitted = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
        $testi = is_array($_POST['open'] ?? null) ? $_POST['open'] : [];

        $answers = [];
        $giuste = 0;
        $valutate = 0;

        foreach ($questions as $question) {
            $questionId = (int) $question['id'];
            $tipo = (string) $question['question_type'];

            // --- risposta aperta: si raccoglie, non si corregge ----------
            if ($tipo === 'open') {
                // Tagliato con gli a capo contati come li conta il campo
                // (10/10): prima una risposta piena con degli a capo
                // perdeva la fine, uno per a capo.
                $testo = CampoContato::taglia((string) ($testi[$questionId] ?? ''), self::MAX_OPEN_CHARS);

                if ($testo === '') {
                    $_SESSION['flash_error'] = 'Rispondi a tutte le domande prima di inviare il quiz.';
                    $this->redirect('/quizzes/' . $quiz['id']);
                }

                $answers[] = [
                    'question_id' => $questionId,
                    'selected_option_id' => null,
                    // Tagliato qui sopra, per non lasciare che il corpo della
                    // richiesta decida quanto spazio occupare nel database.
                    'answer_text' => $testo,
                    // Non e' «sbagliata»: e' fuori dal punteggio. Il valore
                    // nella colonna non viene letto per le aperte, e vale 0
                    // perche' la colonna non ammette nulla.
                    'is_correct' => false,
                ];

                continue;
            }

            $valutate++;

            // --- le opzioni scelte, comunque siano arrivate ---------------
            //
            // La multipla manda un elenco, gli altri tipi un valore solo:
            // si normalizza a elenco e il resto del codice non deve piu'
            // sapere la differenza.
            $grezze = $submitted[$questionId] ?? null;
            $scelte = is_array($grezze) ? array_map('intval', $grezze) : [(int) $grezze];
            $scelte = array_values(array_unique(array_filter($scelte, static fn (int $v): bool => $v > 0)));

            if ($scelte === []) {
                $_SESSION['flash_error'] = 'Rispondi a tutte le domande prima di inviare il quiz.';
                $this->redirect('/quizzes/' . $quiz['id']);
            }

            if (!QuizScoring::piuRisposte($tipo) && count($scelte) > 1) {
                // Una domanda a scelta singola con due risposte non arriva da
                // un modulo onesto: si ferma invece di tenerne una a caso.
                $_SESSION['flash_error'] = 'Su questa domanda si può scegliere una sola risposta.';
                $this->redirect('/quizzes/' . $quiz['id']);
            }

            // Ogni opzione deve esistere e appartenere **a questa domanda**:
            // senza questo controllo il punteggio sarebbe manipolabile
            // mandando l'identificativo di un'opzione corretta di un'altra.
            foreach ($scelte as $optionId) {
                $option = QuizOptionModel::find($optionId);

                if ($option === null || (int) $option['question_id'] !== $questionId) {
                    $_SESSION['flash_error'] = 'Rispondi a tutte le domande prima di inviare il quiz.';
                    $this->redirect('/quizzes/' . $quiz['id']);
                }
            }

            $corrette = QuizOptionModel::correctIdsForQuestion($questionId);

            $indovinata = QuizScoring::piuRisposte($tipo)
                ? QuizScoring::multiplaGiusta($scelte, $corrette)
                : in_array($scelte[0], $corrette, true);

            $giuste += $indovinata ? 1 : 0;

            /*
             * Una riga per opzione scelta. `is_correct` dice se **l'intera
             * domanda** e' stata indovinata, non se quella singola opzione
             * era giusta: con il «tutto o niente» il verdetto e' della
             * domanda, e due righe della stessa domanda portano lo stesso
             * valore.
             */
            foreach ($scelte as $optionId) {
                $answers[] = [
                    'question_id' => $questionId,
                    'selected_option_id' => $optionId,
                    'answer_text' => null,
                    'is_correct' => $indovinata,
                ];
            }
        }

        $scorePct = QuizScoring::percentuale($giuste, $valutate);
        $passed = QuizScoring::superato($scorePct, (int) $quiz['passing_score_pct'], $valutate);

        $attemptId = QuizAttemptModel::create((int) Auth::id(), (int) $quiz['id'], $scorePct, $passed, $answers);

        if ($passed) {
            // Superare l'ultimo quiz mancante puo' completare i requisiti del
            // certificato. Un certificato che non si riesce a generare (per
            // esempio senza Dompdf) non deve far perdere allo studente la
            // pagina del risultato, con il tentativo gia' salvato (13/10): si
            // scrive nel log, come nella pagina del corso, che riprova a ogni
            // visita. La pagina del risultato non mostra avvisi, quindi niente
            // messaggio in sessione, che comparirebbe poi altrove.
            try {
                CertificateService::issueIfEligible((int) Auth::id(), (int) $module['course_id']);
            } catch (\RuntimeException $e) {
                error_log('[Certificati] ' . $e->getMessage());
            }
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

        // Ognuno vede i propri tentativi; lo staff quelli degli studenti che
        // segue, con la stessa regola del report dello studente da cui si
        // arriva qui (17/10, StudentScope). Prima bastava il ruolo: qualunque
        // tutor o assistente apriva il tentativo di qualunque studente.
        $quiz = QuizModel::find((int) $attempt['quiz_id']);

        if ($quiz === null) {
            $this->notFound('Tentativo non trovato.');
            return;
        }

        // Dal 26/10 anche nel corso giusto: dello studente si vedono i corsi
        // che fa con chi guarda (`StudentScope::puoVedereNelCorso`), non i
        // tentativi di un corso seguito con un altro tutor.
        $corsoDelQuiz = (int) (ModuleModel::find((int) $quiz['module_id'])['course_id'] ?? 0);

        if ((int) $attempt['user_id'] !== (int) Auth::id()
            && !StudentScope::puoAprireTentativo((int) $attempt['user_id'], $corsoDelQuiz)) {
            http_response_code(403);
            echo 'Non puoi consultare questo tentativo.';
            return;
        }
        $module = ModuleModel::find((int) $quiz['module_id']);

        if ($module === null) {
            $this->notFound('Tentativo non trovato.');
            return;
        }

        /*
         * Il conteggio che conta e' quello delle domande **valutate**: un
         * quiz con due aperte su cinque dice «3 su 3», non «3 su 5», perche'
         * il punteggio e' calcolato su tre.
         */
        $domande = QuizQuestionModel::forQuiz((int) $quiz['id']);

        View::render('quizzes/result', [
            'pageTitle' => 'Esito quiz',
            'attempt' => $attempt,
            'quiz' => $quiz,
            'module' => $module,
            'course' => CourseModel::find((int) $module['course_id']),
            'questionCount' => QuizModel::countQuestions((int) $quiz['id']),
            'scoredCount' => QuizScoring::conteggioValutate($domande),
            'openAnswers' => QuizAttemptModel::openAnswersForAttempt((int) $attempt['id']),
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
    private function guardQuizAccess(array $module, array $quiz): bool
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

            // Il questionario e' il test d'uscita del modulo (27/10): si apre
            // con le lezioni fatte, la verifica finale con i moduli prima
            // completati. Chi ci arriva prima torna al corso, con la ragione.
            $chiuso = CourseAccess::questionarioChiuso((int) Auth::id(), $module);

            if ($chiuso !== null) {
                $_SESSION['flash_error'] = 'Il questionario «' . $quiz['title'] . '» si apre quando hai '
                    . ($chiuso === Percorso::CHIUSO_LEZIONI ? 'fatto le lezioni del modulo.' : 'completato i moduli precedenti.');
                // Senza l'ancora del modulo: l'avviso sta in cima alla
                // pagina, e saltando al modulo non si vedrebbe.
                $this->redirect('/courses/' . $courseId);
            }
        }

        return true;
    }

    /**
     * Il file .h5p arrivato dal modulo. Null se non ne e' stato scelto uno
     * e non era obbligatorio; altrimenti si torna a `$indietro` con la frase
     * che dice cosa non va.
     *
     * @return array{name: string, tmp_name: string}|null
     */
    private function fileH5P(string $indietro, bool $obbligatorio): ?array
    {
        $f = $_FILES['h5p'] ?? null;
        $errore = is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($errore === UPLOAD_ERR_NO_FILE && !$obbligatorio) {
            return null;
        }

        $messaggio = match (true) {
            !H5PArchivio::disponibile() => 'Per i contenuti H5P serve l\'estensione zip di PHP, che su questo server non c\'è.',
            $errore === UPLOAD_ERR_NO_FILE => 'Scegli il file .h5p esportato da Lumi.',
            $errore === UPLOAD_ERR_INI_SIZE, $errore === UPLOAD_ERR_FORM_SIZE => 'Il file supera il limite del server (' . \App\Core\Upload::humanIniLimit() . ').',
            $errore !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) => 'Il file non è arrivato per intero: riprova.',
            strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'h5p' => 'Il file deve essere un pacchetto .h5p (in Lumi: Esporta, formato H5P).',
            default => null,
        };

        if ($messaggio !== null) {
            $_SESSION['flash_error'] = $messaggio;
            $this->redirect($indietro);
        }

        return ['name' => basename((string) $f['name']), 'tmp_name' => (string) $f['tmp_name']];
    }

    private function passingScoreFromPost(): int
    {
        return max(1, min(100, (int) ($_POST['passing_score_pct'] ?? 70)));
    }

    /**
     * Estrae e valida testo, tipo e opzioni di una domanda dal POST.
     *
     * Quattro tipi, quattro forme diverse di dati in arrivo. La validazione
     * sta qui e non nel browser: il modulo nasconde i gruppi di campi che
     * non servono, ma quello che arriva al server lo decide chi invia.
     *
     * @return array{0: string, 1: string, 2: array<int, array{text: string, is_correct: bool}>}
     * @throws \RuntimeException se i dati non sono validi
     */
    private function questionFromPost(): array
    {
        $text = CampoContato::taglia((string) ($_POST['question_text'] ?? ''), self::MAX_QUESTION_CHARS);

        if ($text === '') {
            throw new \RuntimeException('Il testo della domanda è obbligatorio.');
        }

        $type = (string) ($_POST['question_type'] ?? 'single_choice');

        if (!QuizScoring::esiste($type)) {
            $type = 'single_choice';
        }

        // La risposta aperta non ha opzioni: non c'e' altro da validare.
        if ($type === 'open') {
            return [$text, $type, []];
        }

        if ($type === 'true_false') {
            $correctIndex = (int) ($_POST['correct_option'] ?? -1);

            if (!in_array($correctIndex, [0, 1], true)) {
                throw new \RuntimeException('Indica se la risposta corretta è Vero o Falso.');
            }

            return [$text, $type, [
                ['text' => 'Vero', 'is_correct' => $correctIndex === 0],
                ['text' => 'Falso', 'is_correct' => $correctIndex === 1],
            ]];
        }

        /*
         * Gli indici delle corrette si leggono sulle righe **come arrivano**,
         * prima di scartare quelle vuote: e' la riga numero tre del modulo
         * che e' stata spuntata, non la terza fra quelle compilate. Filtrare
         * prima sposterebbe la risposta giusta su un'altra opzione.
         */
        $corretti = $type === 'multiple_choice'
            ? array_map('intval', is_array($_POST['correct_options'] ?? null) ? $_POST['correct_options'] : [])
            : [(int) ($_POST['correct_option'] ?? -1)];

        $rawOptions = is_array($_POST['options'] ?? null) ? array_values($_POST['options']) : [];
        $options = [];

        foreach ($rawOptions as $index => $optionText) {
            $optionText = trim((string) $optionText);

            if ($optionText === '') {
                continue;
            }

            $options[] = ['text' => $optionText, 'is_correct' => in_array($index, $corretti, true)];
        }

        if (count($options) < self::MIN_OPTIONS) {
            throw new \RuntimeException('Servono almeno ' . self::MIN_OPTIONS . ' opzioni di risposta.');
        }

        $options = array_slice($options, 0, self::MAX_OPTIONS);
        $quante = count(array_filter($options, static fn (array $o): bool => $o['is_correct']));

        if ($quante === 0) {
            throw new \RuntimeException($type === 'multiple_choice'
                ? 'Spunta almeno un\'opzione corretta. Se la risposta giusta è una sola, usa il tipo "Scelta singola".'
                : 'Seleziona quale opzione è la risposta corretta.');
        }

        /*
         * Una multipla con una sola risposta corretta funzionerebbe, ma allo
         * studente arriverebbero caselle dove bastavano pallini, e la
         * scritta «seleziona 1 risposta» suonerebbe come un errore. Meglio
         * dirlo a chi costruisce il quiz.
         */
        if ($type === 'multiple_choice' && $quante === 1) {
            throw new \RuntimeException(
                'Una domanda a scelta multipla vuole almeno due risposte corrette. '
                . 'Se la risposta giusta è una sola, usa il tipo "Scelta singola".'
            );
        }

        if ($type === 'multiple_choice' && $quante === count($options)) {
            throw new \RuntimeException(
                'Tutte le opzioni sono segnate come corrette: la domanda non distinguerebbe nessuno. '
                . 'Lasciane almeno una sbagliata.'
            );
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
