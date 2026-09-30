<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\CourseRights;
use App\Core\Csrf;
use App\Core\Csv;
use App\Core\View;
use App\Core\WatchIntervals;
use App\Core\Xlsx;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\LessonModel;
use App\Models\ModuleModel;
use App\Models\VideoProgressModel;

/**
 * Riceve dal player quanto lo studente ha guardato, e mostra il rendiconto
 * di una lezione.
 *
 * Sul dato in arrivo, da tenere bene a mente leggendo questo file: lo manda
 * il JavaScript dello studente, e il JavaScript dello studente e' modificabile
 * da chiunque apra gli strumenti per sviluppatori. I controlli qui sotto
 * rendono inefficace la falsificazione ingenua — non si guardano dieci minuti
 * di video in due minuti di orologio, non si guarda un video oltre la sua
 * durata — ma **non rendono il dato infalsificabile**, e nessun controllo
 * lato server puo' farlo, perche' il dato nasce dalla parte sbagliata della
 * rete. Vedi pistacchio-lms.md Sezione 8.2.
 */
class VideoProgressController
{
    /**
     * Ogni quanto lo script manda i dati. Serve a sapere quanto tempo di
     * orologio e' lecito che sia passato fra due invii: se ne arrivano tre
     * al secondo, il tetto di plausibilita' non li lascia passare comunque.
     */
    public const REPORT_EVERY_SECONDS = 60;

    /**
     * Sotto questa soglia «riprendi» non compare: tornare al secondo 12 non
     * e' riprendere, e' ricominciare con un fastidio in piu'.
     */
    public const RESUME_MIN_SECONDS = 30;

    /**
     * E nemmeno negli ultimi secondi: chi e' arrivato in fondo vuole
     * rivederlo da capo, non riprendere dai titoli di coda.
     */
    public const RESUME_TAIL_SECONDS = 30;

    // ---------------------------------------------------------------
    // Ricezione
    // ---------------------------------------------------------------

    /**
     * POST /lessons/{id}/fruizione — chiamato dallo script del player.
     *
     * Risponde sempre JSON, anche in errore: dall'altra parte c'e' un
     * `fetch`, non un browser che naviga.
     */
    public function store(array $params): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        // Gli stessi controlli di ogni altra pagina — utente ancora
        // esistente, sessione non aperta con una password poi cambiata,
        // nessun cambio password in sospeso — ma come risposta invece che
        // come rimando: un 302 verso `/login` il `fetch` lo seguirebbe, e
        // lo script si ritroverebbe in mano la pagina di accesso senza
        // capire che la sessione e' scaduta.
        if (!Auth::sessionIsCurrent()) {
            http_response_code(401);
            echo json_encode(['errore' => 'sessione scaduta']);
            return;
        }

        if (!Csrf::isValid($_POST[Csrf::FIELD] ?? null)) {
            http_response_code(419);
            echo json_encode(['errore' => 'token non valido']);
            return;
        }

        $lessonId = (int) $params['id'];
        $lesson = LessonModel::find($lessonId);

