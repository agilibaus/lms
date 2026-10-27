<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\CertificateService;
use App\Core\CourseAccess;
use App\Core\FileStream;
use App\Core\H5P;
use App\Core\H5PArchivio;
use App\Core\QuizScoring;
use App\Core\Theme;
use App\Models\EnrollmentModel;
use App\Models\LessonH5PModel;
use App\Models\LessonModel;
use App\Models\ModuleModel;
use App\Models\QuizAttemptModel;
use App\Models\QuizModel;

/**
 * I questionari H5P (22/10) e i contenuti H5P delle lezioni (23/10) dal
 * lato del browser: i file del contenuto per il riproduttore, il punteggio che arriva a fine esercizio, il foglio di
 * stile con i colori e il carattere del tema. Caricamento e pagine stanno
 * in `QuizController`; le regole in `App\Core\H5P`.
 */
class H5PController
{
    /** La chiave di sessione dei questionari H5P gia' aperti (vedi `file()`). */
    public const SESSIONE = 'h5p_aperti';

    /** La stessa cosa per i contenuti delle lezioni (23/10), per id del contenuto. */
    public const SESSIONE_LEZIONI = 'h5p_lezioni_aperti';

    /**
     * GET /quizzes/{id}/h5p/{p1}[/{p2}…] — un file del contenuto.
     *
     * Il riproduttore ne chiede decine per ogni esercizio: il permesso si
     * controlla per intero la prima volta (la pagina del questionario lo
     * segna in sessione), e la sessione si chiude subito, perche' PHP la
     * tiene bloccata finche' la richiesta e' aperta e i file arriverebbero
     * uno per volta.
     */
    public function file(array $params): void
    {
        Auth::requireLogin();

        $quizId = (int) $params['id'];
        $giaAperto = !empty($_SESSION[self::SESSIONE][$quizId]);
        session_write_close();

        $quiz = QuizModel::find($quizId);

        if ($quiz === null || ($quiz['kind'] ?? '') !== 'h5p' || empty($quiz['h5p_dir'])) {
            $this->manca();
        }

        if (!$giaAperto && !self::puoAprire($quiz)) {
            $this->vietato();
        }

        $this->manda((string) $quiz['h5p_dir'], $params);
    }

    /**
     * GET /lessons/{id}/h5p/{contenuto}/{p1}[/{p2}…] — un file di un
     * contenuto H5P della lezione (23/10). Come `file()`: il permesso per
     * intero la prima volta, poi la sessione aperta dalla pagina della
     * lezione.
     *
     * `{contenuto}` e' l'id con la parte casuale della cartella
     * («12-a1b2c3d4e5f6», `self::chiave()`): sostituito il file, l'id resta
     * (i segnaposto nel testo valgono ancora) ma l'indirizzo cambia, e il
     * browser non mescola file vecchi e nuovi dalla sua cache.
     */
    public function fileLezione(array $params): void
    {
        Auth::requireLogin();

        if (preg_match('/^(\d{1,10})-([0-9a-f]{12})$/', (string) $params['contenuto'], $m) !== 1) {
            $this->manca();
        }

        $id = (int) $m[1];
        $giaAperto = !empty($_SESSION[self::SESSIONE_LEZIONI][$id]);
        session_write_close();

        $contenuto = LessonH5PModel::find($id);

        if ($contenuto === null || (int) $contenuto['lesson_id'] !== (int) $params['id']
            || self::chiave($contenuto) !== $params['contenuto']) {
            $this->manca();
        }

        if (!$giaAperto && !self::puoAprireLezione((int) $contenuto['lesson_id'])) {
            $this->vietato();
        }

        $this->manda((string) $contenuto['h5p_dir'], $params);
    }

    /** «12-a1b2c3d4e5f6»: l'id del contenuto e la parte casuale della sua cartella. */
    public static function chiave(array $contenuto): string
    {
        return (int) $contenuto['id'] . '-' . substr((string) $contenuto['h5p_dir'], -12);
    }

    /** L'indirizzo da cui il riproduttore prende i file di un contenuto della lezione. */
    public static function percorsoLezione(array $contenuto): string
    {
        return '/lessons/' . (int) $contenuto['lesson_id'] . '/h5p/' . self::chiave($contenuto);
    }

