<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\CourseRights;
use App\Core\FileStream;
use App\Core\H5PArchivio;
use App\Core\LezioneH5P;
use App\Core\CertificateService;
use App\Core\CourseAccess;
use App\Core\HtmlSanitizer;
use App\Core\LiveScope;
use App\Core\OrphanFiles;
use App\Core\Upload;
use App\Core\VideoEmbed;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\LessonH5PModel;
use App\Models\LessonMaterialModel;
use App\Models\LessonModel;
use App\Models\LessonProgressModel;
use App\Models\LiveSessionModel;
use App\Models\ModuleModel;
use App\Models\VideoProgressModel;

class LessonController
{
    private const MATERIAL_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'mp3', 'wav', 'm4a', 'zip', 'txt'];
    private const MATERIAL_MAX_BYTES = 50 * 1024 * 1024; // 50 MB

    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    /**
     * Il tipo con cui si consegna un video caricato, dall'estensione e mai
     * dal contenuto (13/10). Dedotto dal contenuto, un file chiamato `.mp4`
     * che dentro e' una pagina HTML usciva come `text/html`, con l'indirizzo
     * della piattaforma: il codice della pagina girava con la sessione di
     * chi lo apriva.
     */
    private const VIDEO_MIME = [
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm', 'mov' => 'video/quicktime',
    ];
    public const VIDEO_MAX_BYTES = 500 * 1024 * 1024; // 500 MB — per file più grandi preferire Bunny/Cloudflare Stream

    /** Immagini inserite nel testo dall'editor. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const IMAGE_MAX_BYTES = 8 * 1024 * 1024; // 8 MB
    private const IMAGE_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    ];

    public function createForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['moduleId']);

        $module = ModuleModel::find((int) $params['moduleId']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        View::render('lessons/form', [
            'pageTitle' => 'Nuova lezione',
            'module' => $module,
            'lesson' => null,
            'materials' => [],
            'h5p' => [],
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditModule((int) $params['moduleId']);

        $module = ModuleModel::find((int) $params['moduleId']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            $_SESSION['flash_error'] = 'Il titolo della lezione è obbligatorio.';
            header('Location: /modules/' . $module['id'] . '/lessons/create');
            exit;
        }

        $provider = $this->videoProviderFromPost();

        $lessonId = LessonModel::create(
            (int) $module['id'],
            $title,
            $this->contentHtmlFromPost(),
            $provider,
            $provider === 'self_hosted' ? null : $this->externalVideoRefFromPost($provider),
            $this->durationFromPost()
        );

        try {
            $this->handleVideoUpload($lessonId);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        $_SESSION['flash_success'] = 'Lezione creata.' . $this->handleH5PUploads($lessonId);
        $this->warnMissingVideoRef($provider);

        header('Location: /lessons/' . $lessonId . '/edit');
        exit;
    }

    public function show(array $params): void
    {
        Auth::requireLogin();

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        if ($module === null) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }
        $course = CourseModel::find((int) $module['course_id']);

        if ($course === null) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        if (!$this->canAccessCourse((int) $course['id'])) {
            http_response_code(403);
            echo 'Non sei iscritto a questo corso.';
            return;
        }

        if ($this->isModuleLocked((int) $module['id'])) {
            http_response_code(403);
            echo 'Questo modulo è bloccato: supera prima il questionario del modulo precedente.';
            return;
        }

        $h5p = LessonH5PModel::forLesson((int) $lesson['id']);

        // I file dei contenuti si servono a chi ha aperto la pagina senza
        // rifare tutti i controlli a ogni richiesta (H5PController::fileLezione).
        foreach ($h5p as $contenuto) {
            $_SESSION[H5PController::SESSIONE_LEZIONI][(int) $contenuto['id']] = true;
        }

        View::render('lessons/show', [
            'pageTitle' => $lesson['title'],
            'lesson' => $lesson,
            'module' => $module,
            'course' => $course,
            'materials' => LessonMaterialModel::forLesson((int) $lesson['id']),
            'h5p' => $h5p,
            'completed' => LessonProgressModel::isCompleted((int) Auth::id(), (int) $lesson['id']),
            // Solo gli incontri che chi guarda puo' aprire (17/10, LiveScope):
            // un tutor che legge la lezione del corso di un collega non vede
            // un «Entra nella riunione» che lo porterebbe a un 403.
            'liveSessions' => array_values(array_filter(
                LiveSessionModel::upcomingForModule((int) $module['id']),
                static fn (array $s): bool => LiveScope::visibile($s)
            )),
            // Il secondo da cui riprendere, o null se non c'e' niente da
            // riprendere. Lo calcola il server: cosi' il pulsante e' gia'
            // nell'HTML con il suo minuto scritto dentro, e non compare a
            // sorpresa un istante dopo che la pagina si e' aperta.
            'riprendiDa' => $this->resumePoint((int) Auth::id(), $lesson),
        ]);
    }

    /**
     * Da dove riprendere il video di questa lezione, o null.
     *
     * Null in tre casi: non c'e' una posizione salvata; e' troppo vicina
     * all'inizio (tornare al secondo 12 non e' riprendere); e' troppo vicina
     * alla fine (chi e' arrivato in fondo vuole rivederlo da capo). Le due
     * soglie stanno sul controller della fruizione, con il resto delle
     * costanti di quel lavoro.
     */
    private function resumePoint(int $userId, array $lesson): ?int
    {
        if ($lesson['video_provider'] === 'none') {
            return null;
        }

        $salvata = VideoProgressModel::position($userId, (int) $lesson['id']);

        if ($salvata === null) {
            return null;
        }

        $posizione = $salvata['position_seconds'];

        if ($posizione < VideoProgressController::RESUME_MIN_SECONDS) {
            return null;
        }

        $durata = $salvata['duration_seconds'] ?? (int) ($lesson['duration_seconds'] ?? 0);

        if ($durata > 0 && $posizione > $durata - VideoProgressController::RESUME_TAIL_SECONDS) {
            return null;
        }

        return $posizione;
    }

    public function editForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        View::render('lessons/form', [
            'pageTitle' => 'Modifica lezione',
            'module' => $module,
            'lesson' => $lesson,
            'materials' => LessonMaterialModel::forLesson((int) $lesson['id']),
            'h5p' => LessonH5PModel::forLesson((int) $lesson['id']),
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $title = trim($_POST['title'] ?? '');
        $provider = $this->videoProviderFromPost();

        if ($title === '') {
            // Prima non succedeva niente e non lo diceva nessuno: la pagina
            // tornava identica e sembrava salvata.
            $_SESSION['flash_error'] = 'Il titolo della lezione è obbligatorio: non ho salvato niente.';
            header('Location: /lessons/' . $lesson['id'] . '/edit');
            exit;
        }

        LessonModel::update(
            (int) $lesson['id'],
            $title,
            $this->contentHtmlFromPost(),
            $provider,
            $this->resolveVideoRefForUpdate($lesson, $provider),
            $this->durationFromPost()
        );

        $_SESSION['flash_success'] = 'Lezione salvata.' . $this->handleH5PUploads((int) $lesson['id']);
        $this->warnMissingVideoRef($provider);

        try {
            $this->handleVideoUpload((int) $lesson['id']);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        // Un file scelto mentre la tendina dice altro veniva ignorato senza
        // un fiato: chi carica resta a guardare una lezione senza video,
        // convinto di aver sbagliato qualcos'altro.
        if (!empty($_FILES['video_file']['name']) && $this->videoProviderFromPost() !== 'self_hosted') {
            $_SESSION['flash_error'] = 'Il file non e\' stato caricato: per usarlo scegli '
                . '"Video caricato sul server" nella tendina del provider.';
        }

        // Nessuna cancellazione automatica: caricando un sostituto o
        // cambiando provider il file di prima resta sul server, e si toglie
        // solo con il comando apposito. In cambio si avvisa, altrimenti un
        // video sparito dalla lezione sembrerebbe perso.
        $this->warnIfVideoDetached($lesson, (int) $lesson['id']);

        header('Location: /lessons/' . $lesson['id'] . '/edit');
        exit;
    }

    /**
     * Sposta la lezione di un posto su o giu' dentro il suo modulo.
     */
    public function move(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        LessonModel::move((int) $lesson['id'], ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down');

        $module = ModuleModel::find((int) $lesson['module_id']);

        header('Location: /courses/' . ($module['course_id'] ?? '') . '#modulo-' . (int) $lesson['module_id']);
        exit;
    }

    public function destroy(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        // I file caricati (video, materiali, immagini) restano sul server: qui
        // si eliminano solo le righe, che seguono via FK ON DELETE CASCADE.
        // Chi elimina viene avvisato che quei file rimangono, perche' da quel
        // momento nessuna pagina li nomina piu'.
        $files = OrphanFiles::forLesson((int) $lesson['id'], $lesson, true);
        // I contenuti H5P invece si tolgono (23/10): sono cartelle estratte da
        // Pistacchio, non file caricati, e il .h5p da cui vengono e' di chi
        // l'ha creato con Lumi.
        $cartelleH5P = LessonH5PModel::dirsForLesson((int) $lesson['id']);

        LessonModel::delete((int) $lesson['id']);

        foreach ($cartelleH5P as $cartella) {
            H5PArchivio::elimina($cartella);
        }

        $_SESSION['flash_success'] = match (true) {
            $files['count'] === 0 => 'Lezione eliminata.',
            $files['count'] === 1 => 'Lezione eliminata. Il file caricato ('
                . OrphanFiles::humanSize($files['bytes']) . ') resta sul server in '
                . implode(', ', $files['folders']) . ' e non e\' piu\' collegato a nessuna lezione.',
            default => 'Lezione eliminata. I ' . $files['count'] . ' file caricati ('
                . OrphanFiles::humanSize($files['bytes']) . ') restano sul server in '
                . implode(', ', $files['folders']) . ' e non sono piu\' collegati a nessuna lezione.',
        };

        header('Location: /courses/' . ($module['course_id'] ?? ''));
        exit;
    }

    public function uploadMaterial(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $lessonId = (int) $params['id'];
        $lesson = LessonModel::find($lessonId);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        try {
            $this->handleMaterialUploads($lessonId);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        header('Location: /lessons/' . $lessonId . '/edit');
        exit;
    }

    /**
     * Sposta un materiale su o giu' nell'elenco.
     */
    public function moveMaterial(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $material = LessonMaterialModel::find((int) $params['materialId']);

        if (!$material) {
            http_response_code(404);
            echo 'Materiale non trovato.';
            return;
        }

        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
        LessonMaterialModel::move((int) $material['id'], $direction);

        header('Location: /lessons/' . $material['lesson_id'] . '/edit#materiali');
        exit;
    }

    /**
     * Carica un'immagine inserita nel testo dall'editor.
     *
     * Risponde in JSON perche' e' TinyMCE a chiamarla, non un form: in caso di
     * errore il messaggio finisce nella finestrella dell'editor.
     */
    public function uploadImage(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        header('Content-Type: application/json; charset=utf-8');

        $lessonId = (int) $params['id'];

        if (!LessonModel::find($lessonId)) {
            http_response_code(404);
            echo json_encode(['error' => 'Lezione non trovata.']);
            return;
        }

        $file = $_FILES['file'] ?? null;

        if (!is_array($file)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nessun file ricevuto.']);
            return;
        }

        try {
            // Estensione giusta ma contenuto qualsiasi: getimagesize apre davvero
            // il file e fallisce se non e' un'immagine.
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
                && is_uploaded_file($file['tmp_name'])
                && getimagesize($file['tmp_name']) === false
            ) {
                throw new \RuntimeException('Il file non è un\'immagine valida.');
            }

            $stored = Upload::store(
                $file,
                'lesson-images/' . $lessonId,
                self::IMAGE_EXTENSIONS,
                self::IMAGE_MAX_BYTES
            );
        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        echo json_encode([
            'location' => '/lessons/' . $lessonId . '/images/' . basename($stored['stored_path']),
        ]);
    }

    /**
     * Serve un'immagine del testo della lezione.
     *
     * Le immagini stanno in /storage, fuori dal document root: passano da qui
     * proprio perche' l'iscrizione al corso venga verificata come per i video.
     */
    public function showImage(array $params): void
    {
        Auth::requireLogin();

        $lessonId = (int) $params['id'];
        $lesson = LessonModel::find($lessonId);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        if ($module === null) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        if (!$this->canAccessCourse((int) $module['course_id'])) {
            http_response_code(403);
            echo 'Non sei iscritto a questo corso.';
            return;
        }

        // Il nome e' quello generato da Upload::store: 32 cifre esadecimali piu'
        // l'estensione. Verificarlo chiude la porta a qualunque "../".
        $name = (string) ($params['file'] ?? '');

        if (preg_match('/^[0-9a-f]{32}\.([a-z0-9]+)$/', $name, $matches) !== 1
            || !isset(self::IMAGE_MIME[$matches[1]])
        ) {
            http_response_code(404);
            echo 'Immagine non trovata.';
            return;
        }

        $absolute = Upload::absolutePath('lesson-images/' . $lessonId . '/' . $name);

        if (!is_file($absolute)) {
            http_response_code(404);
            echo 'Immagine non trovata.';
            return;
        }

        header('Content-Type: ' . self::IMAGE_MIME[$matches[1]]);
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        // Privata: l'immagine e' visibile solo agli iscritti, quindi non deve
        // finire nella cache di un proxy condiviso.
        header('Cache-Control: private, max-age=86400');
        readfile($absolute);
        exit;
    }

    public function deleteMaterial(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $material = LessonMaterialModel::find((int) $params['materialId']);

        if (!$material) {
            http_response_code(404);
            echo 'Materiale non trovato.';
            return;
        }

        @unlink(Upload::absolutePath($material['file_path']));
        LessonMaterialModel::delete((int) $material['id']);

        header('Location: /lessons/' . $material['lesson_id'] . '/edit');
        exit;
    }

    /**
     * POST /lessons/{id}/h5p/{contenuto}/delete — toglie un contenuto H5P
     * dalla lezione e la sua cartella dal disco (23/10). Un segnaposto
     * rimasto nel testo non mostra piu' niente, e l'editor lo dice.
     */
    public function deleteH5P(array $params): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson((int) $params['id']);

        $contenuto = LessonH5PModel::find((int) $params['contenuto']);

        if ($contenuto === null || (int) $contenuto['lesson_id'] !== (int) $params['id']) {
            http_response_code(404);
            echo 'Contenuto non trovato.';
            return;
        }

        LessonH5PModel::delete((int) $contenuto['id']);
        H5PArchivio::elimina((string) $contenuto['h5p_dir']);

        $lesson = LessonModel::find((int) $contenuto['lesson_id']);
        $nelTesto = in_array((int) $contenuto['id'], LezioneH5P::segnaposti($lesson['content_html'] ?? null), true);

        $_SESSION['flash_success'] = 'Contenuto «' . $contenuto['h5p_file'] . '» eliminato.'
            . ($nelTesto ? ' Il suo segnaposto è ancora nel testo e non mostra più niente: puoi cancellarlo dall\'editor.' : '');

        header('Location: /lessons/' . (int) $contenuto['lesson_id'] . '/edit#contenuti-h5p');
        exit;
    }

    public function downloadMaterial(array $params): void
    {
        Auth::requireLogin();

        $material = LessonMaterialModel::find((int) $params['id']);

        if (!$material) {
            http_response_code(404);
            echo 'Materiale non trovato.';
            return;
        }

        $lesson = LessonModel::find((int) $material['lesson_id']);

        if ($lesson === null) {
            http_response_code(404);
            echo 'Materiale non trovato.';
            return;
        }
        $module = ModuleModel::find((int) $lesson['module_id']);

        if ($module === null) {
            http_response_code(404);
            echo 'Materiale non trovato.';
            return;
        }

        if (!$this->canAccessCourse((int) $module['course_id'])) {
            http_response_code(403);
            echo 'Non sei iscritto a questo corso.';
            return;
        }

        // Il materiale e' servito da PHP, quindi e' un ingresso al pari della
        // pagina della lezione: senza questo controllo un modulo chiuso si
        // scavalcherebbe scaricando direttamente i suoi allegati.
        if ($this->isModuleLocked((int) $module['id'])) {
            http_response_code(403);
            echo 'Questo materiale appartiene a un modulo non ancora disponibile.';
            return;
        }

        $absolute = Upload::absolutePath($material['file_path']);

        if (!is_file($absolute)) {
            http_response_code(404);
            echo 'File non trovato sul server.';
            return;
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($material['file_name']) . '"');
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
        exit;
    }

    public function streamVideo(array $params): void
    {
        Auth::requireLogin();

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson || $lesson['video_provider'] !== 'self_hosted' || empty($lesson['video_ref'])) {
            http_response_code(404);
            echo 'Video non disponibile.';
            return;
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        if ($module === null) {
            http_response_code(404);
            echo 'Video non disponibile.';
            return;
        }

        if (!$this->canAccessCourse((int) $module['course_id'])) {
            http_response_code(403);
            echo 'Non sei iscritto a questo corso.';
            return;
        }

        // Come gli allegati: il video e' un ingresso alla lezione al pari della
        // sua pagina, e un modulo chiuso non si scavalca chiedendolo
        // direttamente (13/10).
        if ($this->isModuleLocked((int) $module['id'])) {
            http_response_code(403);
            echo 'Questo video appartiene a un modulo non ancora disponibile.';
            return;
        }

        $absolute = Upload::absolutePath($lesson['video_ref']);
        $mime = self::VIDEO_MIME[strtolower(pathinfo((string) $lesson['video_ref'], PATHINFO_EXTENSION))] ?? null;

        if (!is_file($absolute) || $mime === null) {
            http_response_code(404);
            echo 'File video non trovato sul server.';
            return;
        }

        FileStream::send($absolute, $mime);
    }

    public function complete(array $params): void
    {
        Auth::requireLogin();

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        if ($module === null) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }
        $userId = (int) Auth::id();
        $courseId = (int) $module['course_id'];

        if (!$this->canAccessCourse($courseId) || $this->isModuleLocked((int) $module['id'])) {
            http_response_code(403);
            echo 'Questa lezione non è accessibile.';
            return;
        }

        LessonProgressModel::markCompleted($userId, (int) $lesson['id']);

        $total = LessonModel::countForCourse($courseId);
        $done = LessonProgressModel::countCompletedForCourse($userId, $courseId);
        $pct = $total > 0 ? round(($done / $total) * 100, 2) : 0.0;

        EnrollmentModel::updateProgress($userId, $courseId, $pct);

        // Completare l'ultima lezione puo' rendere lo studente idoneo al certificato.
        try {
            CertificateService::issueIfEligible($userId, $courseId);
        } catch (\RuntimeException $e) {
            error_log('[Certificati] ' . $e->getMessage());
            $_SESSION['flash_error'] = $e->getMessage();
        }

        header('Location: /lessons/' . $lesson['id']);
        exit;
    }

    // ---------------------------------------------------------------
    // Helper privati
    // ---------------------------------------------------------------

    /**
     * Staff (admin/tutor/assistente) ha sempre accesso; lo studente solo se iscritto.
     */
    private function canAccessCourse(int $courseId): bool
    {
        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            return true;
        }

        return EnrollmentModel::find((int) Auth::id(), $courseId) !== null;
    }

    /**
     * Moduli bloccati dallo sblocco progressivo (quiz obbligatorio del modulo
     * precedente non ancora superato). Lo staff non e' mai bloccato.
     */
    private function isModuleLocked(int $moduleId): bool
    {
        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            return false;
        }

        return CourseAccess::isModuleLocked((int) Auth::id(), $moduleId);
    }

    /**
     * Il contenuto arriva come HTML dall'editor e viene stampato senza escape
     * nella pagina della lezione: qui passa dal sanificatore, una volta sola,
     * in scrittura.
     */
    private function contentHtmlFromPost(): ?string
    {
        return HtmlSanitizer::clean($_POST['content_html'] ?? null);
    }

    private function videoProviderFromPost(): string
    {
        $provider = $_POST['video_provider'] ?? 'none';

        return in_array($provider, ['bunny', 'cloudflare', 'self_hosted', 'none'], true) ? $provider : 'none';
    }

    /**
     * Ogni provider esterno ha il suo campo: `video_ref_bunny`,
     * `video_ref_cloudflare`. Un nome solo per tutti e due non funzionava —
     * i campi nascosti vengono inviati lo stesso, e l'ultimo cancellava il
     * primo (Sezione 5 del promemoria).
     */
    private function externalVideoRefFromPost(string $provider): ?string
    {
        $ref = trim($_POST['video_ref_' . $provider] ?? '');

        return $ref === '' ? null : $ref;
    }

    /**
     * Provider esterno scelto ma casella dell'ID vuota: la lezione si salva
     * lo stesso, ma nella pagina non comparirebbe nessun video e nessuno
     * spiegherebbe perche'.
     */
    private function warnMissingVideoRef(string $provider): void
    {
        if (!VideoEmbed::isIframeProvider($provider)) {
            return;
        }

        if ($this->externalVideoRefFromPost($provider) !== null) {
            return;
        }

        $_SESSION['flash_error'] = 'Manca l\'ID del video: la lezione è salvata, '
            . 'ma finché la casella resta vuota il video non compare.';
    }

    private function resolveVideoRefForUpdate(array $lesson, string $newProvider): ?string
    {
        return match ($newProvider) {
            'bunny', 'cloudflare' => $this->externalVideoRefFromPost($newProvider),
            // Mantiene il file già caricato finché non ne arriva uno nuovo
            // (handleVideoUpload lo sovrascrive dopo, se presente).
            'self_hosted' => $lesson['video_provider'] === 'self_hosted' ? $lesson['video_ref'] : null,
            default => null,
        };
    }

    private function durationFromPost(): int
    {
        return max(0, (int) ($_POST['duration_seconds'] ?? 0));
    }

    /**
     * Toglie il video caricato sul server: file dal disco, riferimento dalla
     * lezione. Serve un comando esplicito — cambiare la tendina su "Nessuno"
     * lo faceva gia', ma nessuno poteva indovinarlo.
     */
    /**
     * Toglie il video dalla lezione. Con `/video/detach` il file resta sul
     * server, con `/video/delete` viene anche cancellato: due comandi distinti
     * perche' la seconda cosa non si disfa.
     */
    public function detachVideo(array $params): void
    {
        $this->removeVideo((int) $params['id'], false);
    }

    public function deleteVideo(array $params): void
    {
        $this->removeVideo((int) $params['id'], true);
    }

    private function removeVideo(int $lessonId, bool $deleteFile): void
    {
        Auth::requireRole('admin', 'tutor');
        CourseRights::requireEditLesson($lessonId);

        $lesson = LessonModel::find($lessonId);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $destination = '/lessons/' . $lessonId . '/edit';

        if ($lesson['video_provider'] !== 'self_hosted' || empty($lesson['video_ref'])) {
            $_SESSION['flash_error'] = 'Questa lezione non ha un video caricato sul server.';
            header('Location: ' . $destination);
            exit;
        }

        $reference = (string) $lesson['video_ref'];

        LessonModel::update(
            $lessonId,
            (string) $lesson['title'],
            $lesson['content_html'],
            'none',
            null,
            (int) $lesson['duration_seconds']
        );

        if (!$deleteFile) {
            $_SESSION['flash_success'] = 'Video tolto dalla lezione. Il file resta sul server, '
                . 'in ' . dirname($reference) . ', ma nessuna lezione lo usa piu\'.';
            header('Location: ' . $destination);
            exit;
        }

        $absolute = Upload::absolutePath($reference);
        $removed = is_file($absolute) ? @unlink($absolute) : true;

        $_SESSION['flash_success'] = $removed
            ? 'Video rimosso dalla lezione ed eliminato dal server.'
            : 'Video rimosso dalla lezione, ma il file non si e\' potuto eliminare dal disco: '
                . 'controlla i permessi di ' . dirname($reference) . '.';

        header('Location: ' . $destination);
        exit;
    }

    /**
     * Avvisa quando un video caricato smette di essere collegato alla lezione:
     * cambiando provider, o caricandone un altro al suo posto. Il file resta
     * sul server, ma nessuna pagina lo nomina piu', quindi conviene dirlo.
     *
     * Si rilegge la riga invece di fidarsi di quello che credevamo di aver
     * scritto: fra la modifica e qui passano il salvataggio e l'eventuale
     * caricamento del sostituto.
     */
    private function warnIfVideoDetached(array $previous, int $lessonId): void
    {
        if ($previous['video_provider'] !== 'self_hosted' || empty($previous['video_ref'])) {
            return;
        }

        $current = LessonModel::find($lessonId);

        if ($current !== null && ($current['video_ref'] ?? null) === $previous['video_ref']) {
            return;
        }

        // Un errore gia' segnalato conta di piu' di questo avviso.
        if (!empty($_SESSION['flash_error'])) {
            return;
        }

        $_SESSION['flash_info'] = 'Il video ' . basename((string) $previous['video_ref'])
            . ' non e\' piu\' collegato a questa lezione. Il file resta sul server: '
            . 'per toglierlo davvero usa "Rimuovi dalla lezione e dal server".';
    }

    private function handleVideoUpload(int $lessonId): void
    {
        if ($this->videoProviderFromPost() !== 'self_hosted') {
            return;
        }

        if (empty($_FILES['video_file']['name'])) {
            return;
        }

        $stored = Upload::store(
            $_FILES['video_file'],
            'videos/' . $lessonId,
            self::VIDEO_EXTENSIONS,
            self::VIDEO_MAX_BYTES
        );

        LessonModel::updateVideoRef($lessonId, $stored['stored_path']);
    }

    /**
     * I contenuti H5P scelti nel modulo della lezione (23/10): quelli nuovi
     * (`h5p_nuovi[]`) e quelli sostituiti (`h5p_sostituisci[id]`, stesso id:
     * il segnaposto nel testo resta valido). Si fa dopo aver salvato il
     * testo: un file che non va non fa perdere niente, e l'errore dice quale.
     *
     * @return string la frase da aggiungere al «Lezione salvata.», vuota se
     *                non e' arrivato nessun contenuto
     */
    private function handleH5PUploads(int $lessonId): string
    {
        $fatti = [];
        $errori = [];

        foreach (self::fileArrivati('h5p_nuovi') as $f) {
            try {
                $contenuto = H5PArchivio::apriPerLezione(self::fileH5PValido($f), $lessonId);
                LessonH5PModel::create($lessonId, $contenuto, self::nomeFile($f));
                $fatti[] = 'aggiunto «' . self::nomeFile($f) . '»';
            } catch (\RuntimeException $e) {
                $errori[] = '«' . self::nomeFile($f) . '» non è stato aggiunto: ' . $e->getMessage();
            }
        }

        foreach (self::fileArrivati('h5p_sostituisci') as $id => $f) {
            $vecchio = LessonH5PModel::find((int) $id);

            if ($vecchio === null || (int) $vecchio['lesson_id'] !== $lessonId) {
                continue;
            }

            try {
                $contenuto = H5PArchivio::apriPerLezione(self::fileH5PValido($f), $lessonId);
                LessonH5PModel::replace((int) $vecchio['id'], $contenuto, self::nomeFile($f));
                H5PArchivio::elimina((string) $vecchio['h5p_dir']);
                $fatti[] = '«' . $vecchio['h5p_file'] . '» sostituito con «' . self::nomeFile($f) . '»';
            } catch (\RuntimeException $e) {
                $errori[] = '«' . $vecchio['h5p_file'] . '» non è stato sostituito: ' . $e->getMessage();
            }
        }

        if ($errori !== []) {
            $_SESSION['flash_error'] = trim(($_SESSION['flash_error'] ?? '') . ' ' . implode(' ', $errori));
        }

        return $fatti === [] ? '' : ' Contenuti H5P: ' . implode(', ', $fatti) . '.';
    }

    /**
     * I file arrivati in un campo, uno per chiave, senza quelli lasciati
     * vuoti: `campo[]` (chiavi 0, 1…) o `campo[id]`.
     *
     * @return array<int|string, array{name: string, tmp_name: string, error: int}>
     */
    private static function fileArrivati(string $campo): array
    {
        $f = $_FILES[$campo] ?? null;

        if (!is_array($f) || !is_array($f['name'] ?? null)) {
            return [];
        }

        $arrivati = [];

        foreach ($f['name'] as $chiave => $nome) {
            $errore = (int) ($f['error'][$chiave] ?? UPLOAD_ERR_NO_FILE);

            if ($errore === UPLOAD_ERR_NO_FILE || !is_string($nome)) {
                continue;
            }

            $arrivati[$chiave] = ['name' => $nome, 'tmp_name' => (string) ($f['tmp_name'][$chiave] ?? ''), 'error' => $errore];
        }

        return $arrivati;
    }

    /**
     * Il percorso del file caricato, se e' un .h5p arrivato per intero.
     *
     * @param array{name: string, tmp_name: string, error: int} $f
     * @throws \RuntimeException
     */
    private static function fileH5PValido(array $f): string
    {
        $messaggio = match (true) {
            $f['error'] === UPLOAD_ERR_INI_SIZE, $f['error'] === UPLOAD_ERR_FORM_SIZE => 'supera il limite del server (' . Upload::humanIniLimit() . ').',
            $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) => 'non è arrivato per intero, riprova.',
            strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'h5p' => 'deve essere un pacchetto .h5p (in Lumi: Esporta, formato H5P).',
            default => null,
        };

        if ($messaggio !== null) {
            throw new \RuntimeException($messaggio);
        }

        return $f['tmp_name'];
    }

    /** @param array{name: string} $f */
    private static function nomeFile(array $f): string
    {
        return mb_substr(basename(str_replace('\\', '/', $f['name'])), 0, 255);
    }

    private function handleMaterialUploads(int $lessonId): void
    {
        foreach (Upload::normalizeMultiple($_FILES['materials'] ?? null) as $file) {
            $stored = Upload::store(
                $file,
                'materials/' . $lessonId,
                self::MATERIAL_EXTENSIONS,
                self::MATERIAL_MAX_BYTES
            );

            LessonMaterialModel::create(
                $lessonId,
                $stored['original_name'],
                $stored['stored_path'],
                $stored['extension'],
                $stored['size']
            );
        }
    }
}
