<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\TutorWelcomeController;
use App\Auth\Auth;
use App\Auth\CourseRights;
use App\Auth\StudentScope;
use App\Core\CourseCover;
use App\Core\Mail\Mailer;
use App\Core\TestoBreve;
use App\Core\Url;
use App\Core\View;
use App\Models\BoardModel;
use App\Models\CourseModel;
use App\Models\TreParoleModel;
use App\Models\EnrollmentModel;
use App\Models\EnrollmentRequestModel;
use App\Models\TutorWelcomeModel;
use App\Models\UserModel;

/**
 * Gestione corsi e iscrizioni individuali.
 *
 * Creazione con `course.create`, modifica con `course.edit`,
 * eliminazione con `course.delete`.
 */
class CourseController extends AdminController
{
    /**
     * Quanto puo' essere lunga la descrizione del corso: 3.000 caratteri,
     * chiesto da Alessandro il 10/10. Dall'11/10 conta il testo visibile,
     * perche' la descrizione accetta qualche tag: il controllo e' qui, in
     * `rifiutaOltreIlLimite()`, e il contatore conta allo stesso modo.
     */
    public const MAX_DESCRIPTION_CHARS = 3000;

    /**
     * L'obiettivo del corso: facoltativo, al massimo 1.000 caratteri, con il
     * contatore (11/10, Alessandro). Dove si mostra agli studenti non e'
     * ancora deciso.
     *
     * Obiettivo e descrizione accettano qualche tag HTML (`TestoBreve`), e il
     * limite conta solo il testo visibile: oltre il limite il salvataggio si
     * rifiuta e il modulo torna con il testo scritto (`RIPRENDI`).
     */
    public const MAX_OBJECTIVE_CHARS = 1000;

    /** La chiave di sessione del modulo da riproporre dopo un rifiuto. */
    private const RIPRENDI = 'corso_da_riprendere';

    public function index(array $params = []): void
    {
        Auth::requirePermission('course.create', 'course.edit', 'course.delete');

        // Il tutor vede i corsi dei suoi gruppi (26/10): li' trova il suo
        // benvenuto e i suoi studenti; i contenuti li modifica l'admin.
        $tutor = !Auth::hasRole('admin');
        $suoi = CourseRights::courseIdsFor((int) Auth::id());

        View::render('admin/courses/index', [
            'pageTitle' => 'Gestione corsi',
            'courses' => $tutor
                ? array_values(array_filter(CourseModel::allForStaff(), static fn (array $c): bool => in_array((int) $c['id'], $suoi, true)))
                : CourseModel::allForStaff(),
            'tutor' => $tutor,
            'canCreate' => Auth::can('course.create'),
            'canDelete' => Auth::can('course.delete'),
            // Solo le richieste dei corsi che chi guarda puo' gestire (13/10):
            // un tutor non deve leggere richieste che non puo' ne' vedere ne'
            // decidere. Per l'admin `editableCourseIds()` e' null, cioe' tutte.
            'pendingRequests' => EnrollmentRequestModel::countPending(CourseRights::editableCourseIds()),
        ]);
    }

    public function createForm(array $params = []): void
    {
        Auth::requirePermission('course.create');

        View::render('admin/courses/form', [
            'pageTitle' => 'Nuovo corso',
            'course' => self::riprendi(0),
        ]);
    }

    public function store(array $params = []): void
    {
        Auth::requirePermission('course.create');

        $data = $this->dataFromPost();

        if ($data['title'] === '') {
            $this->fail('Il titolo del corso è obbligatorio.', '/admin/courses/create');
        }

        $this->rifiutaOltreIlLimite($data, 0, '/admin/courses/create');

        $courseId = CourseModel::create(
            $data['title'],
            CourseModel::uniqueSlug($data['slug'] !== '' ? $data['slug'] : $data['title']),
            $data['description'],
            $data['is_published'],
            (int) Auth::id(),
            $data['enrollment_mode'],
            $data['objective']
        );

        TreParoleModel::imposta($courseId, $data['three_words']);

        $this->success('Corso creato.', '/admin/courses/' . $courseId . '/edit');
    }