    /** Il file `p1/p2/…` della cartella del contenuto, con il tipo deciso qui. */
    private function manda(string $dir, array $params): never
    {
        $segmenti = [];

        for ($i = 1; isset($params['p' . $i]); $i++) {
            $segmenti[] = rawurldecode((string) $params['p' . $i]);
        }

        $percorso = implode('/', $segmenti);
        $file = H5PArchivio::file($dir, $percorso);
        $tipo = H5P::tipo($percorso);

        if ($file === null || $tipo === null) {
            $this->manca();
        }

        // La cartella cambia a ogni contenuto caricato: i file non cambiano
        // mai sotto lo stesso indirizzo, e il browser puo' tenerli.
        header('Cache-Control: private, max-age=86400');

        // Un SVG aperto da solo e' un documento, e potrebbe eseguire codice:
        // qui puo' solo disegnarsi.
        if ($tipo === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        }

        if (str_starts_with($tipo, 'text/')) {
            $tipo .= '; charset=utf-8';
        }

        FileStream::send($file, $tipo);
    }

    /**
     * POST /quizzes/{id}/h5p/tentativo — il punteggio a fine esercizio
     * (`raw`, `max`), dalla pagina del questionario. Risponde in JSON con
     * l'esito, che la pagina scrive sotto l'esercizio.
     *
     * Solo lo studente iscritto salva: per lo staff la pagina e'
     * un'anteprima, e la risposta lo dice senza scrivere niente.
     */
    public function tentativo(array $params): void
    {
        Auth::requireLogin();

        $quiz = QuizModel::find((int) $params['id']);

        if ($quiz === null || ($quiz['kind'] ?? '') !== 'h5p') {
            $this->json(404, ['errore' => 'Questionario non trovato.']);
        }

        if (!self::puoAprire($quiz)) {
            $this->json(403, ['errore' => 'Non puoi svolgere questo questionario.']);
        }

        if (!Auth::hasRole('studente')) {
            $this->json(200, ['anteprima' => true, 'messaggio' => 'Anteprima: il punteggio non viene salvato.']);
        }

        $userId = (int) Auth::id();

        // Superato al 100% (23/10): concluso. La pagina e' spenta, ma un
        // punteggio puo' arrivare lo stesso (una scheda rimasta aperta, un
        // invio a mano): non si salva.
        if (QuizScoring::concluso(QuizAttemptModel::bestForUserAndQuiz($userId, (int) $quiz['id']))) {
            $this->json(409, ['errore' => QuizScoring::CONCLUSO . ' Il nuovo tentativo non è stato salvato.']);
        }

        $punteggio = H5P::punteggio($_POST['raw'] ?? null, $_POST['max'] ?? null);

        if ($punteggio === null) {
            $this->json(422, ['errore' => 'Il punteggio ricevuto non è valido: il tentativo non è stato salvato.']);
        }

        $soglia = (int) $quiz['passing_score_pct'];
        // La stessa regola dei questionari a domande, con «1» domanda
        // valutata: c'e' sempre un punteggio.
        $superato = QuizScoring::superato($punteggio['pct'], $soglia, 1);

        QuizAttemptModel::create($userId, (int) $quiz['id'], $punteggio['pct'], $superato, [], $punteggio);

        if ($superato) {
            $module = ModuleModel::find((int) $quiz['module_id']);

            try {
                CertificateService::issueIfEligible($userId, (int) ($module['course_id'] ?? 0));
            } catch (\RuntimeException $e) {
                error_log('[Certificati] ' . $e->getMessage());
            }
        }

        $migliore = QuizAttemptModel::bestForUserAndQuiz($userId, (int) $quiz['id']);

        $this->json(200, [
            'salvato' => true,
            'messaggio' => 'Tentativo salvato: ' . H5P::punti($punteggio['raw'], $punteggio['max'])
                . ' (' . number_format($punteggio['pct'], 0) . '%), '
                . ($superato ? 'superato.' : 'non superato: la soglia è ' . $soglia . '%.'),
            'superato' => $superato,
            'migliore' => $migliore === null ? null : (float) $migliore['score_pct'],
        ]);
    }

