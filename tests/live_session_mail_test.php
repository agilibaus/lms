<?php

declare(strict_types=1);

/**
 * Test degli inviti alle sessioni live: file .ics, allegati nelle email,
 * testi con segnaposto ed etichette del report delle presenze.
 *
 * Esecuzione:  php tests/live_session_mail_test.php
 *
 * La prima parte non tocca il database. L'ultima legge i testi dalla tabella
 * `settings` e, se il database non è raggiungibile, viene saltata dicendolo
 * invece di far fallire tutto.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';  // come l'applicazione: fuso orario Europe/Rome

use App\Controllers\ReportController;
use App\Core\Env;
use App\Core\Ics;
use App\Core\Mail\LiveSessionMail;
use App\Core\Mail\Message;
use App\Core\Settings;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

$sessione = [
    'id' => 7,
    'title' => 'Incontro di metà corso',
    'description' => "Portate le domande.\nSi comincia puntuali.",
    'starts_at' => '2026-10-02 18:30:00',
    'ends_at' => '2026-10-02 20:00:00',
    'meet_link' => 'https://meet.google.com/abc-defg-hij',
    'course_title' => 'Mindfulness, primo livello',
    'module_title' => 'Secondo modulo',
    'group_name' => 'Gruppo del martedì',
];

$destinatario = ['email' => 'anna.rossi@example.org', 'full_name' => 'Anna Rossi'];

// ---------------------------------------------------------------
echo 'File iCalendar' . PHP_EOL;

$ics = Ics::request(
    'sessione-live-7@lms.test',
    1000,
    'Incontro di metà corso',
    "Portate le domande.\nSi comincia puntuali.",
    'https://meet.google.com/abc-defg-hij',
    new DateTimeImmutable('2026-10-02 18:30:00'),
    new DateTimeImmutable('2026-10-02 20:00:00'),
    'no-reply@lms.test',
    'Pistacchio LMS',
    'anna.rossi@example.org',
    'Anna Rossi'
);

check('comincia e finisce come un VCALENDAR', str_starts_with($ics, "BEGIN:VCALENDAR\r\n")
    && str_ends_with($ics, "END:VCALENDAR\r\n"));
check('le righe sono separate da CRLF', !str_contains(str_replace("\r\n", '', $ics), "\n"));
check('dichiara METHOD:REQUEST', str_contains($ics, "\r\nMETHOD:REQUEST\r\n"));
check('lo stato è CONFIRMED', str_contains($ics, "\r\nSTATUS:CONFIRMED\r\n"));
check('porta il numero di revisione', str_contains($ics, "\r\nSEQUENCE:1000\r\n"));

// 18:30 a Roma in ottobre (ora legale, UTC+2) sono le 16:30 UTC.
check('gli orari sono convertiti in UTC', str_contains($ics, 'DTSTART:20261002T163000Z'));
check('anche la fine', str_contains($ics, 'DTEND:20261002T200000Z') === false
    && str_contains($ics, 'DTEND:20261002T180000Z'));
check('il link finisce in LOCATION', str_contains($ics, 'LOCATION:https://meet.google.com/abc-defg-hij'));
check('l’invitato compare con il suo indirizzo', str_contains($ics, 'mailto:anna.rossi@example.org'));
check('l’organizzatore è il mittente configurato', str_contains($ics, 'ORGANIZER;CN=Pistacchio LMS:mailto:no-reply@lms.test'));
check('gli a capo della descrizione diventano \\n', str_contains($ics, 'DESCRIPTION:Portate le domande.\\nSi comincia puntuali.'));

$annullamento = Ics::cancel(
    'sessione-live-7@lms.test',
    1001,
    'Incontro di metà corso',
    null,
    null,
    new DateTimeImmutable('2026-10-02 18:30:00'),
    new DateTimeImmutable('2026-10-02 20:00:00'),
    'no-reply@lms.test'
);

check('l’annullamento dichiara METHOD:CANCEL', str_contains($annullamento, "\r\nMETHOD:CANCEL\r\n"));
check('e STATUS:CANCELLED', str_contains($annullamento, "\r\nSTATUS:CANCELLED\r\n"));
check('senza descrizione non scrive DESCRIPTION', !str_contains($annullamento, 'DESCRIPTION:'));
check('senza link non scrive LOCATION', !str_contains($annullamento, 'LOCATION:'));
check(
    'l’identificativo resta lo stesso fra invito e annullamento',
    str_contains($ics, 'UID:sessione-live-7@lms.test')
        && str_contains($annullamento, 'UID:sessione-live-7@lms.test')
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Caratteri speciali e righe lunghe' . PHP_EOL;

$difficile = Ics::request(
    'x@y',
    1,
    'Virgola, punto e virgola; barra \\ e accenti àèìòù',
    null,
    null,
    new DateTimeImmutable('2026-10-02 18:30:00'),
    new DateTimeImmutable('2026-10-02 20:00:00'),
    'no-reply@lms.test'
);

check('la virgola è protetta', str_contains($difficile, 'Virgola\\,'));
check('il punto e virgola è protetto', str_contains($difficile, 'virgola\\;'));
check('la barra rovesciata è raddoppiata una volta sola', str_contains($difficile, 'barra \\\\ e'));

$lunga = Ics::request(
    'x@y',
    1,
    str_repeat('àèìòù ', 40),
    null,
    null,
    new DateTimeImmutable('2026-10-02 18:30:00'),
    new DateTimeImmutable('2026-10-02 20:00:00'),
    'no-reply@lms.test'
);

$righe = explode("\r\n", $lunga);
$troppoLunghe = array_filter($righe, static fn (string $r): bool => strlen($r) > 75);

check('nessuna riga supera i 75 ottetti', $troppoLunghe === []);
check('le righe piegate riprendono con uno spazio', count(array_filter(
    $righe,
    static fn (string $r): bool => str_starts_with($r, ' ')
)) > 0);
check('la piegatura non spezza i caratteri accentati', mb_check_encoding($lunga, 'UTF-8'));

$prima = Ics::sequence(1800000000);
$dopo = Ics::sequence(1800000060);
check('il numero di revisione cresce nel tempo', $dopo > $prima);
check('e resta un intero positivo ragionevole', $prima > 0 && $dopo < 2147483647);
check('l’identificativo si costruisce dall’id della sessione', Ics::uid(7, 'lms.test') === 'sessione-live-7@lms.test');

// ---------------------------------------------------------------
echo PHP_EOL . 'Email con allegato' . PHP_EOL;

$semplice = new Message('a@b.it', 'Anna', 'Oggetto', "Riga uno\nRiga due\n");
$grezzo = $semplice->toRfc822('no-reply@lms.test', 'Pistacchio LMS');

check('senza allegati il messaggio resta text/plain', str_contains($grezzo, 'Content-Type: text/plain; charset=UTF-8'));
check('senza allegati non c’è alcun confine multipart', !str_contains($grezzo, 'boundary'));
check('senza allegati hasAttachments() è falso', !$semplice->hasAttachments());

$puntato = new Message('a@b.it', 'Anna', 'Oggetto', ".punto a inizio riga\n");
check(
    'una riga che comincia con un punto resta protetta',
    str_contains($puntato->toRfc822('no-reply@lms.test', ''), "\r\n..punto a inizio riga")
);

$conAllegato = new Message(
    'a@b.it',
    'Anna',
    'Oggetto',
    "Corpo del messaggio\n",
    [['filename' => 'invito.ics', 'mimeType' => 'text/calendar; charset=UTF-8; method=REQUEST', 'content' => $ics]]
);
$grezzoAllegato = $conAllegato->toRfc822('no-reply@lms.test', 'Pistacchio LMS');

check('con un allegato il messaggio diventa multipart/mixed', str_contains($grezzoAllegato, 'Content-Type: multipart/mixed; boundary="'));
check('hasAttachments() è vero', $conAllegato->hasAttachments());

preg_match('/boundary="([^"]+)"/', $grezzoAllegato, $match);
$confine = $match[1] ?? '';

check('il confine è dichiarato una volta sola nelle intestazioni', $confine !== '');
check('e compare tre volte nel corpo (due parti più la chiusura)', substr_count($grezzoAllegato, '--' . $confine) === 3);
check('il corpo è ancora presente come testo semplice', str_contains($grezzoAllegato, 'Corpo del messaggio'));
check('l’allegato è dichiarato come calendario', str_contains($grezzoAllegato, 'Content-Type: text/calendar; charset=UTF-8; method=REQUEST; name="invito.ics"'));
check('ed è marcato come allegato con il suo nome', str_contains($grezzoAllegato, 'Content-Disposition: attachment; filename="invito.ics"'));
check('è codificato in base64', str_contains($grezzoAllegato, 'Content-Transfer-Encoding: base64'));

// Il confine non deve cambiare fra una chiamata e l'altra, altrimenti le
// intestazioni annuncerebbero un confine che nel corpo non c'è.
check('il confine resta lo stesso a ogni lettura', $conAllegato->mimeBody() === $conAllegato->mimeBody());

$parti = explode('--' . $confine, $grezzoAllegato);
$base64 = trim(substr($parti[2], (int) strpos($parti[2], "\r\n\r\n")));
check('l’allegato decodificato torna identico al file .ics', base64_decode($base64, true) === $ics);

// ---------------------------------------------------------------
echo PHP_EOL . 'Testi con segnaposto' . PHP_EOL;

$variabili = LiveSessionMail::variables($sessione, 'Anna Rossi');

check('il nome finisce nei segnaposto', $variabili['{nome}'] === 'Anna Rossi');
check('la data è in formato italiano', $variabili['{data}'] === '02/10/2026');
check('l’ora di inizio è quella locale', $variabili['{ora_inizio}'] === '18:30');
check('l’ora di fine pure', $variabili['{ora_fine}'] === '20:00');
check('il link Meet è quello della sessione', $variabili['{link_meet}'] === 'https://meet.google.com/abc-defg-hij');
check('il corso compare', $variabili['{corso}'] === 'Mindfulness, primo livello');
check('il gruppo compare', $variabili['{gruppo}'] === 'Gruppo del martedì');
check('senza orari precedenti non ci sono i segnaposto del cambio', !isset($variabili['{data_precedente}']));

$conPrecedenti = LiveSessionMail::variables(
    $sessione,
    'Anna Rossi',
    ['starts_at' => '2026-09-25 17:00:00', 'ends_at' => '2026-09-25 18:00:00']
);

check('con gli orari di prima compare la data precedente', $conPrecedenti['{data_precedente}'] === '25/09/2026');
check('e l’ora precedente', $conPrecedenti['{ora_inizio_precedente}'] === '17:00');

$reso = LiveSessionMail::render('Ciao {nome}, ci vediamo il {data} alle {ora_inizio}.', $variabili);
check('i segnaposto vengono sostituiti', $reso === "Ciao Anna Rossi, ci vediamo il 02/10/2026 alle 18:30.\n");

$sconosciuto = LiveSessionMail::render('Ciao {tiotlo}', $variabili);
check('un segnaposto che non esiste resta scritto com’è', str_contains($sconosciuto, '{tiotlo}'));

$senzaDescrizione = LiveSessionMail::variables(array_merge($sessione, ['description' => null]), 'Anna');
$vuoto = LiveSessionMail::render("Prima\n\n{descrizione}\n\nDopo", $senzaDescrizione);
check('una descrizione assente non lascia righe bianche in fila', $vuoto === "Prima\n\nDopo\n");

check('il predefinito dell’invito nomina il titolo', str_contains(LiveSessionMail::DEFAULTS['LIVE_INVITE_SUBJECT'], '{titolo}'));
check('il predefinito del cambio nomina l’orario di prima', str_contains(LiveSessionMail::DEFAULTS['LIVE_UPDATE_BODY'], '{data_precedente}'));
check('i segnaposto documentati sono quelli usati davvero', array_key_exists('{link_sessione}', LiveSessionMail::placeholders()));

// ---------------------------------------------------------------
echo PHP_EOL . 'Etichette del report delle presenze' . PHP_EOL;

check(
    'chi non si è presentato non ha ritardo',
    ReportController::delayLabel(['joined_at' => null, 'delay_minutes' => null]) === ''
);
check(
    'entrare in anticipo non è un ritardo negativo',
    ReportController::delayLabel(['joined_at' => '2026-10-02 18:25:00', 'delay_minutes' => -5]) === '0'
);
check(
    'entrare in orario dà zero',
    ReportController::delayLabel(['joined_at' => '2026-10-02 18:30:00', 'delay_minutes' => 0]) === '0'
);
check(
    'sette minuti dopo l’inizio sono sette',
    ReportController::delayLabel(['joined_at' => '2026-10-02 18:37:00', 'delay_minutes' => 7]) === '7'
);
check('una data vuota resta vuota', ReportController::dateTimeLabel(null) === '');
check(
    'la data si legge in formato italiano',
    ReportController::dateTimeLabel('2026-10-02 18:37:00') === '02/10/2026 18:37'
);
check('l’origine "platform" si legge "piattaforma"', ReportController::sourceLabel('platform') === 'piattaforma');
check('l’origine "manual" dice chi l’ha segnata', ReportController::sourceLabel('manual') === 'segnata dal tutor');
check('senza origine non si inventa niente', ReportController::sourceLabel(null) === '');

// ---------------------------------------------------------------
echo PHP_EOL . 'Messaggi completi' . PHP_EOL;

// Settings::get passa dal database; senza, Database::connection() chiude il
// processo. Si prova la connessione da qui per poterlo dire e proseguire.
$database = true;

try {
    new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_NAME', 'lms')),
        Env::get('DB_USER', 'root'),
        Env::get('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    $database = false;
}

if (!$database) {
    echo '  (saltati: database non raggiungibile)' . PHP_EOL;
} else {
    // I testi salvati dal pannello su questa installazione vanno tolti di
    // mezzo e rimessi a posto: altrimenti il test verifica i testi di Elena
    // invece di quelli predefiniti, e fallisce per il motivo sbagliato.
    $salvati = [];

    foreach (Settings::LIVE_MAIL_KEYS as $chiave) {
        $salvati[$chiave] = Settings::stored($chiave);
        Settings::set($chiave, null);
    }

    $ripristina = static function () use ($salvati): void {
        foreach ($salvati as $chiave => $valore) {
            Settings::set($chiave, $valore);
        }
    };

    register_shutdown_function($ripristina);

    $invito = LiveSessionMail::invite($sessione, $destinatario);

    check('l’invito va all’indirizzo del partecipante', $invito->toEmail === 'anna.rossi@example.org');
    check('l’oggetto è su una riga sola', !str_contains($invito->subject, "\n"));
    check('l’oggetto contiene il titolo', str_contains($invito->subject, 'Incontro di metà corso'));
    check('il corpo contiene il link Meet', str_contains($invito->body, 'https://meet.google.com/abc-defg-hij'));
    check('il corpo contiene il link alla pagina della sessione', str_contains($invito->body, '/live/7'));
    check('non restano segnaposto non sostituiti', preg_match('/\{[a-z_]+\}/', $invito->body) === 0);
    check('porta un allegato solo', count($invito->attachments) === 1);
    check('che si chiama invito.ics', $invito->attachments[0]['filename'] === 'invito.ics');
    check('con METHOD=REQUEST nel tipo', str_contains($invito->attachments[0]['mimeType'], 'method=REQUEST'));

    $cambio = LiveSessionMail::change(
        $sessione,
        $destinatario,
        ['starts_at' => '2026-09-25 17:00:00', 'ends_at' => '2026-09-25 18:00:00']
    );

    check('l’avviso di cambio dice l’orario di prima', str_contains($cambio->body, '25/09/2026'));
    check('e quello nuovo', str_contains($cambio->body, '02/10/2026'));
    check('anche qui niente segnaposto rimasti', preg_match('/\{[a-z_]+\}/', $cambio->body) === 0);

    $disdetta = LiveSessionMail::cancellation($sessione, $destinatario);

    check('l’annullamento allega annullamento.ics', $disdetta->attachments[0]['filename'] === 'annullamento.ics');
    check('con METHOD=CANCEL', str_contains($disdetta->attachments[0]['mimeType'], 'method=CANCEL'));
    check('e il file dichiara l’evento annullato', str_contains($disdetta->attachments[0]['content'], 'STATUS:CANCELLED'));

    // Con un testo salvato dal pannello vince quello, non il predefinito.
    Settings::set('LIVE_INVITE_SUBJECT', 'Su misura: {titolo}');
    $suMisura = LiveSessionMail::invite($sessione, $destinatario);
    check('un oggetto salvato dal pannello ha la precedenza', $suMisura->subject === 'Su misura: Incontro di metà corso');

    Settings::set('LIVE_INVITE_SUBJECT', null);
    $tornato = LiveSessionMail::invite($sessione, $destinatario);
    check('svuotandolo torna quello predefinito', str_contains($tornato->subject, 'Invito:'));

    $ripristina();
}

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
