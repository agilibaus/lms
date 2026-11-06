<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\TutorWelcomeController;
use App\Auth\Auth;
use App\Auth\CourseRights;
use App\Auth\StudentScope;
use App\Core\CorsiAPagamento;
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
use App\Models\GroupModel;
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
        // Il supervisore (30/10) vede i corsi in bozza, come l'admin li vede
        // tutti; i pubblicati, per lui, non ci sono.
        $supervisore = Auth::hasRole('supervisore');
        $tutor = !Auth::hasRole('admin') && !$supervisore;
        $suoi = $supervisore ? CourseModel::idsInBozza() : CourseRights::courseIdsFor((int) Auth::id());

        View::render('admin/courses/index', [
            'pageTitle' => 'Gestione corsi',
            'courses' => $tutor || $supervisore
                ? array_values(array_filter(CourseModel::allForStaff(), static fn (array $c): bool => in_array((int) $c['id'], $suoi, true)))
                : CourseModel::allForStaff(),
            'tutor' => $tutor,
            'supervisore' => $supervisore,
            'canCreate' => Auth::can('course.create'),
            'canDelete' => Auth::can('course.delete'),
            // Solo le richieste dei corsi che chi guarda puo' gestire (13/10):
            // un tutor non deve leggere richieste che non puo' ne' vedere ne'
            // decidere. Per l'admin `editableCourseIds()` e' null, cioe' tutte.
            'pendingRequests' => $supervisore ? 0 : EnrollmentRequestModel::countPending(CourseRights::editableCourseIds()),
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

        $modalita = self::modalitaDaSalvare($data['enrollment_mode'], null);
        $data['enrollment_mode'] = $modalita;

        $this->rifiutaOltreIlLimite($data, 0, '/admin/courses/create');

        $pagamento = $this->pagamentoDaSalvare($data, $modalita, null, '/admin/courses/create');

        $courseId = CourseModel::create(
            $data['title'],
            CourseModel::uniqueSlug($data['slug'] !== '' ? $data['slug'] : $data['title']),
            $data['description'],
            // Il corso del supervisore nasce in bozza: pubblica l'admin (30/10).
            Auth::hasRole('supervisore') ? false : $data['is_published'],
            (int) Auth::id(),
            $modalita,
            $data['objective']
        );

        if ($pagamento !== null) {
            CourseModel::impostaPagamento($courseId, $pagamento['prezzo'], $pagamento['link'], $pagamento['plink']);
        }

        TreParoleModel::imposta($courseId, $data['three_words']);

        $this->success('Corso creato.', '/admin/courses/' . $courseId . '/edit');
    }

    public function editForm(array $params): void
    {
        Auth::requirePermission('course.edit', 'course.build');

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
            // Il supervisore costruisce i contenuti: niente iscritti, richieste
            // e report (30/10).
            'supervisore' => Auth::hasRole('supervisore'),
            'course' => array_merge($course, self::riprendi((int) $course['id']) ?? []),
            'requests' => $tutor ? [] : EnrollmentRequestModel::pendingForCourse((int) $course['id']),
            'enrollments' => $enrolled,
            'availableStudents' => $tutor ? [] : array_values(array_filter(
                UserModel::byRoles(['studente']),
                static fn (array $u): bool => !in_array((int) $u['id'], $enrolledIds, true)
            )),
            'canDelete' => Auth::can('course.delete'),
            // Dal database, non dal modulo riproposto: a una bozza non si
            // iscrive nessuno (06/11).
            'bozza' => (int) $course['is_published'] === 0,
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
        Auth::requirePermission('course.edit', 'course.build');
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

        $modalita = self::modalitaDaSalvare($data['enrollment_mode'], $course);
        $data['enrollment_mode'] = $modalita;

        $this->rifiutaOltreIlLimite($data, $id, $redirect);

        $pagamento = $this->pagamentoDaSalvare($data, $modalita, $course, $redirect);

        // Pubblicato o bozza (06/11): il supervisore non lo cambia. Un corso
        // con iscritti non torna in bozza, perche' una bozza non ha iscritti;
        // un corso che si pubblica iscrive i membri dei gruppi a cui e'
        // assegnato.
        $eraPubblicato = (int) $course['is_published'] === 1;
        $pubblicato = Auth::hasRole('supervisore') ? $eraPubblicato : $data['is_published'];

        if ($eraPubblicato && !$pubblicato) {
            $iscritti = EnrollmentModel::quantiNelCorso($id);

            if ($iscritti > 0) {
                $this->riproponi($data, $id, $redirect, $iscritti === 1
                    ? 'Il corso ha 1 iscritto: non può tornare in bozza.'
                    : 'Il corso ha ' . $iscritti . ' iscritti: non può tornare in bozza.');
            }
        }

        CourseModel::update(
            $id,
            $data['title'],
            CourseModel::uniqueSlug($data['slug'] !== '' ? $data['slug'] : $data['title'], $id),
            $data['description'],
            // Il supervisore non pubblica: il corso resta com'e' (in bozza,
            // perche' su un pubblicato non arriva).
            $pubblicato,
            $modalita,
            $data['objective']
        );

        if ($pagamento !== null) {
            CourseModel::impostaPagamento($id, $pagamento['prezzo'], $pagamento['link'], $pagamento['plink']);
        }

        TreParoleModel::imposta($id, $data['three_words']);

        if (!$eraPubblicato && $pubblicato) {
            // Le «Iscrizione confermata» le manda bin/avvisi, a scaglioni.
            $nuovi = EnrollmentModel::iscriviAllaPubblicazione($id);

            if ($nuovi > 0) {
                $this->success('Corso pubblicato: ' . ($nuovi === 1
                    ? 'iscritto 1 membro dei gruppi a cui è assegnato.'
                    : 'iscritti ' . $nuovi . ' membri dei gruppi a cui è assegnato.'), $redirect);
            }
        }

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
        Auth::requirePermission('course.edit', 'course.build');
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
        Auth::requirePermission('course.edit', 'course.build');
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

        // A una bozza non si iscrive nessuno (06/11): e' un corso non ancora
        // finito. I membri dei gruppi li iscrive la pubblicazione.
        if ((int) $course['is_published'] === 0) {
            $this->fail('Il corso è in bozza: gli studenti si iscrivono quando viene pubblicato.', $redirect);
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

        // Una richiesta fatta a corso pubblicato, che nel frattempo e' tornato
        // in bozza (06/11): si approva quando il corso e' di nuovo pubblicato.
        $corsoRichiesta = CourseModel::find($courseId);

        if ($approve && $corsoRichiesta !== null && (int) $corsoRichiesta['is_published'] === 0) {
            $this->fail('Il corso è in bozza: la richiesta si approva quando viene pubblicato.', $redirect);
        }

        EnrollmentRequestModel::decide((int) $request['id'], $approve ? 'approved' : 'rejected', (int) Auth::id());

        if ($approve) {
            EnrollmentModel::enroll($userId, $courseId);

            Mailer::subitoOInCoda(Mailer::enrollmentConfirmed(
                (string) $request['email'],
                (string) $request['full_name'],
                (string) $request['course_title'],
                Url::to('/courses/' . $courseId)
            ));

            $this->success('Richiesta approvata: lo studente è iscritto ed è stato avvisato.', $redirect);
        }

        Mailer::subitoOInCoda(Mailer::enrollmentRejected(
            (string) $request['email'],
            (string) $request['full_name'],
            (string) $request['course_title']
        ));

        $this->success('Richiesta rifiutata: lo studente è stato avvisato.', $redirect);
    }

    // ---------------------------------------------------------------

    /**
     * @return array{title: string, slug: string, objective: string|null, description: string|null, is_published: bool, enrollment_mode: string, three_words: bool, prezzo: string, link: string, plink: string}
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
            'enrollment_mode' => in_array($mode, ['open', 'request', 'closed', CorsiAPagamento::MODALITA], true) ? $mode : 'closed',
            'three_words' => isset($_POST['three_words']),
            // I tre campi del pagamento, come sono stati scritti: contano solo
            // con «A pagamento» (`pagamentoDaSalvare()`).
            'prezzo' => trim((string) ($_POST['price'] ?? '')),
            'link' => trim((string) ($_POST['payment_link_url'] ?? '')),
            'plink' => trim((string) ($_POST['payment_link_id'] ?? '')),
        ];
    }

    /**
     * La modalita' d'iscrizione da salvare (06/11, `CorsiAPagamento`).
     * «A pagamento» la sceglie solo l'admin, e solo a interruttore acceso; un
     * corso gia' a pagamento resta tale per chi non e' admin (il supervisore),
     * e anche per l'admin a interruttore spento, finche' non ne sceglie
     * un'altra. Una scelta non ammessa lascia la modalita' com'era.
     *
     * @param array<string, mixed>|null $corso null per un corso nuovo
     */
    private static function modalitaDaSalvare(string $richiesta, ?array $corso): string
    {
        $attuale = $corso === null ? null : (string) $corso['enrollment_mode'];
        $admin = Auth::hasRole('admin');

        if ($attuale === CorsiAPagamento::MODALITA && !$admin) {
            return $attuale;
        }

        if ($richiesta === CorsiAPagamento::MODALITA && !CorsiAPagamento::nelModulo($admin, $attuale)) {
            return $attuale ?? 'closed';
        }

        return $richiesta;
    }

    /**
     * Prezzo, link e identificativo da salvare con «A pagamento», controllati;
     * null quando non c'e' niente da scrivere (un'altra modalita', o un corso
     * a pagamento salvato da chi non e' admin, che i tre campi non li ha).
     * Se qualcosa non va, o il corso non puo' diventare a pagamento perche' e'
     * assegnato a un gruppo o ha richieste in attesa, il salvataggio si
     * rifiuta e il modulo torna con quello che era stato scritto.
     *
     * @param array{title: string, slug: string, objective: string|null, description: string|null, is_published: bool, enrollment_mode: string, three_words: bool, prezzo: string, link: string, plink: string} $data
     * @param array<string, mixed>|null $corso
     * @return array{prezzo: int, link: string, plink: string}|null
     */
    private function pagamentoDaSalvare(array $data, string $modalita, ?array $corso, string $redirect): ?array
    {
        if ($modalita !== CorsiAPagamento::MODALITA || !Auth::hasRole('admin')) {
            return null;
        }

        $id = $corso === null ? 0 : (int) $corso['id'];
        $prezzo = CorsiAPagamento::prezzoDaTesto($data['prezzo']);
        $problema = null;

        if ($id > 0) {
            $problema = CorsiAPagamento::impedimento(
                GroupModel::nomiPerCorso($id),
                count(EnrollmentRequestModel::pendingForCourse($id))
            );
        }

        if ($problema === null && ($data['prezzo'] === '' || $data['link'] === '' || $data['plink'] === '')) {
            $problema = 'Per un corso a pagamento servono il prezzo, il link di pagamento e il suo identificativo.';
        } elseif ($problema === null && $prezzo === null) {
            $problema = 'Il prezzo va scritto in euro, per esempio 49,00.';
        } elseif ($problema === null && !CorsiAPagamento::linkValido($data['link'])) {
            $problema = 'Il link di pagamento deve cominciare con https://, come quello copiato da Stripe.';
        } elseif ($problema === null && !CorsiAPagamento::identificativoValido($data['plink'])) {
            $problema = 'L’identificativo del link comincia con «plink_»: si legge nella pagina del link su Stripe.';
        }

        if ($problema === null) {
            $altro = CourseModel::altroConIdentificativo($data['plink'], $id);

            if ($altro !== null) {
                $problema = 'L’identificativo del link è già usato dal corso «' . $altro . '».';
            }
        }

        if ($problema !== null || $prezzo === null) {
            $this->riproponi($data, $id, $redirect, (string) $problema);
        }

        return ['prezzo' => $prezzo, 'link' => $data['link'], 'plink' => $data['plink']];
    }

    /**
     * Obiettivo o descrizione oltre il limite: il salvataggio si rifiuta,
     * con una frase che dice di quanto, e il modulo torna con quello che
     * era stato scritto (11/10, Alessandro: «rifiuta, tiene il testo»).
     * Un salvataggio rifiutato per un'altra ragione, come il titolo
     * mancante, il testo non lo tiene: e' il comportamento di prima.
     *
     * @param array{title: string, slug: string, objective: string|null, description: string|null, is_published: bool, enrollment_mode: string, three_words: bool, prezzo: string, link: string, plink: string} $data
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

        $this->riproponi($data, $courseId, $redirect, implode(' ', $problemi));
    }

    /**
     * Rifiuta il salvataggio e ripropone il modulo con quello che era stato
     * scritto, una volta sola (`riprendi()`).
     *
     * @param array{title: string, slug: string, objective: string|null, description: string|null, is_published: bool, enrollment_mode: string, three_words: bool, prezzo: string, link: string, plink: string} $data
     */
    private function riproponi(array $data, int $courseId, string $redirect, string $messaggio): never
    {
        $_SESSION[self::RIPRENDI] = [
            'id' => $courseId,
            'campi' => [
                'title' => $data['title'],
                'slug' => $data['slug'],
                'objective' => $data['objective'],
                'description' => $data['description'],
                'is_published' => $data['is_published'] ? 1 : 0,
                'enrollment_mode' => $data['enrollment_mode'],
                // I campi del pagamento tornano come testo, anche se non si
                // leggono: il prezzo sbagliato si corregge, non si riscrive.
                'prezzo_testo' => $data['prezzo'],
                'payment_link_url' => $data['link'],
                'payment_link_id' => $data['plink'],
            ],
        ];

        $this->fail($messaggio, $redirect);
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
