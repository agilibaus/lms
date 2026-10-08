<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Mail\Mailer;
use App\Core\Url;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\ModuleModel;
use App\Models\QuestionModel;
use App\Models\UserModel;

/**
 * Le domande degli studenti al tutor (07/10). Le regole stanno in
 * `QuestionModel`; qui chi puo' fare che cosa.
 *
 *   - Fa una domanda lo studente iscritto al corso.
 *   - Risponde, pubblicando, o scarta: l'admin per tutte
 *     (`question.answer`), il tutor per quelle che gli sono assegnate
 *     (`question.answer_own`). Una domanda senza tutor la vede solo l'admin.
 *   - Le stesse persone, con la stessa regola, correggono una domanda gia'
 *     pubblicata o la tolgono dall'archivio (08/10).
 */
class QuestionController
{
    public function store(array $params): void
    {
        Auth::requireLogin();

        $course = CourseModel::find((int) $params['id']);
        $userId = (int) Auth::id();

        if ($course === null || !Auth::hasRole('studente') || EnrollmentModel::find($userId, (int) $course['id']) === null) {
            http_response_code(403);
            echo 'Puoi fare domande solo nei corsi a cui sei iscritto.';
            return;
        }

        $courseId = (int) $course['id'];
        $redirect = '/courses/' . $courseId . '#domande';
        $testo = trim(str_replace("\r\n", "\n", (string) ($_POST['question'] ?? '')));

        // Il testo che lo studente stava scrivendo si conserva se c'e' un
        // errore: rifarlo da capo sarebbe la ragione per non riprovare.
        $_SESSION['question_old'] = ['text' => $testo, 'module' => (string) ($_POST['module_id'] ?? '')];

        if ($testo === '') {
            $this->torna($redirect, 'Scrivi la domanda prima di inviarla.', false);
        }

        if (mb_strlen($testo) > QuestionModel::MAX_QUESTION_CHARS) {
            $this->torna($redirect, 'La domanda supera i ' . QuestionModel::MAX_QUESTION_CHARS . ' caratteri.', false);
        }

        $moduleId = $this->moduloDelCorso((string) ($_POST['module_id'] ?? ''), $courseId);

        if ($moduleId === false) {
            $this->torna($redirect, 'Il modulo scelto non è di questo corso.', false);
        }

        $tutorId = QuestionModel::tutorFor($courseId, $userId);
        QuestionModel::create($courseId, $moduleId, $userId, $tutorId, $testo);
        unset($_SESSION['question_old']);

        $this->avvisa($tutorId, (string) (UserModel::find($userId)['full_name'] ?? ''), (string) $course['title'], $moduleId, $testo);

        $this->torna($redirect, 'Domanda inviata al tutor: la trovi qui sotto, in «Le tue domande».', true);
    }

    public function index(array $params = []): void
    {
        Auth::requireLogin();

        if (!Auth::canAny('question.answer', 'question.answer_own')) {
            http_response_code(403);
            echo 'Non hai accesso alle domande degli studenti.';
            return;
        }

        $tutte = Auth::can('question.answer');
        $domande = QuestionModel::pending((int) Auth::id(), $tutte);
        $pubblicate = QuestionModel::publishedFor((int) Auth::id(), $tutte);
        $moduli = [];

        foreach (array_unique(array_map(static fn (array $q): int => (int) $q['course_id'], [...$domande, ...$pubblicate])) as $courseId) {
            $moduli[$courseId] = ModuleModel::forCourse($courseId);
        }

        View::render('questions/index', [
            'pageTitle' => 'Domande',
            'domande' => $domande,
            'pubblicate' => $pubblicate,
            'moduli' => $moduli,
            'tutte' => $tutte,
            // La pubblicata da aprire, arrivando dal «Modifica» dell'archivio.
            'apri' => (int) ($_GET['apri'] ?? 0),
        ]);
    }

    public function publish(array $params): void
    {
        $domanda = $this->daGestire((int) $params['id']);
        [$testo, $moduleId, $risposta] = $this->campi($domanda, '/domande#domanda-' . (int) $domanda['id']);

        $fatto = QuestionModel::publish((int) $domanda['id'], $testo, $moduleId, $risposta, (int) Auth::id());
        $this->torna('/domande', $fatto ? 'Domanda pubblicata.' : 'Questa domanda era già stata gestita.', $fatto);
    }

    /** Corregge una domanda gia' pubblicata (08/10). */
    public function update(array $params): void
    {
        $domanda = $this->daGestire((int) $params['id']);
        $qui = '/domande?apri=' . (int) $domanda['id'] . '#pubblicata-' . (int) $domanda['id'];
        [$testo, $moduleId, $risposta] = $this->campi($domanda, $qui);

        $fatto = QuestionModel::update((int) $domanda['id'], $testo, $moduleId, $risposta);
        $this->torna($fatto ? $qui : '/domande', $fatto ? 'Modifica salvata.' : 'Questa domanda non è più pubblicata.', $fatto);
    }