    public function editForm(array $params): void
    {
        Auth::requirePermission('course.edit');

        // L'admin modifica il corso; il tutor apre la pagina dei corsi dei
        // suoi gruppi per il suo benvenuto e i suoi studenti, in sola lettura
        // (26/10, conflitti 1 e 3).
        $tutor = !CourseRights::canEdit((int) $params['id']);

        if ($tutor && !in_array((int) $params['id'], CourseRights::courseIdsFor((int) Auth::id()), true)) {
            CourseRights::requireEdit((int) $params['id']);
        }

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $enrolled = EnrollmentModel::forCourse((int) $course['id']);
        $enrolledIds = array_map(static fn (array $row): int => (int) $row['user_id'], $enrolled);

        if ($tutor) {
            $suoi = StudentScope::studentiNelCorso((int) $course['id']) ?? [];
            $enrolled = array_values(array_filter($enrolled, static fn (array $r): bool => in_array((int) $r['user_id'], $suoi, true)));
        }

        View::render('admin/courses/edit', [
            'pageTitle' => $course['title'],
            'tutor' => $tutor,
            'course' => array_merge($course, self::riprendi((int) $course['id']) ?? []),
            'requests' => $tutor ? [] : EnrollmentRequestModel::pendingForCourse((int) $course['id']),
            'enrollments' => $enrolled,
            'availableStudents' => $tutor ? [] : array_values(array_filter(
                UserModel::byRoles(['studente']),
                static fn (array $u): bool => !in_array((int) $u['id'], $enrolledIds, true)
            )),
            'canDelete' => Auth::can('course.delete'),
            // L'admin vede il blocco di ogni tutor del corso, il tutor solo il
            // proprio; chi non ne puo' gestire nessuno, la sezione non la vede.
            'welcomes' => Auth::can('course.welcome') || Auth::can('course.welcome_own')
                ? array_values(array_filter(
                    TutorWelcomeModel::tutorsForCourse((int) $course['id']),
                    static fn (array $w): bool => TutorWelcomeController::puoGestire((int) $w['tutor_id'])
                ))
                : null,
            // Le categorie della bacheca (17/10): solo per chi le gestisce.
            'bacheca' => Auth::can('board.manage') ? BoardModel::categorie((int) $course['id']) : null,
        ]);
    }

    public function update(array $params): void
    {
        Auth::requirePermission('course.edit');
        CourseRights::requireEdit((int) $params['id']);

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $id = (int) $course['id'];
        $redirect = '/admin/courses/' . $id . '/edit';
        $data = $this->dataFromPost();

        if ($data['title'] === '') {
            $this->fail('Il titolo del corso è obbligatorio.', $redirect);
        }

        $this->rifiutaOltreIlLimite($data, $id, $redirect);

        CourseModel::update(
            $id,
            $data['title'],
            CourseModel::uniqueSlug($data['slug'] !== '' ? $data['slug'] : $data['title'], $id),
            $data['description'],
            $data['is_published'],
            $data['enrollment_mode'],
            $data['objective']
        );

        TreParoleModel::imposta($id, $data['three_words']);

        $this->success('Corso aggiornato.', $redirect);
    }

    public function destroy(array $params): void
    {
        Auth::requirePermission('course.delete');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        // Come ogni altra modifica di un corso: il permesso non basta, il
        // corso dev'essere fra quelli che si possono modificare (13/10). Oggi
        // `course.delete` ce l'ha solo l'admin, che li modifica tutti; se un
        // giorno lo avesse anche il tutor, cancellerebbe solo i suoi.
        CourseRights::requireEdit((int) $course['id']);

        // Moduli, lezioni, iscrizioni, tentativi e certificati vengono eliminati
        // a cascata: i file caricati in /storage restano invece su disco.
        CourseModel::delete((int) $course['id']);

        $this->success('Corso eliminato.', '/admin/courses');
    }

    // ---------------------------------------------------------------
    // Ordine
    // ---------------------------------------------------------------

