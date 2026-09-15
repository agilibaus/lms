<?php

declare(strict_types=1);

/**
 * Test del client Google Meet/Calendar, senza rete e senza credenziali reali:
 * la JWT viene verificata con la chiave pubblica corrispondente e le risposte
 * di Google sono simulate da un trasporto finto.
 *
 * Esecuzione:  php tests/google_meet_test.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';  // come l'applicazione: fuso orario Europe/Rome

use App\Core\Google\GoogleException;
use App\Core\Google\HttpTransport;
use App\Core\Google\MeetCalendar;
use App\Core\Google\ServiceAccountClient;

/** Trasporto finto: registra le richieste e restituisce risposte preconfezionate. */
class FakeTransport implements HttpTransport
{
    public array $requests = [];

    /** @param array<int, array{status:int, body:string}> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        return array_shift($this->responses) ?? ['status' => 500, 'body' => '{"error":{"message":"nessuna risposta preparata"}}'];
    }
}

// Chiave RSA generata al volo: il test non richiede credenziali Google reali.
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $privateKey);

$credentials = [
    'client_email' => 'lms@progetto.iam.gserviceaccount.com',
    'private_key' => $privateKey,
];
$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

// ---------------------------------------------------------------
echo "JWT assertion" . PHP_EOL;

$client = new ServiceAccountClient(
    $credentials['client_email'],
    $credentials['private_key'],
    'corsi@movimente.it',
    new FakeTransport([])
);

$assertion = $client->buildAssertion(MeetCalendar::SCOPE, 1_780_000_000);
[$h, $p, $s] = explode('.', $assertion);
$header = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
$claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

check('tre segmenti', count(explode('.', $assertion)) === 3);
check('alg RS256', $header['alg'] === 'RS256');
check('iss = service account', $claims['iss'] === $credentials['client_email']);
check('sub = utente impersonato (delega)', $claims['sub'] === 'corsi@movimente.it');
check('aud = endpoint token', $claims['aud'] === 'https://oauth2.googleapis.com/token');
check('scope calendar', $claims['scope'] === MeetCalendar::SCOPE);
check('exp = iat + 3600', $claims['exp'] - $claims['iat'] === 3600);

// La firma deve verificare con la chiave pubblica corrispondente.
$publicKey = openssl_pkey_get_details(openssl_pkey_get_private($credentials['private_key']))['key'];
$verified = openssl_verify(
    $h . '.' . $p,
    base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)),
    $publicKey,
    OPENSSL_ALGO_SHA256
);
check('firma RS256 verificata con la chiave pubblica', $verified === 1);

// Senza impersonazione non deve comparire "sub".
$noDelegation = new ServiceAccountClient($credentials['client_email'], $credentials['private_key'], null, new FakeTransport([]));
$claimsNoSub = json_decode(base64_decode(strtr(explode('.', $noDelegation->buildAssertion('scope'))[1], '-_', '+/')), true);
check('senza delega nessun claim sub', !isset($claimsNoSub['sub']));

// ---------------------------------------------------------------
echo PHP_EOL . "Creazione evento con Meet" . PHP_EOL;

$transport = new FakeTransport([
    ['status' => 200, 'body' => json_encode(['access_token' => 'token-finto', 'expires_in' => 3600])],
    ['status' => 200, 'body' => json_encode([
        'id' => 'evt_abc123',
        'htmlLink' => 'https://calendar.google.com/event?eid=abc',
        'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
    ])],
]);

$calendar = new MeetCalendar(
    new ServiceAccountClient($credentials['client_email'], $credentials['private_key'], 'corsi@movimente.it', $transport),
    'corsi@movimente.it',
    'Europe/Rome'
);

$event = $calendar->createEvent(
    'Incontro settimanale',
    'Pratica guidata',
    new DateTimeImmutable('2026-09-20 18:30:00'),
    new DateTimeImmutable('2026-09-20 19:30:00'),
    ['sara@example.com', 'marco@example.com', 'non-una-email']
);

check('event_id restituito', $event['event_id'] === 'evt_abc123');
check('meet_link restituito', $event['meet_link'] === 'https://meet.google.com/abc-defg-hij');

$tokenRequest = $transport->requests[0];
check('prima chiamata al token endpoint', $tokenRequest['url'] === 'https://oauth2.googleapis.com/token');
check('grant_type jwt-bearer', str_contains($tokenRequest['body'], 'urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer'));

$eventRequest = $transport->requests[1];
$payload = json_decode($eventRequest['body'], true);
check('POST su events del calendario', str_contains($eventRequest['url'], '/calendars/corsi%40movimente.it/events'));
check('conferenceDataVersion=1', str_contains($eventRequest['url'], 'conferenceDataVersion=1'));
check('Authorization con il token ottenuto', $eventRequest['headers']['Authorization'] === 'Bearer token-finto');
check('richiesta conferenza hangoutsMeet', $payload['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet');
check('requestId presente', !empty($payload['conferenceData']['createRequest']['requestId']));
check('fuso orario Europe/Rome', $payload['start']['timeZone'] === 'Europe/Rome');
check('inizio in RFC3339', $payload['start']['dateTime'] === '2026-09-20T18:30:00+02:00');
check('due invitati validi (email malformata scartata)', count($payload['attendees']) === 2);

// Il token viene riusato: la seconda chiamata non ripassa dal token endpoint.
$transport2 = new FakeTransport([
    ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 3600])],
    ['status' => 200, 'body' => json_encode(['id' => 'e1', 'hangoutLink' => 'https://meet.google.com/a'])],
    ['status' => 200, 'body' => json_encode(['id' => 'e2', 'hangoutLink' => 'https://meet.google.com/b'])],
]);
$calendar2 = new MeetCalendar(new ServiceAccountClient($credentials['client_email'], $credentials['private_key'], null, $transport2));
$calendar2->createEvent('A', null, new DateTimeImmutable('2026-09-20 10:00'), new DateTimeImmutable('2026-09-20 11:00'));
$calendar2->createEvent('B', null, new DateTimeImmutable('2026-09-21 10:00'), new DateTimeImmutable('2026-09-21 11:00'));
check('token riusato (3 chiamate, non 4)', count($transport2->requests) === 3);

// ---------------------------------------------------------------
echo PHP_EOL . "Gestione errori" . PHP_EOL;

$errorTransport = new FakeTransport([
    ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 3600])],
    ['status' => 403, 'body' => json_encode(['error' => ['message' => 'Insufficient Permission']])],
]);
$calendar3 = new MeetCalendar(new ServiceAccountClient($credentials['client_email'], $credentials['private_key'], null, $errorTransport));

try {
    $calendar3->createEvent('X', null, new DateTimeImmutable('2026-09-20 10:00'), new DateTimeImmutable('2026-09-20 11:00'));
    check('403 solleva GoogleException', false);
} catch (GoogleException $e) {
    check('403 solleva GoogleException', true);
    check('messaggio leggibile', str_contains($e->getMessage(), 'Insufficient Permission'));
}

$authError = new FakeTransport([
    ['status' => 400, 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature'])],
]);
$calendar4 = new MeetCalendar(new ServiceAccountClient($credentials['client_email'], $credentials['private_key'], null, $authError));

try {
    $calendar4->createEvent('X', null, new DateTimeImmutable('2026-09-20 10:00'), new DateTimeImmutable('2026-09-20 11:00'));
    check('errore di autenticazione intercettato', false);
} catch (GoogleException $e) {
    check('errore di autenticazione intercettato', str_contains($e->getMessage(), 'Invalid JWT Signature'));
}

// Evento gia' cancellato: 404 non deve essere un errore.
$deleteTransport = new FakeTransport([
    ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 3600])],
    ['status' => 404, 'body' => json_encode(['error' => ['message' => 'Not Found']])],
]);
$calendar5 = new MeetCalendar(new ServiceAccountClient($credentials['client_email'], $credentials['private_key'], null, $deleteTransport));

try {
    $calendar5->deleteEvent('gia-rimosso');
    check('delete di un evento inesistente non solleva errore', true);
} catch (GoogleException $e) {
    check('delete di un evento inesistente non solleva errore', false);
}

// ---------------------------------------------------------------
echo PHP_EOL . "Link Meet da entryPoints (risposta senza hangoutLink)" . PHP_EOL;

$entryTransport = new FakeTransport([
    ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 3600])],
    ['status' => 200, 'body' => json_encode([
        'id' => 'evt_x',
        'conferenceData' => ['entryPoints' => [
            ['entryPointType' => 'phone', 'uri' => 'tel:+3912345'],
            ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/xyz-abcd-efg'],
        ]],
    ])],
]);
$calendar6 = new MeetCalendar(new ServiceAccountClient($credentials['client_email'], $credentials['private_key'], null, $entryTransport));
$event6 = $calendar6->createEvent('Y', null, new DateTimeImmutable('2026-09-20 10:00'), new DateTimeImmutable('2026-09-20 11:00'));
check('link video estratto da entryPoints', $event6['meet_link'] === 'https://meet.google.com/xyz-abcd-efg');

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
