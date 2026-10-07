<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\FileStream;
use App\Core\TutorWelcome;
use App\Core\Upload;
use App\Models\CourseModel;
use App\Models\TutorWelcomeModel;

/**
 * Il benvenuto del tutor all'inizio di un corso (07/10). Lo carica il tutor,
 * il proprio, dalla pagina di modifica dei corsi dei suoi gruppi (permesso
 * `course.welcome_own`); l'admin lo carica per qualunque tutor
 * (`course.welcome`). Prima versione: solo l'admin; Elena ha precisato che
 * e' il tutor a caricarlo, e che ogni corso deve averne uno.
 *
 * Foto e audio si servono passando di qui, mai da un indirizzo pubblico: li
 * ricevono l'admin, il tutor del benvenuto, e gli studenti per cui quel
 * benvenuto e' il loro (`TutorWelcomeModel::forStudent()`). A tutti gli altri
 * l'indirizzo risponde 404, come a un benvenuto che non c'e'.
 */
class TutorWelcomeController extends AdminController
{
    /** La trascrizione: abbastanza per due minuti di parlato, con margine. */
    public const TRANSCRIPT_MAX_CHARS = 4000;

    public function save(array $params): void
    {
        $courseId = (int) $params['id'];
        $tutorId = (int) $params['tutorId'];
        self::requireGestione($tutorId);
        $redirect = '/admin/courses/' . $courseId . '/edit#benvenuti';

        if (CourseModel::find($courseId) === null || !$this->tutorDelCorso($courseId, $tutorId)) {
            $this->notFound('Tutor o corso non trovato.');
            return;
        }

        $current = TutorWelcomeModel::findFor($courseId, $tutorId);
        $transcript = trim(str_replace("\r\n", "\n", (string) ($_POST['transcript'] ?? '')));
        $hasPhoto = !empty($_FILES['photo']['name']);
        $hasAudio = !empty($_FILES['audio']['name']);

        if ($transcript === '') {
            $this->fail('Scrivi il testo del benvenuto: chi non può ascoltare lo legge.', $redirect);
        }

        if (mb_strlen($transcript) > self::TRANSCRIPT_MAX_CHARS) {
            $this->fail('Il testo supera i ' . self::TRANSCRIPT_MAX_CHARS . ' caratteri.', $redirect);
        }

        if ($current === null && (!$hasPhoto || !$hasAudio)) {
            $this->fail('Per un benvenuto nuovo servono sia la foto sia l\'audio.', $redirect);
        }

        $photo = $current['photo_path'] ?? null;
        $audio = $current['audio_path'] ?? null;
        $nuovi = [];

        try {
            if ($hasPhoto) {
                $photo = $nuovi[] = TutorWelcome::storePhoto($_FILES['photo'], $courseId);
            }
            if ($hasAudio) {
                $audio = $nuovi[] = TutorWelcome::storeAudio($_FILES['audio'], $courseId);
            }
        } catch (\RuntimeException $e) {
            // Un file salvato e l'altro no: il primo non e' legato a niente.
            foreach ($nuovi as $orfano) {
                TutorWelcome::delete($orfano);
            }
            $this->fail($e->getMessage(), $redirect);
        }

        // Prima si scrive in tabella, poi si cancellano i file vecchi: al
        // contrario, un errore in mezzo lascerebbe la riga che punta al nulla
        // (lo stesso ordine della copertina del corso).
        TutorWelcomeModel::save($courseId, $tutorId, (string) $photo, (string) $audio, $transcript);

        if ($hasPhoto) {
            TutorWelcome::delete($current['photo_path'] ?? null);
        }
        if ($hasAudio) {
            TutorWelcome::delete($current['audio_path'] ?? null);
        }

        $this->success($current === null ? 'Benvenuto caricato.' : 'Benvenuto aggiornato.', $redirect);
    }