    /**
     * Sposta un corso di un posto: e' la via che funziona senza JavaScript,
     * ed e' anche quella raggiungibile da tastiera.
     */
    public function move(array $params): void
    {
        Auth::requirePermission('course.edit');
        // L'ordine dei corsi e' uno solo, per tutta la piattaforma e per il
        // catalogo di ogni studente: lo decide l'admin, che e' anche chi crea i
        // corsi. Un tutor con due corsi su sette non riordina gli altri cinque.
        Auth::requireRole('admin');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        CourseModel::move((int) $course['id'], ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down');

        $this->redirectBackToCourses();
    }

    /**
     * Ordine completo, come arriva dal trascinamento.
     *
     * Risponde JSON perche' la chiamata parte dalla pagina senza ricaricarla:
     * dopo un trascinamento le schede sono gia' al loro posto, e ricaricare
     * farebbe sobbalzare la pagina sotto le mani di chi sta lavorando.
     */
    public function reorder(array $params = []): void
    {
        Auth::requirePermission('course.edit');
        // L'ordine dei corsi e' uno solo, per tutta la piattaforma e per il
        // catalogo di ogni studente: lo decide l'admin, che e' anche chi crea i
        // corsi. Un tutor con due corsi su sette non riordina gli altri cinque.
        Auth::requireRole('admin');

        $ids = $_POST['ids'] ?? '';
        $ids = is_string($ids) ? array_filter(explode(',', $ids), 'is_numeric') : [];

        if ($ids === []) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Nessun ordine ricevuto.']);
            return;
        }

        CourseModel::reorder(array_map('intval', $ids));

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    /**
     * Le frecce compaiono in due pagine: si torna da dove si e' partiti.
     */
    private function redirectBackToCourses(): void
    {
        $from = (string) ($_POST['from'] ?? '');
        $destination = $from === 'admin' ? '/admin/courses' : '/';

        header('Location: ' . $destination);
        exit;
    }

    // ---------------------------------------------------------------
    // Copertina
    // ---------------------------------------------------------------

    /**
     * Salva testo alternativo e, se ne e' stata scelta una, la nuova immagine.
     *
     * Il testo si puo' correggere senza ricaricare l'immagine: e' il caso piu'
     * frequente, visto che l'immagine si sceglie una volta sola.
     */
    public function updateCover(array $params): void
    {
        Auth::requirePermission('course.edit');
        CourseRights::requireEdit((int) $params['id']);

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $id = (int) $course['id'];
        $redirect = '/admin/courses/' . $id . '/edit';
        $current = $course['cover_image'] !== null && $course['cover_image'] !== '' ? (string) $course['cover_image'] : null;
        $alt = CourseCover::normalizeAlt($_POST['cover_alt'] ?? null);
        $uploaded = !empty($_FILES['cover']['name']);

        if (!$uploaded) {
            if ($current === null && $alt !== null) {
                $this->fail('Il testo alternativo descrive la copertina: carica prima un\'immagine.', $redirect);
            }

            CourseModel::updateCover($id, $current, $alt);

            $this->success('Testo alternativo aggiornato.', $redirect);
        }

        try {
            $path = CourseCover::store($_FILES['cover'], $id);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), $redirect);
        }

        // Prima si scrive in tabella, poi si cancella il file vecchio: al
        // contrario, un errore in mezzo lascerebbe la riga che punta al nulla.
        CourseModel::updateCover($id, $path, $alt);
        CourseCover::delete($current);