        if ($lesson === null) {
            http_response_code(404);
            echo json_encode(['errore' => 'lezione non trovata']);
            return;
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        if ($module === null) {
            http_response_code(404);
            echo json_encode(['errore' => 'lezione non trovata']);
            return;
        }

        $userId = (int) Auth::id();
        $courseId = (int) $module['course_id'];

        // Chi non e' iscritto non genera fruizione. Lo staff puo' guardare i
        // video per lavoro, e quel tempo non e' didattica di nessuno: non va
        // nel rendiconto.
        if (!Auth::hasRole('admin', 'tutor', 'assistente')) {
            if (EnrollmentModel::find($userId, $courseId) === null) {
                http_response_code(403);
                echo json_encode(['errore' => 'non iscritto']);
                return;
            }
        } elseif (EnrollmentModel::find($userId, $courseId) === null) {
            http_response_code(204);
            return;
        }

        $durata = self::durationFromPost($lesson);
        $posizione = max(0, (int) ($_POST['posizione'] ?? 0));

        if ($durata !== null && $durata > 0) {
            $posizione = min($posizione, $durata);
        }

        $nuovi = WatchIntervals::fromJson(
            isset($_POST['intervalli']) ? (string) $_POST['intervalli'] : null,
            $durata
        );

        // Quanto orologio e' passato dall'ultima scrittura di questo
        // studente su questa lezione. La prima volta non c'e' un «prima»:
        // vale l'intervallo di invio, cosi' la finestra iniziale e' la
        // stessa delle successive.
        //
        // Il conto lo fa il database: vedi il commento su
        // `secondsSinceLastWrite()`, e' l'unico modo perche' i due istanti
        // vengano dallo stesso orologio.
        $trascorsi = VideoProgressModel::secondsSinceLastWrite($userId, $lessonId)
            ?? self::REPORT_EVERY_SECONDS;

        $nuovi = WatchIntervals::capToElapsed($nuovi, $trascorsi);

        try {
            VideoProgressModel::record($userId, $lessonId, $posizione, $durata, $nuovi);
        } catch (\Throwable $e) {
            error_log('[Fruizione] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['errore' => 'salvataggio non riuscito']);
            return;
        }

        echo json_encode(['salvato' => true]);
    }

    /**
     * La durata dichiarata dal player, con quella scritta a mano sulla
     * lezione come ripiego. Una durata assurda si scarta: con cinquecento
     * studenti basta un player confuso per sporcare le percentuali di tutti.
     */
    private static function durationFromPost(array $lesson): ?int
    {
        $dichiarata = isset($_POST['durata']) ? (int) $_POST['durata'] : 0;

        // 24 ore: nessuna lezione dura tanto, e oltre quel numero siamo
        // davanti a un millisecondo scambiato per un secondo.
        if ($dichiarata > 0 && $dichiarata <= 86400) {
            return $dichiarata;
        }

        $sullaLezione = (int) ($lesson['duration_seconds'] ?? 0);

        return $sullaLezione > 0 ? $sullaLezione : null;
    }

    // ---------------------------------------------------------------
    // Rendiconto
    // ---------------------------------------------------------------

    /**
     * GET /lessons/{id}/fruizione — la pagina del rendiconto.
     */
    public function report(array $params): void
    {
        [$lesson, $module, $course, $righe] = $this->reportData((int) $params['id']);

        View::render('lessons/fruizione', [
            'pageTitle' => 'Fruizione — ' . $lesson['title'],
            'lesson' => $lesson,
            'module' => $module,
            'course' => $course,
            'righe' => $righe,
            'riepilogo' => self::riepilogo($righe),
        ]);
    }

    /**
     * GET /lessons/{id}/fruizione/csv e .../xlsx — lo stesso rendiconto da
     * portare fuori. Il formato lo sceglie chi scarica, i dati sono gli
     * stessi: un rendiconto che cambia a seconda del formato non e' un
     * rendiconto.
     */
    public function download(array $params): void
    {
        [$lesson, , $course, $righe] = $this->reportData((int) $params['id']);

        $formato = (string) ($params['formato'] ?? 'csv');
        $nome = 'fruizione-lezione-' . (int) $lesson['id'] . '-' . date('Y-m-d');

        $intestazioni = [
            'Studente', 'Email', 'Corso', 'Lezione',
            'Percentuale vista', 'Tempo guardato (minuti)', 'Durata video (minuti)',
            'Ultima posizione (minuti)', 'Prima visita', 'Ultima visita', 'Completata il',
        ];

        $xlsx = $formato === 'xlsx';

        if ($xlsx && !Xlsx::disponibile()) {
            http_response_code(500);
            echo 'Il formato XLSX richiede l\'estensione zip di PHP, che qui non c\'è. Scarica il CSV.';
            return;
        }

        /*
         * I numeri escono diversi nei due formati, e non e' una svista.
         * Nell'XLSX sono numeri veri, cosi' chi apre il rendiconto puo'
         * sommarli e filtrarli. Nel CSV sono testo con la virgola decimale,
         * perche' Excel in italiano legge «12.5» come una data e «12,5»
         * come un numero: mettere il punto li' vorrebbe dire consegnare alla
         * Regione una colonna di date.
         */
        $dati = [];

        foreach ($righe as $riga) {
            $dati[] = [
                $riga['full_name'],
                $riga['email'],
                $course['title'],
                $lesson['title'],
                $riga['percentage'] === null ? '' : $riga['percentage'],
                self::minuti($riga['watched_seconds'], $xlsx),
                $riga['duration_seconds'] === null ? '' : self::minuti($riga['duration_seconds'], $xlsx),
                $riga['position_seconds'] === null ? '' : self::minuti($riga['position_seconds'], $xlsx),
                self::data($riga['first_seen_at']),
                self::data($riga['updated_at']),
                self::data($riga['completed_at']),
            ];
        }

        if ($xlsx) {
            Xlsx::send($nome . '.xlsx', $intestazioni, $dati, [
                4 => 'numero', 5 => 'numero', 6 => 'numero', 7 => 'numero',
            ], 'Fruizione');
            return;
        }

        Csv::send($nome . '.csv', $intestazioni, $dati);
    }

    /**
     * Carica lezione, modulo, corso e righe, dopo aver verificato che chi
     * chiede possa vedere quella lezione. Sta in un posto solo perche' la
     * pagina e i due scaricamenti devono dare esattamente gli stessi dati e
     * gli stessi controlli.
     *
     * @return array{0: array, 1: array, 2: array, 3: list<array<string, mixed>>}
     */
    private function reportData(int $lessonId): array
    {
        Auth::requireRole('admin', 'tutor', 'assistente');

        $lesson = LessonModel::find($lessonId);

        if ($lesson === null) {
            http_response_code(404);
            exit('Lezione non trovata.');
        }

        $module = ModuleModel::find((int) $lesson['module_id']);

        if ($module === null) {
            http_response_code(404);
            exit('Lezione non trovata.');
        }

        $course = CourseModel::find((int) $module['course_id']);

        if ($course === null) {
            http_response_code(404);
            exit('Corso non trovato.');
        }

        /*
         * Due strade per arrivare qui, e vanno bene tutte e due: chi puo'
         * modificare il corso (ci si arriva da Modifica lezione) e chi puo'
         * consultare i report di tutti (ci si arriva da Report → Fruizione
         * dei video).
         *
         * `report.view_assigned` **non** basta: questa pagina mostra tutti
         * gli iscritti al corso, mentre chi ha quel permesso puo' vedere
         * solo gli studenti dei gruppi del proprio tutor. La matrice per
         * corso dentro Report e' gia' ristretta a quel perimetro, e da li'
         * il titolo della lezione non porta un collegamento a chi non puo'
         * aprirlo.
         */
        if (!CourseRights::canEdit((int) $course['id']) && !Auth::can('report.view')) {
            http_response_code(403);
            exit('Accesso negato: questa lezione non è fra quelle che puoi gestire, '
                . 'e non hai i permessi per consultare i report di tutti gli studenti.');
        }

        $durata = (int) ($lesson['duration_seconds'] ?? 0);

        $righe = VideoProgressModel::reportForLesson(
            $lessonId,
            (int) $module['course_id'],
            $durata > 0 ? $durata : null
        );

        return [$lesson, $module, $course, $righe];
    }

    /**
     * I due numeri in cima alla pagina. Sono medie sugli **iscritti**, non
     * su chi ha guardato: la media di chi ha guardato direbbe sempre bene,
     * ed e' esattamente il numero che non serve a un rendiconto.
     *
     * @param  list<array<string, mixed>> $righe
     * @return array{iscritti: int, avviati: int, media: int|null, completati: int}
     */
    public static function riepilogo(array $righe): array
    {
        $iscritti = count($righe);
        $avviati = 0;
        $completati = 0;
        $somma = 0;
        $conPercentuale = 0;

        foreach ($righe as $riga) {
            if (($riga['watched_seconds'] ?? 0) > 0) {
                $avviati++;
            }

            if (($riga['completed_at'] ?? null) !== null) {
                $completati++;
            }

            if ($riga['percentage'] !== null) {
                $somma += (int) $riga['percentage'];
                $conPercentuale++;
            }
        }

        return [
            'iscritti' => $iscritti,
            'avviati' => $avviati,
            'media' => $conPercentuale > 0 ? (int) round($somma / $conPercentuale) : null,
            'completati' => $completati,
        ];
    }

    /**
     * Minuti con un decimale: i secondi in un rendiconto non li legge
     * nessuno. Numero vero per il foglio di calcolo, testo con la virgola
     * per il CSV e per la pagina — vedi il commento in `download()`.
     */
    public static function minuti(int $secondi, bool $comeNumero = false): string|float
    {
        $minuti = round($secondi / 60, 1);

        return $comeNumero ? $minuti : number_format($minuti, 1, ',', '');
    }

    private static function data(?string $quando): string
    {
        if ($quando === null) {
            return '';
        }

        $ora = strtotime($quando);

        return $ora === false ? '' : date('d/m/Y H:i', $ora);
    }

}