    public function destroy(array $params): void
    {
        $courseId = (int) $params['id'];
        self::requireGestione((int) $params['tutorId']);
        $current = TutorWelcomeModel::findFor($courseId, (int) $params['tutorId']);

        if ($current === null) {
            $this->notFound('Benvenuto non trovato.');
            return;
        }

        TutorWelcomeModel::delete((int) $current['id']);
        TutorWelcome::delete((string) $current['photo_path']);
        TutorWelcome::delete((string) $current['audio_path']);

        $this->success('Benvenuto rimosso.', '/admin/courses/' . $courseId . '/edit#benvenuti');
    }

    public function photo(array $params): void
    {
        $welcome = $this->accessibile((int) $params['id'], 'photo_path');

        if ($welcome === null) {
            return;
        }

        $absolute = Upload::absolutePath((string) $welcome['photo_path']);
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($absolute));
        readfile($absolute);
    }

    public function audio(array $params): void
    {
        $welcome = $this->accessibile((int) $params['id'], 'audio_path');

        if ($welcome === null) {
            return;
        }

        header('Cache-Control: private, max-age=86400');
        FileStream::send(Upload::absolutePath((string) $welcome['audio_path']), TutorWelcome::audioMime((string) $welcome['audio_path']));
    }

    /**
     * Lo studente ha ascoltato l'audio fino in fondo: dalla visita dopo il
     * benvenuto e' ridotto a una riga. Lo manda lo script della pagina del
     * corso; senza JavaScript vale solo il conto delle visite.
     */
    public function listened(array $params): void
    {
        Auth::requireLogin();

        $welcome = TutorWelcomeModel::find((int) $params['id']);
        $userId = (int) Auth::id();

        if ($welcome !== null) {
            $suo = TutorWelcomeModel::forStudent((int) $welcome['course_id'], $userId);

            if ($suo !== null && (int) $suo['id'] === (int) $welcome['id']) {
                TutorWelcomeModel::markListened($userId, (int) $welcome['course_id']);
            }
        }

        http_response_code(204);
    }

    /**
     * Il benvenuto, se chi chiede puo' riceverne i file; altrimenti risponde
     * 404 e restituisce null. Lo stesso 404 per un benvenuto che non c'e' e
     * per uno che non e' suo: non si dice a chi non deve sentirlo che esiste.
     *
     * @return array<string, mixed>|null
     */
    private function accessibile(int $id, string $campo): ?array
    {
        Auth::requireLogin();

        $welcome = TutorWelcomeModel::find($id);
        $userId = (int) Auth::id();
        $puo = false;

        if ($welcome !== null) {
            if (Auth::can('course.welcome') || (int) $welcome['tutor_id'] === $userId) {
                $puo = true;
            } else {
                $suo = TutorWelcomeModel::forStudent((int) $welcome['course_id'], $userId);
                $puo = $suo !== null && (int) $suo['id'] === $id;
            }
        }

        if (!$puo || !is_file(Upload::absolutePath((string) $welcome[$campo]))) {
            http_response_code(404);
            echo 'Benvenuto non trovato.';
            return null;
        }

        return $welcome;
    }

    /**
     * Chi puo' caricare o togliere il benvenuto di questo tutor: l'admin per
     * chiunque, il tutor per se stesso. Come `group.manage` e
     * `group.manage_own`: la differenza fra «tutti» e «il proprio» sta nei
     * permessi, e il confronto con l'utente qui.
     */
    public static function puoGestire(int $tutorId): bool
    {
        return Auth::can('course.welcome')
            || (Auth::can('course.welcome_own') && (int) Auth::id() === $tutorId);
    }

    private static function requireGestione(int $tutorId): void
    {
        Auth::requireLogin();

        if (!self::puoGestire($tutorId)) {
            http_response_code(403);
            echo 'Non puoi modificare il benvenuto di questo tutor.';
            exit;
        }
    }

    private function tutorDelCorso(int $courseId, int $tutorId): bool
    {
        foreach (TutorWelcomeModel::tutorsForCourse($courseId) as $riga) {
            if ((int) $riga['tutor_id'] === $tutorId) {
                return true;
            }
        }

        return false;
    }
}