        $this->success('Copertina aggiornata.', $redirect);
    }

    public function deleteCover(array $params): void
    {
        Auth::requirePermission('course.edit');
        CourseRights::requireEdit((int) $params['id']);

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $id = (int) $course['id'];
        $current = $course['cover_image'] !== null && $course['cover_image'] !== '' ? (string) $course['cover_image'] : null;

        CourseModel::updateCover($id, null, null);
        CourseCover::delete($current);

        $this->success('Copertina rimossa.', '/admin/courses/' . $id . '/edit');
    }

    // ---------------------------------------------------------------
    // Iscrizioni individuali
    // ---------------------------------------------------------------

    public function enroll(array $params): void
    {
        Auth::requirePermission('course.edit');
        CourseRights::requireEdit((int) $params['id']);

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $redirect = '/admin/courses/' . (int) $course['id'] . '/edit';
        $userId = (int) ($_POST['user_id'] ?? 0);

        if (UserModel::find($userId) === null) {
            $this->fail('Utente non trovato.', $redirect);
        }

        // L'«Iscrizione confermata» la manda bin/avvisi (12/10).
        EnrollmentModel::enroll($userId, (int) $course['id'], true);

        $this->success('Studente iscritto.', $redirect);
    }

    public function unenroll(array $params): void
    {
        Auth::requirePermission('course.edit');
        CourseRights::requireEdit((int) $params['id']);

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        EnrollmentModel::remove((int) $params['userId'], (int) $course['id']);

        $this->success(
            'Iscrizione rimossa, insieme al progresso e all’eventuale certificato per questo corso.',
            '/admin/courses/' . (int) $course['id'] . '/edit'
        );
    }

    // ---------------------------------------------------------------
    // Richieste di iscrizione
    // ---------------------------------------------------------------

    public function decideRequest(array $params): void
    {
        Auth::requirePermission('course.edit');

        $request = EnrollmentRequestModel::findById((int) $params['requestId']);

        if ($request === null) {
            $this->notFound('Richiesta non trovata.');
            return;
        }

        $courseId = (int) $request['course_id'];
        CourseRights::requireEdit($courseId);
        $userId = (int) $request['user_id'];
        $redirect = '/admin/courses/' . $courseId . '/edit';
        $approve = ($_POST['decision'] ?? '') === 'approve';

        if ($request['status'] !== 'pending') {
            $this->fail('Questa richiesta è già stata valutata.', $redirect);
        }

        EnrollmentRequestModel::decide((int) $request['id'], $approve ? 'approved' : 'rejected', (int) Auth::id());

        if ($approve) {
            EnrollmentModel::enroll($userId, $courseId);

            Mailer::sendQuietly(Mailer::enrollmentConfirmed(
                (string) $request['email'],
                (string) $request['full_name'],
                (string) $request['course_title'],
                Url::to('/courses/' . $courseId)
            ));

            $this->success('Richiesta approvata: lo studente è iscritto ed è stato avvisato.', $redirect);
        }

        Mailer::sendQuietly(Mailer::enrollmentRejected(
            (string) $request['email'],
            (string) $request['full_name'],
            (string) $request['course_title']
        ));

        $this->success('Richiesta rifiutata: lo studente è stato avvisato.', $redirect);
    }

    // ---------------------------------------------------------------

    /**
     * @return array{title: string, slug: string, objective: string|null, description: string|null, is_published: bool, enrollment_mode: string}
     */
    private function dataFromPost(): array
    {
        // Obiettivo e descrizione si salvano come sono stati scritti, a capo
        // convertiti: niente taglio, perche' il limite conta il testo
        // visibile e un taglio a meta' di un tag romperebbe l'HTML. Il
        // controllo del limite e' in `rifiutaOltreIlLimite()`.
        $description = TestoBreve::normalizza((string) ($_POST['description'] ?? ''));
        $objective = TestoBreve::normalizza((string) ($_POST['objective'] ?? ''));
        $mode = (string) ($_POST['enrollment_mode'] ?? 'closed');

        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'objective' => $objective === '' ? null : $objective,
            'description' => $description === '' ? null : $description,
            'is_published' => isset($_POST['is_published']),
            'enrollment_mode' => in_array($mode, ['open', 'request', 'closed'], true) ? $mode : 'closed',
            'three_words' => isset($_POST['three_words']),
        ];
    }

    /**
     * Obiettivo o descrizione oltre il limite: il salvataggio si rifiuta,
     * con una frase che dice di quanto, e il modulo torna con quello che
     * era stato scritto (11/10, Alessandro: «rifiuta, tiene il testo»).
     * Un salvataggio rifiutato per un'altra ragione, come il titolo
     * mancante, il testo non lo tiene: e' il comportamento di prima.
     *
     * @param array{title: string, slug: string, objective: string|null, description: string|null, is_published: bool, enrollment_mode: string} $data
     */
    private function rifiutaOltreIlLimite(array $data, int $courseId, string $redirect): void
    {
        $problemi = array_filter([
            TestoBreve::problema("L'obiettivo del corso", (string) $data['objective'], self::MAX_OBJECTIVE_CHARS),
            TestoBreve::problema('La descrizione', (string) $data['description'], self::MAX_DESCRIPTION_CHARS),
        ]);

        if ($problemi === []) {
            return;
        }

        $_SESSION[self::RIPRENDI] = [
            'id' => $courseId,
            'campi' => [
                'title' => $data['title'],
                'slug' => $data['slug'],
                'objective' => $data['objective'],
                'description' => $data['description'],
                'is_published' => $data['is_published'] ? 1 : 0,
                'enrollment_mode' => $data['enrollment_mode'],
            ],
        ];

        $this->fail(implode(' ', $problemi), $redirect);
    }

    /**
     * Il modulo rifiutato da riproporre, una volta sola: per il corso
     * indicato (0 per «Nuovo corso»), o null.
     *
     * @return array<string, mixed>|null
     */
    private static function riprendi(int $courseId): ?array
    {
        $salvato = $_SESSION[self::RIPRENDI] ?? null;
        unset($_SESSION[self::RIPRENDI]);

        if (!is_array($salvato) || (int) ($salvato['id'] ?? -1) !== $courseId || !is_array($salvato['campi'] ?? null)) {
            return null;
        }

        return $salvato['campi'];
    }
}
