<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\CertificateService;
use App\Core\CourseAccess;
use App\Core\HtmlSanitizer;
use App\Core\OrphanFiles;
use App\Core\Upload;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\LessonMaterialModel;
use App\Models\LessonModel;
use App\Models\LessonProgressModel;
use App\Models\LiveSessionModel;
use App\Models\ModuleModel;

class LessonController
{
    private const MATERIAL_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'mp3', 'wav', 'm4a', 'zip', 'txt'];
    private const MATERIAL_MAX_BYTES = 50 * 1024 * 1024; // 50 MB

    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];
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
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

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
            $provider === 'self_hosted' ? null : $this->externalVideoRefFromPost(),
            $this->durationFromPost()
        );

        try {
            $this->handleVideoUpload($lessonId);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

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
        $course = CourseModel::find((int) $module['course_id']);

        if (!$this->canAccessCourse((int) $course['id'])) {
            http_response_code(403);
            echo 'Non sei iscritto a questo corso.';
            return;
        }

        if ($this->isModuleLocked((int) $module['id'])) {
            http_response_code(403);
            echo 'Questo modulo è bloccato: supera prima il quiz del modulo precedente.';
            return;
        }

        View::render('lessons/show', [
            'pageTitle' => $lesson['title'],
            'lesson' => $lesson,
            'module' => $module,
            'course' => $course,
            'materials' => LessonMaterialModel::forLesson((int) $lesson['id']),
            'completed' => LessonProgressModel::isCompleted((int) Auth::id(), (int) $lesson['id']),
            'liveSessions' => LiveSessionModel::upcomingForModule((int) $module['id']),
        ]);
    }

    public function editForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

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
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $lesson = LessonModel::find((int) $params['id']);

        if (!$lesson) {
            http_response_code(404);
            echo 'Lezione non trovata.';
            return;
        }

        $title = trim($_POST['title'] ?? '');

        if ($title !== '') {
            $provider = $this->videoProviderFromPost();

            LessonModel::update(
                (int) $lesson['id'],
                $title,
                $this->contentHtmlFromPost(),
                $provider,
                $this->resolveVideoRefForUpdate($lesson, $provider),
                $this->durationFromPost()
            );
        }

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

        LessonModel::delete((int) $lesson['id']);

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
        $module = ModuleModel::find((int) $lesson['module_id']);

        if (!$this->canAccessCourse((int) $module['course_id'])) {
            http_response_code(403);
            echo 'Non sei iscritto a questo corso.';
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

        if (!$this->canAccessCourse((int) $module['course_id'])) {
            http_response_code(403);
            echo 'Non sei iscritto a questo corso.';
            return;
        }

        $absolute = Upload::absolutePath($lesson['video_ref']);

        if (!is_file($absolute)) {
            http_response_code(404);
            echo 'File video non trovato sul server.';
            return;
        }

        $this->streamFileWithRangeSupport($absolute);
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

    private function externalVideoRefFromPost(): ?string
    {
        $ref = trim($_POST['video_ref'] ?? '');

        return $ref === '' ? null : $ref;
    }

    private function resolveVideoRefForUpdate(array $lesson, string $newProvider): ?string
    {
        return match ($newProvider) {
            'bunny', 'cloudflare' => $this->externalVideoRefFromPost(),
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

    private function streamFileWithRangeSupport(string $absolutePath): void
    {
        $size = filesize($absolutePath);
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';

        $start = 0;
        $end = $size - 1;

        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mime);

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = $matches[1] === '' ? 0 : (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);

            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                return;
            }

            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        header('Content-Length: ' . ($end - $start + 1));

        $stream = fopen($absolutePath, 'rb');
        fseek($stream, $start);
        $bytesLeft = $end - $start + 1;

        while ($bytesLeft > 0 && !feof($stream)) {
            $read = (int) min(1024 * 1024, $bytesLeft);
            echo fread($stream, $read);
            flush();
            $bytesLeft -= $read;
        }

        fclose($stream);
    }
}
