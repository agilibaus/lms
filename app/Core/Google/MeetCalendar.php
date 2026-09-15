<?php

declare(strict_types=1);

namespace App\Core\Google;

use App\Core\Env;

/**
 * Creazione e gestione di eventi Google Calendar con link Meet.
 *
 * Un link Meet nasce insieme all'evento: si chiede a Calendar di creare una
 * conferenza (`conferenceData.createRequest`) passando `conferenceDataVersion=1`.
 */
class MeetCalendar
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar';

    private const API_BASE = 'https://www.googleapis.com/calendar/v3/calendars/';

    public function __construct(
        private ServiceAccountClient $client,
        private string $calendarId = 'primary',
        private string $timeZone = 'Europe/Rome',
    ) {
    }

    /**
     * Istanza configurata da .env, oppure null se mancano le credenziali:
     * in quel caso le sessioni live restano gestibili con link inseriti a mano.
     */
    public static function fromEnv(?HttpTransport $transport = null): ?self
    {
        $client = ServiceAccountClient::fromEnv($transport);

        if ($client === null) {
            return null;
        }

        return new self(
            $client,
            (string) Env::get('GOOGLE_CALENDAR_ID', 'primary'),
            (string) Env::get('GOOGLE_CALENDAR_TIMEZONE', date_default_timezone_get())
        );
    }

    public static function isConfigured(): bool
    {
        return self::fromEnv() !== null;
    }

    /**
     * Crea l'evento con conferenza Meet.
     *
     * @param string[] $attendeeEmails
     * @return array{event_id: string, meet_link: string|null, html_link: string|null}
     * @throws GoogleException
     */
    public function createEvent(
        string $title,
        ?string $description,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        array $attendeeEmails = []
    ): array {
        $payload = $this->eventPayload($title, $description, $startsAt, $endsAt, $attendeeEmails);

        // requestId univoco: identifica la richiesta di conferenza ed evita
        // che un retry crei due Meet per lo stesso evento.
        $payload['conferenceData'] = [
            'createRequest' => [
                'requestId' => bin2hex(random_bytes(16)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ];

        $event = $this->client->requestJson(
            'POST',
            $this->eventsUrl() . '?conferenceDataVersion=1&sendUpdates=none',
            self::SCOPE,
            $payload
        );

        return $this->extractEvent($event);
    }

    /**
     * Aggiorna titolo, descrizione, orari e invitati di un evento esistente,
     * conservando la conferenza Meet gia' creata.
     *
     * @param string[] $attendeeEmails
     * @return array{event_id: string, meet_link: string|null, html_link: string|null}
     * @throws GoogleException
     */
    public function updateEvent(
        string $eventId,
        string $title,
        ?string $description,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        array $attendeeEmails = []
    ): array {
        $event = $this->client->requestJson(
            'PATCH',
            $this->eventsUrl() . '/' . rawurlencode($eventId) . '?conferenceDataVersion=1&sendUpdates=none',
            self::SCOPE,
            $this->eventPayload($title, $description, $startsAt, $endsAt, $attendeeEmails)
        );

        return $this->extractEvent($event);
    }

    /**
     * Elimina l'evento dal calendario. Un evento gia' rimosso (404/410) non e'
     * un errore: l'obiettivo — che non ci sia piu' — e' comunque raggiunto.
     *
     * @throws GoogleException
     */
    public function deleteEvent(string $eventId): void
    {
        try {
            $this->client->requestJson(
                'DELETE',
                $this->eventsUrl() . '/' . rawurlencode($eventId) . '?sendUpdates=none',
                self::SCOPE
            );
        } catch (GoogleException $e) {
            if (!str_contains($e->getMessage(), 'Google ha risposto 404')
                && !str_contains($e->getMessage(), 'Google ha risposto 410')) {
                throw $e;
            }
        }
    }

    // ---------------------------------------------------------------

    private function eventsUrl(): string
    {
        return self::API_BASE . rawurlencode($this->calendarId) . '/events';
    }

    /**
     * @param string[] $attendeeEmails
     * @return array<string, mixed>
     */
    private function eventPayload(
        string $title,
        ?string $description,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        array $attendeeEmails
    ): array {
        // Gli orari vengono espressi nel fuso del calendario, cosi' l'offset
        // inviato e il campo timeZone non possono mai contraddirsi.
        $zone = new \DateTimeZone($this->timeZone);
        $start = \DateTimeImmutable::createFromInterface($startsAt)->setTimezone($zone);
        $end = \DateTimeImmutable::createFromInterface($endsAt)->setTimezone($zone);

        $payload = [
            'summary' => $title,
            'start' => ['dateTime' => $start->format(\DateTimeInterface::RFC3339), 'timeZone' => $this->timeZone],
            'end' => ['dateTime' => $end->format(\DateTimeInterface::RFC3339), 'timeZone' => $this->timeZone],
        ];

        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        $attendees = array_values(array_unique(array_filter(
            $attendeeEmails,
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        )));

        if ($attendees !== []) {
            $payload['attendees'] = array_map(
                static fn (string $email): array => ['email' => $email],
                $attendees
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{event_id: string, meet_link: string|null, html_link: string|null}
     */
    private function extractEvent(array $event): array
    {
        $meetLink = $event['hangoutLink'] ?? null;

        if ($meetLink === null) {
            foreach ($event['conferenceData']['entryPoints'] ?? [] as $entryPoint) {
                if (($entryPoint['entryPointType'] ?? '') === 'video' && !empty($entryPoint['uri'])) {
                    $meetLink = $entryPoint['uri'];
                    break;
                }
            }
        }

        return [
            'event_id' => (string) ($event['id'] ?? ''),
            'meet_link' => $meetLink !== null ? (string) $meetLink : null,
            'html_link' => isset($event['htmlLink']) ? (string) $event['htmlLink'] : null,
        ];
    }
}
