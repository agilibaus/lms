<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Google\GoogleException;
use App\Core\Google\MeetCalendar;
use App\Core\Mail\LiveSessionNotifier;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\GroupModel;
use App\Models\LiveSessionAttendanceModel;
use App\Models\LiveSessionModel;
use App\Models\ModuleModel;

/**
 * Sessioni live su Google Meet.
 *
 * Il link Meet viene creato insieme all'evento su Google Calendar quando le
 * credenziali sono configurate; se Google non e' configurato o risponde con un
 * errore, la sessione viene comunque salvata e il tutor puo' incollare un link
 * creato a mano. L'integrazione accelera il lavoro, non lo blocca.
 */
class LiveSessionController
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $isStaff = $this->canManage();

        View::render('live/index', [
            'pageTitle' => 'Sessioni live',
            'sessions' => $isStaff ? LiveSessionModel::all() : LiveSessionModel::forUser((int) Auth::id()),
            'canManage' => $isStaff,
            'googleConfigured' => MeetCalendar::isConfigured(),
        ]);
    }

    public function show(array $params): void
    {
        Auth::requireLogin();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        if (!$this->canView($session)) {
            http_response_code(403);
            echo 'Questa sessione non è fra quelle a cui sei iscritto.';
            return;
        }

        $canManage = $this->canManage();

        View::render('live/show', [
            'pageTitle' => $session['title'],
            'session' => $session,
            'canManage' => $canManage,
            'participants' => $canManage ? LiveSessionModel::participants((int) $session['id']) : [],
            'attendance' => $canManage ? LiveSessionAttendanceModel::forSession((int) $session['id']) : [],
            'hasJoined' => LiveSessionAttendanceModel::hasJoined((int) $session['id'], (int) Auth::id()),
        ]);
    }

    public function createForm(array $params = []): void
    {
        $this->requireManage();

        View::render('live/form', [
            'pageTitle' => 'Nuova sessione live',
            'session' => null,
            'modules' => $this->moduleOptions(),
            'groups' => GroupModel::all(),
            'googleConfigured' => MeetCalendar::isConfigured(),
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireManage();

        $data = $this->dataFromPost();
        $error = $this->validate($data);

        if ($error !== null) {
            $this->fail($error, '/live/create');
        }

        $googleEventId = null;
        $meetLink = $data['meet_link'];
        $warning = null;

        // Link Meet automatico solo se non ne e' gia' stato incollato uno a mano.
        if ($meetLink === null) {
            [$googleEventId, $meetLink, $warning] = $this->createGoogleEvent($data);
        }

        $sessionId = LiveSessionModel::create(
            $data['module_id'],
            $data['group_id'],
            $data['title'],
            $data['description'],
            $data['starts_at'],
            $data['ends_at'],
            $googleEventId,
            $meetLink,
            (int) Auth::id()
        );

        if ($warning !== null) {
            $_SESSION['flash_error'] = $warning;
        }

        $this->success('Sessione creata.', '/live/' . $sessionId);
    }

    public function editForm(array $params): void
    {
        $this->requireManage();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        View::render('live/form', [
            'pageTitle' => 'Modifica sessione',
            'session' => $session,
            'modules' => $this->moduleOptions(),
            'groups' => GroupModel::all(),
            'googleConfigured' => MeetCalendar::isConfigured(),
        ]);
    }

    public function update(array $params): void
    {
        $this->requireManage();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        $id = (int) $session['id'];
        $redirect = '/live/' . $id . '/edit';
        $data = $this->dataFromPost();
        $error = $this->validate($data);

        if ($error !== null) {
            $this->fail($error, $redirect);
        }

        // Gli orari di prima servono dopo, per dire nell'avviso da dove si
        // sposta l'incontro: vanno letti finche' la riga e' ancora quella.
        $primaInizio = (string) $session['starts_at'];
        $primaFine = (string) $session['ends_at'];
        $orarioCambiato = $data['starts_at'] !== $primaInizio || $data['ends_at'] !== $primaFine;

        LiveSessionModel::update(
            $id,
            $data['module_id'],
            $data['group_id'],
            $data['title'],
            $data['description'],
            $data['starts_at'],
            $data['ends_at']
        );

        // Il link incollato a mano ha la precedenza e stacca la sessione da Google.
        $linkManuale = $data['meet_link'] !== null && $data['meet_link'] !== $session['meet_link'];

        if ($linkManuale) {
            LiveSessionModel::updateGoogleReferences($id, null, $data['meet_link']);
        }

        $warning = $linkManuale ? null : $this->syncGoogleEvent($session, $data);

        if ($warning !== null) {
            $_SESSION['flash_error'] = $warning;
        }

        $messaggio = $linkManuale
            ? 'Sessione aggiornata con il link inserito manualmente.'
            : 'Sessione aggiornata.';

        if ($orarioCambiato) {
            $messaggio .= ' ' . $this->notifyChange($id, $primaInizio, $primaFine);
        }

        $this->success($messaggio, '/live/' . $id);
    }

    public function destroy(array $params): void
    {
        $this->requireManage();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        $warning = null;

        if (!empty($session['google_event_id'])) {
            $calendar = MeetCalendar::fromEnv();

            try {
                $calendar?->deleteEvent((string) $session['google_event_id']);
            } catch (GoogleException $e) {
                error_log('[Google] ' . $e->getMessage());
                $warning = 'Sessione eliminata, ma l’evento su Google Calendar va rimosso a mano: ' . $e->getMessage();
            }
        }

        // I destinatari vanno letti prima della cancellazione: dopo, la riga
        // non c'e' piu' e la lista tornerebbe vuota. Di un incontro gia'
        // concluso non si avvisa nessuno: non c'e' piu' niente da disdire.
        $daAvvisare = $this->isOver($session) ? [] : LiveSessionModel::participants((int) $session['id']);

        LiveSessionModel::delete((int) $session['id']);

        if ($warning !== null) {
            $_SESSION['flash_error'] = $warning;
        }

        $messaggio = 'Sessione eliminata.';

        if ($daAvvisare !== []) {
            $messaggio .= ' ' . LiveSessionNotifier::summary(
                LiveSessionNotifier::cancellation($session, $daAvvisare),
                'Avvisi di annullamento'
            );
        }

        $this->success($messaggio, '/live');
    }

    /**
     * Manda (o rimanda) l'invito ai partecipanti attesi.
     */
    public function invite(array $params): void
    {
        $this->requireManage();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        $id = (int) $session['id'];

        if (empty($session['meet_link'])) {
            $this->fail('Questa sessione non ha ancora un link Meet: un invito senza collegamento non serve a niente.', '/live/' . $id);
        }

        if ($this->isOver($session)) {
            $this->fail('La sessione è già conclusa.', '/live/' . $id);
        }

        $this->success(LiveSessionNotifier::summary(LiveSessionNotifier::invite($session)), '/live/' . $id);
    }

    /**
     * Ingresso dallo studente: registra la presenza e reindirizza al Meet.
     */
    public function join(array $params): void
    {
        Auth::requireLogin();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        if (!$this->canView($session)) {
            http_response_code(403);
            echo 'Questa sessione non è fra quelle a cui sei iscritto.';
            return;
        }

        if (empty($session['meet_link'])) {
            $this->fail('Questa sessione non ha ancora un link Meet.', '/live/' . (int) $session['id']);
        }

        // Lo staff entra senza comparire fra i presenti.
        if (!$this->canManage()) {
            LiveSessionAttendanceModel::markJoined((int) $session['id'], (int) Auth::id());
        }

        header('Location: ' . $session['meet_link']);
        exit;
    }

    /**
     * Registro presenze del tutor: segna o toglie un partecipante.
     */
    public function setAttendance(array $params): void
    {
        $this->requireManage();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        $sessionId = (int) $session['id'];
        $userId = (int) $params['userId'];

        if (($_POST['present'] ?? '0') === '1') {
            LiveSessionAttendanceModel::markManually($sessionId, $userId);
            $this->success('Presenza registrata.', '/live/' . $sessionId);
        }

        LiveSessionAttendanceModel::remove($sessionId, $userId);

        $this->success('Presenza rimossa.', '/live/' . $sessionId);
    }

    /**
     * Riprova la creazione dell'evento Google per una sessione che ne e' priva
     * (utile quando le credenziali arrivano dopo, o dopo un errore temporaneo).
     */
    public function syncGoogle(array $params): void
    {
        $this->requireManage();

        $session = LiveSessionModel::find((int) $params['id']);

        if ($session === null) {
            $this->notFound('Sessione non trovata.');
            return;
        }

        $id = (int) $session['id'];
        $calendar = MeetCalendar::fromEnv();

        if ($calendar === null) {
            $this->fail('Google Calendar non è configurato: inserisci un link Meet manuale.', '/live/' . $id);
        }

        try {
            $event = $calendar->createEvent(
                (string) $session['title'],
                $session['description'] !== null ? (string) $session['description'] : null,
                new \DateTimeImmutable((string) $session['starts_at']),
                new \DateTimeImmutable((string) $session['ends_at']),
                $this->participantEmails($id)
            );
        } catch (GoogleException $e) {
            error_log('[Google] ' . $e->getMessage());
            $this->fail('Google ha rifiutato la richiesta: ' . $e->getMessage(), '/live/' . $id);
        } catch (\Exception $e) {
            $this->fail('Date della sessione non valide: ' . $e->getMessage(), '/live/' . $id);
        }

        LiveSessionModel::updateGoogleReferences($id, $event['event_id'], $event['meet_link']);

        $this->success(
            $event['meet_link'] !== null
                ? 'Evento creato su Google Calendar con link Meet.'
                : 'Evento creato su Google Calendar, ma senza link Meet: verifica la delega a livello di dominio.',
            '/live/' . $id
        );
    }

    // ---------------------------------------------------------------
    // Helper privati
    // ---------------------------------------------------------------

    /**
     * La gestione delle sessioni segue `course.edit` (admin e tutor).
     */
    private function canManage(): bool
    {
        return Auth::can('course.edit');
    }

    private function requireManage(): void
    {
        Auth::requirePermission('course.edit');
    }

    private function canView(array $session): bool
    {
        if ($this->canManage() || Auth::hasRole('admin', 'tutor', 'assistente')) {
            return true;
        }

        return LiveSessionModel::isParticipant((int) $session['id'], (int) Auth::id());
    }

    /**
     * @return array{module_id: int|null, group_id: int|null, title: string, description: string|null, starts_at: string, ends_at: string, meet_link: string|null}
     */
    private function dataFromPost(): array
    {
        $moduleId = (int) ($_POST['module_id'] ?? 0);
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));
        $meetLink = trim((string) ($_POST['meet_link'] ?? ''));

        return [
            'module_id' => $moduleId > 0 ? $moduleId : null,
            'group_id' => $groupId > 0 ? $groupId : null,
            'title' => trim((string) ($_POST['title'] ?? '')),
            'description' => $description === '' ? null : $description,
            'starts_at' => $this->normalizeDateTime((string) ($_POST['starts_at'] ?? '')),
            'ends_at' => $this->normalizeDateTime((string) ($_POST['ends_at'] ?? '')),
            'meet_link' => $meetLink === '' ? null : $meetLink,
        ];
    }

    /**
     * @param array{module_id: int|null, group_id: int|null, title: string, starts_at: string, ends_at: string, meet_link: string|null} $data
     */
    private function validate(array $data): ?string
    {
        if ($data['title'] === '') {
            return 'Il titolo della sessione è obbligatorio.';
        }

        if ($data['starts_at'] === '' || $data['ends_at'] === '') {
            return 'Indica data e ora di inizio e di fine.';
        }

        if ($data['ends_at'] <= $data['starts_at']) {
            return 'La fine della sessione deve essere successiva all’inizio.';
        }

        // Senza modulo ne' gruppo la sessione non avrebbe partecipanti.
        if ($data['module_id'] === null && $data['group_id'] === null) {
            return 'Collega la sessione a un modulo di corso, a un gruppo, o a entrambi.';
        }

        if ($data['module_id'] !== null && ModuleModel::find($data['module_id']) === null) {
            return 'Modulo non trovato.';
        }

        if ($data['group_id'] !== null && GroupModel::find($data['group_id']) === null) {
            return 'Gruppo non trovato.';
        }

        if ($data['meet_link'] !== null && !filter_var($data['meet_link'], FILTER_VALIDATE_URL)) {
            return 'Il link Meet inserito non è un URL valido.';
        }

        return null;
    }

    /**
     * Converte l'input datetime-local ("2026-09-20T18:30") nel formato MySQL.
     */
    private function normalizeDateTime(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $date === false ? '' : $date->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: string|null, 1: string|null, 2: string|null} id evento, link Meet, eventuale avviso
     */
    private function createGoogleEvent(array $data): array
    {
        $calendar = MeetCalendar::fromEnv();

        if ($calendar === null) {
            return [null, null, 'Google Calendar non è configurato: la sessione è stata salvata senza link Meet, puoi inserirne uno manualmente.'];
        }

        try {
            $event = $calendar->createEvent(
                $data['title'],
                $data['description'],
                new \DateTimeImmutable($data['starts_at']),
                new \DateTimeImmutable($data['ends_at']),
                $this->participantEmailsFor($data['module_id'], $data['group_id'])
            );
        } catch (GoogleException $e) {
            error_log('[Google] ' . $e->getMessage());

            return [null, null, 'Sessione salvata, ma Google ha rifiutato la richiesta (' . $e->getMessage() . '). Puoi inserire un link Meet manuale o riprovare la sincronizzazione.'];
        }

        return [$event['event_id'], $event['meet_link'], null];
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $data
     */
    private function syncGoogleEvent(array $session, array $data): ?string
    {
        if (empty($session['google_event_id'])) {
            return null;
        }

        $calendar = MeetCalendar::fromEnv();

        if ($calendar === null) {
            return 'Sessione aggiornata, ma Google Calendar non è più configurato: l’evento non è stato allineato.';
        }

        try {
            $event = $calendar->updateEvent(
                (string) $session['google_event_id'],
                $data['title'],
                $data['description'],
                new \DateTimeImmutable($data['starts_at']),
                new \DateTimeImmutable($data['ends_at']),
                $this->participantEmailsFor($data['module_id'], $data['group_id'])
            );
        } catch (GoogleException $e) {
            error_log('[Google] ' . $e->getMessage());

            return 'Sessione aggiornata, ma l’evento Google non è stato allineato: ' . $e->getMessage();
        }

        if ($event['meet_link'] !== null) {
            LiveSessionModel::updateGoogleReferences(
                (int) $session['id'],
                $event['event_id'],
                $event['meet_link']
            );
        }

        return null;
    }

    /**
     * Avvisa del cambio di orario e restituisce la frase da aggiungere al
     * messaggio di conferma.
     *
     * La sessione viene riletta: l'avviso deve riportare il link e i dati
     * aggiornati, non quelli con cui la pagina era stata aperta.
     */
    private function notifyChange(int $id, string $previousStart, string $previousEnd): string
    {
        $session = LiveSessionModel::find($id);

        if ($session === null || empty($session['meet_link']) || $this->isOver($session)) {
            return '';
        }

        return LiveSessionNotifier::summary(
            LiveSessionNotifier::change(
                $session,
                ['starts_at' => $previousStart, 'ends_at' => $previousEnd]
            ),
            'Avvisi del cambio di orario'
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function isOver(array $session): bool
    {
        return strtotime((string) $session['ends_at']) < time();
    }

    /**
     * @return string[]
     */
    private function participantEmails(int $sessionId): array
    {
        return array_column(LiveSessionModel::participants($sessionId), 'email');
    }

    /**
     * Email dei partecipanti attesi, prima che la sessione esista in tabella.
     *
     * @return string[]
     */
    private function participantEmailsFor(?int $moduleId, ?int $groupId): array
    {
        $emails = [];

        if ($moduleId !== null) {
            $module = ModuleModel::find($moduleId);

            if ($module !== null) {
                $emails = array_merge($emails, array_column(
                    EnrollmentModel::forCourse((int) $module['course_id']),
                    'email'
                ));
            }
        }

        if ($groupId !== null) {
            $emails = array_merge($emails, array_column(GroupModel::members($groupId), 'email'));
        }

        return array_values(array_unique($emails));
    }

    /**
     * Moduli disponibili, etichettati con il corso di appartenenza.
     */
    private function moduleOptions(): array
    {
        $options = [];

        foreach (CourseModel::allForStaff() as $course) {
            foreach (ModuleModel::forCourse((int) $course['id']) as $module) {
                $options[] = [
                    'id' => (int) $module['id'],
                    'label' => $course['title'] . ' — ' . $module['title'],
                ];
            }
        }

        return $options;
    }

    private function success(string $message, string $location): never
    {
        $_SESSION['flash_success'] = $message;

        $this->redirect($location);
    }

    private function fail(string $message, string $location): never
    {
        $_SESSION['flash_error'] = $message;

        $this->redirect($location);
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }

    private function notFound(string $message): void
    {
        http_response_code(404);
        echo $message;
    }
}
