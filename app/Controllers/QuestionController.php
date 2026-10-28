<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\BachecaScope;
use App\Core\CourseAccess;
use App\Core\PersonName;
use App\Auth\Auth;
use App\Core\Mail\Mailer;
use App\Core\Url;
use App\Core\View;
use App\Models\BoardModel;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\ModuleModel;
use App\Models\QuestionModel;
use App\Models\UserModel;

/**
 * Le domande degli studenti all'esperto (07/10; dal 09/10 risponde
 * l'esperto, cioe' l'admin, non piu' il tutor del gruppo). Le regole stanno
 * in `QuestionModel`; qui chi puo' fare che cosa.
 *
 *   - Fa una domanda lo studente iscritto al corso.
 *   - Risponde, pubblicando, scarta, corregge e toglie dall'archivio solo chi
 *     ha `question.answer`: l'admin, l'esperto. La domanda arriva a lui, con
 *     un'email agli amministratori.
 *   - L'archivio, «L'esperto risponde», lo leggono gli studenti dei loro
 *     corsi e i tutor dei corsi dei loro gruppi, «esattamente come uno
 *     studente» (Elena): in sola lettura.
 *
 * Dal 25/10 (Alessandro), la regola della Bacheca: la risposta e' di tutti,
 * la persona e' della classe.
 *   - Una domanda su un modulo si legge quando quel modulo e' aperto per chi
 *     guarda (`CourseAccess`); lo staff le legge tutte. Si chiede solo sui
 *     moduli aperti. Quelle sul corso in generale si leggono sempre.
 *   - Il nome di chi ha chiesto lo vedono lui, i compagni dei suoi gruppi del
 *     corso e il tutor di quei gruppi; per gli altri e' «uno studente del
 *     corso».
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
            $this->torna($redirect, 'Il modulo scelto non è fra quelli del corso.', false);
        }

        // Il menu offre solo i moduli aperti (25/10): uno chiuso arriva solo
        // scrivendo la richiesta a mano, e la domanda non la leggerebbe
        // nemmeno chi l'ha fatta.
        if ($moduleId !== null && CourseAccess::isModuleLocked($userId, $moduleId)) {
            $this->torna($redirect, 'Questo modulo non è ancora aperto.', false);
        }

        // Nessun tutor assegnato: la domanda va all'esperto, l'admin (09/10).
        QuestionModel::create($courseId, $moduleId, $userId, null, $testo);
        unset($_SESSION['question_old']);

        $this->avvisa((string) (UserModel::find($userId)['full_name'] ?? ''), (string) $course['title'], $moduleId, $testo);

        // La sezione delle domande si riapre da sola, per far vedere la
        // domanda appena inviata in «Le tue domande» (08/10).
        $_SESSION['domande_aperte'] = true;
        $this->torna($redirect, 'Domanda inviata all\'esperto: la trovi in «Le tue domande», in fondo alla pagina.', true);
    }

    /**
     * La pagina «Domande e risposte» dello studente (09/10, Elena): l'archivio
     * di un corso alla volta, quello di `?corso=` (dal 17/10 ci si arriva dal
     * ramo del corso nella sidebar, non piu' da un menu nella pagina).
     * Solo per gli studenti: lo staff ha «Domande», dove risponde e corregge.
     * Un corso a cui lo studente non e' iscritto, chiesto a mano
     * nell'indirizzo, non si apre: si mostra il primo dei suoi.
     */
    public function archive(array $params = []): void
    {
        Auth::requireLogin();

        $studente = Auth::hasRole('studente');

        if (!$studente && !Auth::hasRole('tutor', 'assistente')) {
            http_response_code(403);
            echo 'Questa pagina e\' per studenti, tutor e assistenti: le domande si gestiscono da «Domande».';
            return;
        }

        $userId = (int) Auth::id();
        // Lo studente vede i corsi a cui e' iscritto; il tutor quelli dei suoi
        // gruppi, l'assistente quelli dei suoi tutor (26/10, conflitto 7): gli
        // stessi della Bacheca, in sola lettura. L'assistente non vede i nomi
        // (`vedeIlNome`: non e' compagno ne' tutor di nessuno).
        $corsi = $studente ? CourseModel::enrolledForUser($userId) : BachecaScope::corsi();
        usort($corsi, static fn (array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title']));
        $chiesto = (int) ($_GET['corso'] ?? 0);
        $corso = null;

        foreach ($corsi as $c) {
            if ((int) $c['id'] === $chiesto) {
                $corso = $c;
            }
        }

        $corso ??= $corsi[0] ?? null;
        $cerca = trim((string) ($_GET['cerca'] ?? ''));

        View::render('questions/archive', [
            'pageTitle' => "L'esperto risponde",
            'corso' => $corso,
            'cerca' => $cerca,
            // Il tutor vede gli autori per intero, come lo staff ovunque.
            'archivio' => $corso === null ? [] : self::archivio((int) $corso['id'], $cerca, $userId, !$studente),
            // Quante ne legge senza la ricerca: sotto le cinque
            // (`_archivio.php`) il campo non compare (21/10).
            'totale' => $corso === null ? 0 : count(self::leggibili((int) $corso['id'], QuestionModel::published((int) $corso['id']), $userId)),
            // Solo lo studente chiede: al tutor il rimando non serve.
            'puoChiedere' => $studente,
        ]);
    }

    /**
     * Le pubblicate di un corso che chi guarda legge, con il nome dell'autore
     * come lo vede lui: per intero, o come l'autore l'ha scelto, se e' della
     * sua classe (`vedeIlNome`); altrimenti «uno studente del corso».
     *
     * @return list<array<string, mixed>>
     */
    private static function archivio(int $courseId, string $cerca, int $chiGuarda, bool $vedeTutto): array
    {
        return array_map(
            static fn (array $q): array => $q + ['autore' => !self::vedeIlNome($q['student_id'] === null ? null : (int) $q['student_id'], $chiGuarda, $courseId)
                ? 'uno studente del corso'
                : PersonName::shown(
                    ['id' => (int) $q['student_id'], 'first_name' => (string) $q['first_name'],
                     'last_name' => (string) $q['last_name'], 'full_name' => (string) $q['full_name'],
                     'name_display' => (string) $q['name_display']],
                    $chiGuarda,
                    $vedeTutto
                )],
            self::leggibili($courseId, QuestionModel::published($courseId, mb_substr($cerca, 0, 100)), $chiGuarda)
        );
    }

    /**
     * Senza le domande dei moduli chiusi per chi guarda (25/10). Per lo staff
     * nessun modulo e' chiuso.
     *
     * @param list<array<string, mixed>> $domande
     * @return list<array<string, mixed>>
     */
    private static function leggibili(int $courseId, array $domande, int $chiGuarda): array
    {
        $chiusi = array_map('intval', CourseAccess::lockedModuleIds($chiGuarda, $courseId));

        return array_values(array_filter(
            $domande,
            static fn (array $q): bool => $q['module_id'] === null || !in_array((int) $q['module_id'], $chiusi, true)
        ));
    }

    /**
     * Se chi guarda vede il nome di chi ha chiesto (25/10): e' lui, e' un
     * compagno di uno dei suoi gruppi del corso, o e' il tutor di uno di quei
     * gruppi. La stessa regola dei racconti della Bacheca, con gli stessi
     * elenchi (`BoardModel`), letti una volta per pagina.
     */
    private static function vedeIlNome(?int $autore, int $chiGuarda, int $courseId): bool
    {
        if ($autore === null) {
            return false;
        }

        $chiave = $chiGuarda . ':' . $courseId;
        self::$classe[$chiave] ??= array_merge(
            BoardModel::compagni($chiGuarda, $courseId),
            BoardModel::studentiDelTutor($chiGuarda, $courseId)
        );

        return $autore === $chiGuarda || in_array($autore, self::$classe[$chiave], true);
    }

    /** @var array<string, list<int>> */
    private static array $classe = [];

    public function index(array $params = []): void
    {
        Auth::requireLogin();

        if (!Auth::can('question.answer')) {
            http_response_code(403);
            echo 'Non hai accesso alle domande degli studenti.';
            return;
        }

        $tutte = Auth::can('question.answer');
        $domande = QuestionModel::pending((int) Auth::id(), $tutte);
        $pubblicate = QuestionModel::publishedFor((int) Auth::id(), $tutte);
        $moduli = [];

        foreach (array_unique(array_map(static fn (array $q): int => (int) $q['course_id'], [...$domande, ...$pubblicate])) as $courseId) {
            $moduli[$courseId] = ModuleModel::contenuti($courseId);
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

        if ($fatto) {
            self::avvisaStudente($domanda, $testo, $risposta);
        }

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
     * «La tua domanda ha una risposta» a chi l'ha fatta (12/10), solo alla
     * prima pubblicazione: una correzione successiva non manda niente. Non
     * a un account cancellato o disattivato.
     *
     * @param array<string, mixed> $domanda
     */
    private static function avvisaStudente(array $domanda, string $testo, string $risposta): void
    {
        $studente = $domanda['student_id'] === null ? null : UserModel::find((int) $domanda['student_id']);
        $corso = CourseModel::find((int) $domanda['course_id']);

        if ($studente === null || (int) $studente['is_active'] !== 1 || $corso === null) {
            return;
        }

        Mailer::sendQuietly(Mailer::questionAnswered(
            (string) $studente['email'],
            (string) $studente['full_name'],
            (string) $corso['title'],
            $testo,
            $risposta,
            Url::to('/domande-e-risposte?corso=' . (int) $corso['id'])
        ));
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
        return Auth::can('question.answer');
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
            $this->torna($dove, 'Il modulo scelto non è fra quelli del corso.', false);
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

        // La verifica finale non e' un contenuto del corso (28/10): una
        // domanda non la riguarda.
        return $modulo !== null && (int) $modulo['course_id'] === $courseId && empty($modulo['is_final'])
            ? (int) $modulo['id'] : false;
    }

    /**
     * La notifica di una domanda nuova (Elena: da subito): agli
     * amministratori, cioe' all'esperto (09/10). Se la posta non parte la
     * domanda resta comunque salvata e in attesa: si vede in «Domande».
     */
    private function avvisa(string $studente, string $corso, ?int $moduleId, string $testo): void
    {
        $modulo = $moduleId !== null ? (string) (ModuleModel::find($moduleId)['title'] ?? 'modulo') : 'il corso in generale';
        $link = Url::to('/domande');

        foreach (QuestionModel::adminRecipients() as $d) {
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