    /**
     * Toglie una domanda dall'archivio (08/10): torna «Non pubblicata», e lo
     * studente la vede ancora fra le sue con quello stato (Elena).
     */
    public function withdraw(array $params): void
    {
        $domanda = $this->daGestire((int) $params['id']);
        $fatto = QuestionModel::withdraw((int) $domanda['id']);
        $this->torna('/domande', $fatto ? 'Domanda tolta dall\'archivio.' : 'Questa domanda non è più pubblicata.', $fatto);
    }

    /**
     * Chi puo' gestire una domanda: l'admin tutte, il tutor quelle assegnate
     * a lui. Una regola sola per la pagina «Domande», per le azioni e per il
     * «Modifica» dell'archivio del corso.
     *
     * @param array<string, mixed> $domanda
     */
    public static function puoGestire(array $domanda): bool
    {
        return Auth::can('question.answer')
            || (Auth::can('question.answer_own') && $domanda['tutor_id'] !== null && (int) $domanda['tutor_id'] === (int) Auth::id());
    }

    /**
     * Domanda, modulo e risposta dal modulo, controllati: gli stessi per
     * pubblicare e per correggere. Se qualcosa non va, torna a `$dove` con
     * l'errore.
     *
     * @param array<string, mixed> $domanda
     * @return array{0: string, 1: ?int, 2: string}
     */
    private function campi(array $domanda, string $dove): array
    {
        $testo = trim(str_replace("\r\n", "\n", (string) ($_POST['question'] ?? '')));
        $risposta = trim(str_replace("\r\n", "\n", (string) ($_POST['answer'] ?? '')));

        if ($testo === '' || $risposta === '') {
            $this->torna($dove, 'Servono la domanda e la risposta.', false);
        }

        if (mb_strlen($testo) > QuestionModel::MAX_QUESTION_CHARS || mb_strlen($risposta) > QuestionModel::MAX_ANSWER_CHARS) {
            $this->torna($dove, 'La domanda o la risposta sono troppo lunghe.', false);
        }

        $moduleId = $this->moduloDelCorso((string) ($_POST['module_id'] ?? ''), (int) $domanda['course_id']);

        if ($moduleId === false) {
            $this->torna($dove, 'Il modulo scelto non è di questo corso.', false);
        }

        return [$testo, $moduleId, $risposta];
    }

    public function discard(array $params): void
    {
        $domanda = $this->daGestire((int) $params['id']);
        $fatto = QuestionModel::discard((int) $domanda['id'], (int) Auth::id());
        $this->torna('/domande', $fatto ? 'Domanda scartata.' : 'Questa domanda era già stata gestita.', $fatto);
    }

    /**
     * La domanda, se chi chiede la puo' gestire: l'admin tutte, il tutor
     * quelle assegnate a lui. Altrimenti risponde 403 e si ferma.
     *
     * @return array<string, mixed>
     */
    private function daGestire(int $id): array
    {
        Auth::requireLogin();

        $domanda = QuestionModel::find($id);
        $puo = $domanda !== null && self::puoGestire($domanda);

        if (!$puo) {
            http_response_code(403);
            echo 'Non puoi gestire questa domanda.';
            exit;
        }

        return $domanda;
    }

    /**
     * Il modulo scelto, se e' del corso; null per «il corso in generale»;
     * false se non e' del corso (un indirizzo scritto a mano).
     */
    private function moduloDelCorso(string $valore, int $courseId): int|false|null
    {
        if ($valore === '') {
            return null;
        }

        $modulo = ModuleModel::find((int) $valore);

        return $modulo !== null && (int) $modulo['course_id'] === $courseId ? (int) $modulo['id'] : false;
    }

    /**
     * La notifica di una domanda nuova (Elena: da subito): al tutor, o agli
     * amministratori se non c'e' un tutor. Se la posta non parte la domanda
     * resta comunque salvata e in attesa: si vede nella pagina «Domande».
     */
    private function avvisa(?int $tutorId, string $studente, string $corso, ?int $moduleId, string $testo): void
    {
        $modulo = $moduleId !== null ? (string) (ModuleModel::find($moduleId)['title'] ?? 'modulo') : 'il corso in generale';
        $link = Url::to('/domande');
        $destinatari = [];

        if ($tutorId !== null) {
            $tutor = UserModel::find($tutorId);
            if ($tutor !== null) {
                $destinatari[] = ['email' => (string) $tutor['email'], 'full_name' => (string) $tutor['full_name']];
            }
        }

        if ($destinatari === []) {
            $destinatari = QuestionModel::adminRecipients();
        }

        foreach ($destinatari as $d) {
            Mailer::sendQuietly(Mailer::newQuestion($d['email'], $d['full_name'], $studente, $corso, $modulo, $testo, $link));
        }
    }

    private function torna(string $dove, string $messaggio, bool $bene): never
    {
        $_SESSION[$bene ? 'flash_success' : 'flash_error'] = $messaggio;
        header('Location: ' . $dove);
        exit;
    }
}
