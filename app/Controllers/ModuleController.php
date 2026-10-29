<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\CourseRights;
use App\Core\TestoBreve;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\LessonModel;
use App\Models\ModuleModel;
use App\Models\QuizModel;

class ModuleController
{
    /**
     * L'obiettivo del modulo: facoltativo, al massimo 1.000 caratteri, con il
     * contatore (11/10, Alessandro). Dove si mostra agli studenti non e'
     * ancora deciso. Accetta qualche tag HTML (`TestoBreve`) e il limite
     * conta solo il testo visibile: oltre il limite il salvataggio si
     * rifiuta e il modulo torna con il testo scritto (`RIPRENDI`).
     */
    public const MAX_OBJECTIVE_CHARS = 1000;

    /** La chiave di sessione del modulo da riproporre dopo un rifiuto. */
    private const RIPRENDI = 'modulo_da_riprendere';

    public function createForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEdit((int) $params['courseId']);

        $course = CourseModel::find((int) $params['courseId']);

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        View::render('modules/form', [
            'pageTitle' => 'Nuovo modulo',
            'course' => $course,
            'module' => null,
            'riprendi' => self::riprendi('corso', (int) $course['id']),
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEdit((int) $params['courseId']);

        $courseId = (int) $params['courseId'];
        $course = CourseModel::find($courseId);

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            $_SESSION['flash_error'] = 'Il titolo del modulo è obbligatorio.';
            header('Location: /courses/' . $courseId . '/modules/create');
            exit;
        }

        $this->rifiutaOltreIlLimite('corso', $courseId, '/courses/' . $courseId . '/modules/create');
        $this->rifiutaSecondaVerifica($courseId, null, 'corso', $courseId, '/courses/' . $courseId . '/modules/create');

        ModuleModel::create(
            $courseId,
            $title,
            isset($_POST['quiz_required']),
            $this->availableFromPost(),
            $this->objectivePost(),
            isset($_POST['is_final'])
        );

        header('Location: /courses/' . $courseId);
        exit;
    }