    /**
     * GET /h5p/tema.css — il carattere e i colori del tema dentro gli
     * esercizi (22/10). Solo per i tipi provati: i pulsanti di tutti gli
     * esercizi a domande di H5P (Verifica, Riprova, Mostra soluzione) e i
     * riquadri di «Drag the Words», grigi e non colorati (un riquadro
     * vuoto verde chiaro sembrerebbe gia' giusto). Sempre in «Drag the
     * Words» (23/10), la parola sotto il mouse o trascinata (era rosa) e
     * quella gia' messa in un riquadro (era azzurra) prendono la tinta
     * chiara e il filo del tema; una parola messa e sotto il mouse ha il
     * filo piu' spesso. Dopo la verifica giuste e sbagliate restano di H5P. Gli altri tipi restano
     * con i colori di H5P. I colori vengono da `Theme::valori()`, che li ha gia' controllati;
     * il verde e il rosso di giusto e sbagliato restano quelli di H5P,
     * perche' hanno un significato.
     */
    public function tema(array $params = []): void
    {
        Auth::requireLogin();
        session_write_close();

        $v = Theme::valori();
        $famiglia = Theme::fontScelto(Theme::KEY_FONT_APP);
        $carattere = $famiglia === null
            ? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'
            : '"' . preg_replace('/[^A-Za-z0-9 ]/', '', $famiglia) . '", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

        header('Content-Type: text/css; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');

        echo Theme::bloccoFont() . "\n";
        echo ':root{--h5p-primario:' . $v['primary'] . ';--h5p-primario-hover:' . $v['hover'] . ';--h5p-primario-chiaro:' . $v['soft'] . "}\n";
        // Il carattere su tutto, tranne le icone: H5P le disegna con caratteri
        // propri (H5PFontIcons, FontAwesome), che non vanno sostituiti.
        echo 'html body, .h5p-content, .h5p-content *:not(.fa):not([class*="icon"]):not([class*="Icon"]){font-family:' . $carattere . "}\n";
        echo <<<'CSS'
.h5p-joubelui-button,
.h5p-question-buttons .h5p-joubelui-button {
    background: var(--h5p-primario);
    color: #fff;
}
.h5p-joubelui-button:hover,
.h5p-joubelui-button:focus,
.h5p-question-buttons .h5p-joubelui-button:hover,
.h5p-question-buttons .h5p-joubelui-button:focus {
    background: var(--h5p-primario-hover);
    color: #fff;
}
.h5p-joubelui-button:active,
.h5p-question-buttons .h5p-joubelui-button:active {
    background: var(--h5p-primario-hover);
    box-shadow: inset 0 3px 0 rgba(0, 0, 0, 0.18);
}
.h5p-joubelui-button:focus-visible {
    outline: 2px solid var(--h5p-primario-hover);
    outline-offset: 2px;
}
.h5p-drag-text .h5p-drag-dropzone-container .ui-droppable {
    background: #f1f0ee;
    box-shadow: inset 0 0 0 1px #a8a29e;
}
.h5p-drag-text .h5p-drag-dropzone-container .ui-droppable.ui-droppable-hover {
    background: var(--h5p-primario-chiaro);
    box-shadow: inset 0 0 0 2px var(--h5p-primario);
}
.h5p-drag-text [aria-grabbed].h5p-drag-dropped:not(.h5p-drag-draggable-correct):not(.h5p-drag-draggable-wrong) {
    color: #1c1c1a;
    border-color: var(--h5p-primario);
    background: var(--h5p-primario-chiaro);
}
.h5p-drag-text [aria-grabbed='true'],
.h5p-drag-text [aria-grabbed]:not(.ui-draggable-disabled):hover {
    color: #1c1c1a;
    border-color: var(--h5p-primario);
    background: var(--h5p-primario-chiaro);
}
.h5p-drag-text [aria-grabbed].h5p-drag-dropped:not(.ui-draggable-disabled):hover {
    color: #1c1c1a;
    border-color: var(--h5p-primario);
    background: var(--h5p-primario-chiaro);
    box-shadow: inset 0 0 0 1px var(--h5p-primario);
}
CSS;
    }

    /**
     * Se chi chiede puo' aprire il questionario: le stesse regole della sua
     * pagina (staff; studente iscritto al corso, con il modulo aperto).
     */
    /**
     * Se chi chiede puo' aprire i contenuti di una lezione: le regole della
     * sua pagina (staff; studente iscritto al corso, con il modulo aperto).
     */
    public static function puoAprireLezione(int $lessonId): bool
    {
        $lezione = LessonModel::find($lessonId);
        $module = $lezione === null ? null : ModuleModel::find((int) $lezione['module_id']);

        if ($module === null) {
            return false;
        }

        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            return true;
        }

        return EnrollmentModel::find((int) Auth::id(), (int) $module['course_id']) !== null
            && !CourseAccess::isModuleLocked((int) Auth::id(), (int) $module['id']);
    }

    public static function puoAprire(array $quiz): bool
    {
        $module = ModuleModel::find((int) $quiz['module_id']);

        if ($module === null) {
            return false;
        }

        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            return true;
        }

        // Come la pagina del questionario: anche il questionario ancora
        // chiuso (27/10, `CourseAccess::questionarioChiuso`), cosi' un
        // punteggio mandato a mano prima delle lezioni non si salva.
        return EnrollmentModel::find((int) Auth::id(), (int) $module['course_id']) !== null
            && !CourseAccess::isModuleLocked((int) Auth::id(), (int) $module['id'])
            && CourseAccess::questionarioChiuso((int) Auth::id(), $module) === null;
    }

    private function vietato(): never
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Non puoi aprire questo contenuto.';
        exit;
    }

    private function manca(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File non trovato.';
        exit;
    }

    /** @param array<string, mixed> $dati */
    private function json(int $stato, array $dati): never
    {
        http_response_code($stato);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($dati, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