    public function editForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['id']);

        $module = ModuleModel::find((int) $params['id']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        $course = CourseModel::find((int) $module['course_id']);

        View::render('modules/form', [
            'pageTitle' => 'Modifica modulo',
            'course' => $course,
            'module' => $module,
            'riprendi' => self::riprendi('modulo', (int) $module['id']),
            'quizH5P' => (QuizModel::forModule((int) $module['id'])['kind'] ?? '') === 'h5p',
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['id']);

        $module = ModuleModel::find((int) $params['id']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        $title = trim($_POST['title'] ?? '');

        $this->rifiutaOltreIlLimite('modulo', (int) $module['id'], '/modules/' . (int) $module['id'] . '/edit');

        // Un titolo vuoto (solo spazi: il campo e' obbligatorio nel browser)
        // non si salva, e lo si dice invece di tornare al corso come se fosse
        // andato tutto bene (13/10).
        if ($title === '') {
            $_SESSION['flash_error'] = 'Il titolo del modulo è obbligatorio: le modifiche non sono state salvate.';
            header('Location: /modules/' . (int) $module['id'] . '/edit');
            exit;
        }

        $indietro = '/modules/' . (int) $module['id'] . '/edit';
        $this->rifiutaSecondaVerifica((int) $module['course_id'], (int) $module['id'], 'modulo', (int) $module['id'], $indietro);

        // Una verifica finale non ha lezioni (28/10): accenderla su un
        // modulo che ne ha si rifiuta, invece di nasconderle.
        if (isset($_POST['is_final']) && empty($module['is_final']) && LessonModel::forModule((int) $module['id']) !== []) {
            $this->rifiuta('modulo', (int) $module['id'], $indietro,
                'Una verifica finale non ha lezioni: toglile prima di farne una verifica.');
        }

        // Un questionario H5P non puo' essere obbligatorio ne' la verifica
        // finale (29/10): il suo punteggio lo manda il browser.
        if ((isset($_POST['quiz_required']) || isset($_POST['is_final']))
            && (QuizModel::forModule((int) $module['id'])['kind'] ?? '') === 'h5p') {
            $this->rifiuta('modulo', (int) $module['id'], $indietro, QuizModel::H5P_NON_AMMESSO
                . ' Per farlo, sostituisci il questionario di questo modulo con uno a domande.');
        }

        ModuleModel::update(
            (int) $module['id'],
            $title,
            isset($_POST['quiz_required']),
            $this->availableFromPost(),
            $this->objectivePost(),
            isset($_POST['is_final'])
        );

        header('Location: /courses/' . $module['course_id']);
        exit;
    }

    /**
     * L'obiettivo dal modulo inviato, come e' stato scritto (a capo
     * convertiti); vuoto diventa null. Niente taglio: il limite conta il
     * testo visibile, e lo controlla `rifiutaOltreIlLimite()`.
     */
    private function objectivePost(): ?string
    {
        $value = TestoBreve::normalizza((string) ($_POST['objective'] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Obiettivo oltre il limite: il salvataggio si rifiuta, con una frase che
     * dice di quanto, e il modulo torna con quello che era stato scritto.
     * `$chi` dice se l'id e' del corso (modulo nuovo) o del modulo.
     */
    private function rifiutaOltreIlLimite(string $chi, int $id, string $redirect): void
    {
        $objective = (string) $this->objectivePost();
        $problema = TestoBreve::problema("L'obiettivo del modulo", $objective, self::MAX_OBJECTIVE_CHARS);

        if ($problema === null) {
            return;
        }

        $this->rifiuta($chi, $id, $redirect, $problema);
    }

    /**
     * Una sola verifica finale per corso (28/10): accenderla su un secondo
     * modulo si rifiuta, con il nome di quella che c'e' gia'.
     */
    private function rifiutaSecondaVerifica(int $courseId, ?int $questo, string $chi, int $id, string $redirect): void
    {
        if (!isset($_POST['is_final'])) {
            return;
        }

        $gia = ModuleModel::verificaFinale($courseId, $questo);

        if ($gia !== null) {
            $this->rifiuta($chi, $id, $redirect, 'Questo corso ha già una verifica finale: «' . $gia['title']
                . '». Ce ne può essere una sola per corso.');
        }
    }

    /**
     * Rifiuta il salvataggio con `$messaggio` e torna a `$redirect` con i
     * campi come erano stati scritti (`RIPRENDI`).
     */
    private function rifiuta(string $chi, int $id, string $redirect, string $messaggio): never
    {
        $_SESSION[self::RIPRENDI] = [
            'chi' => $chi,
            'id' => $id,
            'campi' => [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'objective' => (string) $this->objectivePost(),
                'quiz_required' => isset($_POST['quiz_required']) ? 1 : 0,
                'is_final' => isset($_POST['is_final']) ? 1 : 0,
                'available_from' => $this->availableFromPost(),
            ],
        ];
        $_SESSION['flash_error'] = $messaggio;

        header('Location: ' . $redirect);
        exit;
    }

    /**
     * Il modulo rifiutato da riproporre, una volta sola, o null.
     *
     * @return array<string, mixed>|null
     */
    private static function riprendi(string $chi, int $id): ?array
    {
        $salvato = $_SESSION[self::RIPRENDI] ?? null;
        unset($_SESSION[self::RIPRENDI]);

        if (!is_array($salvato) || ($salvato['chi'] ?? '') !== $chi || (int) ($salvato['id'] ?? -1) !== $id
            || !is_array($salvato['campi'] ?? null)) {
            return null;
        }

        return $salvato['campi'];
    }

    /**
     * La data di apertura dal modulo inviato, o null se il campo e' vuoto.
     *
     * Vuoto vuol dire «sempre aperto», ed e' il comportamento di tutti i
     * moduli che esistevano prima del rilascio progressivo: svuotare il campo
     * riapre il modulo, non lo lascia com'era.
     *
     * Una data incomprensibile diventa anch'essa null. E' voluto: il campo e'
     * un `datetime-local`, quindi il browser manda solo valori validi, e
     * quello che arriva storto arriva da un POST costruito a mano. Fra il
     * rifiutare il salvataggio e il lasciare il modulo aperto, la seconda non
     * nasconde contenuto per sbaglio.
     */
    private function availableFromPost(): ?string
    {
        $value = trim((string) ($_POST['available_from'] ?? ''));

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $date === false ? null : $date->format('Y-m-d H:i:s');
    }

    /**
     * Sposta il modulo di un posto su o giu' nell'ordine del corso.
     */
    public function move(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['id']);

        $module = ModuleModel::find((int) $params['id']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        // La verifica finale non si sposta e nessun modulo scende sotto di
        // lei: lo impedisce `move` (28/10).
        ModuleModel::move((int) $module['id'], ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down');

        // L'ancora riporta al modulo appena spostato invece che in cima alla
        // pagina: in un corso lungo, altrimenti, dopo ogni clic si perde il
        // segno e bisogna ritrovare il punto.
        header('Location: /courses/' . $module['course_id'] . '#modulo-' . (int) $module['id']);
        exit;
    }

    public function destroy(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['id']);

        $module = ModuleModel::find((int) $params['id']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        ModuleModel::delete((int) $module['id']);

        header('Location: /courses/' . $module['course_id']);
        exit;
    }
}
