<?php

declare(strict_types=1);

/**
 * Test di chi riceve che cosa (12/10):
 *
 *   - **l'«Iscrizione confermata» delle iscrizioni fatte dallo staff**: va
 *     in coda, non per chi aspetta l'invito, non due volte, e la spedisce
 *     `Avvisi::conferme()` (cioe' `bin/avvisi`) a scaglioni;
 *   - **la richiesta di iscrizione** va a chi puo' decidere: gli admin e i
 *     tutor dei gruppi a cui il corso e' assegnato, non tutti i tutor.
 *
 * Esecuzione:  php tests/avvisi_test.php
 *
 * ATTENZIONE: scrive davvero nel database del .env (quello di sviluppo).
 * Tutto quello che crea ha il marchio qui sotto e si cancella alla fine,
 * anche se un'asserzione fallisce. Le email non partono: le raccoglie un
 * trasporto finto.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Avvisi;
use App\Core\Database;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use App\Core\Mail\Transport;
use App\Models\EnrollmentModel;
use App\Models\UserModel;

const MARCHIO = '[prova-avvisi]';
const DOMINIO = '@prova-avvisi.invalid';

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $dettaglio = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condition && $dettaglio !== '') {
        echo '         · ' . $dettaglio . PHP_EOL;
    }
}

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo 'Database non raggiungibile: ' . $e->getMessage() . PHP_EOL;
    exit(0);
}

$pulisci = static function () use ($pdo): void {
    $pdo->prepare('DELETE FROM `groups` WHERE name LIKE :m')->execute(['m' => MARCHIO . '%']);
    $pdo->prepare('DELETE FROM courses WHERE title LIKE :m')->execute(['m' => MARCHIO . '%']);
    $pdo->prepare('DELETE FROM users WHERE email LIKE :m')->execute(['m' => '%' . DOMINIO]);
};

$pulisci();

/** Le email raccolte invece che spedite. */
final class Raccolta implements Transport
{
    /** @var list<Message> */
    public array $messaggi = [];

    public function send(Message $message): void
    {
        $this->messaggi[] = $message;
    }
}

$raccolta = new Raccolta();
Mailer::setTransport($raccolta);

$utente = static function (string $chi, string $ruolo, int $attivo = 1, int $invito = 0) use ($pdo): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, invite_pending)
                   VALUES (:e, 'x', :n, 'Prova', :r, :a, :i)")
        ->execute(['e' => $chi . DOMINIO, 'n' => ucfirst($chi), 'r' => $ruolo, 'a' => $attivo, 'i' => $invito]);

    return (int) $pdo->lastInsertId();
};

$corso = static function (string $titolo, int $pubblicato) use ($pdo): int {
    $pdo->prepare('INSERT INTO courses (title, slug, is_published, enrollment_mode, created_by)
                   VALUES (:t, :s, :p, "request", (SELECT id FROM users WHERE email = "admin@test.it"))')
        ->execute(['t' => MARCHIO . ' ' . $titolo, 's' => 'prova-avvisi-' . bin2hex(random_bytes(4)), 'p' => $pubblicato]);

    return (int) $pdo->lastInsertId();
};

$pendente = static function (int $userId, int $courseId) use ($pdo): ?int {
    $stmt = $pdo->prepare('SELECT confirmation_pending FROM enrollments WHERE user_id = :u AND course_id = :c');
    $stmt->execute(['u' => $userId, 'c' => $courseId]);
    $v = $stmt->fetchColumn();

    return $v === false ? null : (int) $v;
};

try {
    $anna = $utente('anna', 'studente');
    $bruno = $utente('bruno', 'studente', 1, 1);   // ancora in coda inviti
    $carla = $utente('carla', 'studente');
    $dario = $utente('dario', 'studente', 0);      // disattivato
    $tutorDelCorso = $utente('tutorcorso', 'tutor');
    $altroTutor = $utente('altrotutor', 'tutor');
    $adminSpento = $utente('adminspento', 'admin', 0);

    $pubblicato = $corso('pubblicato', 1);
    $bozza = $corso('in bozza', 0);

    // ---------------------------------------------------------------
    echo 'Le iscrizioni dello staff vanno in coda' . PHP_EOL;

    EnrollmentModel::enroll($anna, $pubblicato, true);
    EnrollmentModel::enroll($bruno, $pubblicato, true);
    EnrollmentModel::enroll($carla, $pubblicato);
    EnrollmentModel::enrollMany([$anna, $dario], [$bozza], true);

    check('iscritta dallo staff: la conferma e\' in coda', $pendente($anna, $pubblicato) === 1);
    check('chi aspetta l\'invito non ha la conferma in coda', $pendente($bruno, $pubblicato) === 0);
    check('un\'iscrizione dal catalogo o da una richiesta non va in coda (parte subito)', $pendente($carla, $pubblicato) === 0);

    $inCoda = array_map(static fn ($r) => (string) $r['email'], EnrollmentModel::conferme(500));
    check('fra le conferme da mandare c\'e\' la sua', in_array('anna' . DOMINIO, $inCoda, true));
    check('non quelle di un corso in bozza: partono quando si pubblica', !array_filter(
        EnrollmentModel::conferme(500),
        static fn ($r) => (int) $r['course_id'] === $bozza
    ));
    check('non quelle di un account disattivato', !in_array('dario' . DOMINIO, $inCoda, true));

    // ---------------------------------------------------------------
    echo PHP_EOL . 'bin/avvisi le manda, una volta sola' . PHP_EOL;

    $prova = Avvisi::conferme(500, true);
    check('in prova le elenca', (bool) array_filter($prova['prova'], static fn ($r) => str_starts_with($r, 'anna' . DOMINIO)));
    check('e non manda niente, e la coda resta', $raccolta->messaggi === [] && $pendente($anna, $pubblicato) === 1);

    Avvisi::conferme(500);
    $adAnna = array_values(array_filter($raccolta->messaggi, static fn (Message $m) => $m->toEmail === 'anna' . DOMINIO));
    check('ad Anna parte una conferma', count($adAnna) === 1);
    check('del corso giusto, con il solo nome', isset($adAnna[0])
        && str_contains($adAnna[0]->subject, 'pubblicato') && str_starts_with($adAnna[0]->body, "Ciao Anna,\n"));
    check('a Bruno, che aspetta l\'invito, niente', !array_filter($raccolta->messaggi, static fn (Message $m) => $m->toEmail === 'bruno' . DOMINIO));
    check('la sua conferma esce dalla coda', $pendente($anna, $pubblicato) === 0);

    $raccolta->messaggi = [];
    Avvisi::conferme(500);
    check('al giro dopo non riparte', !array_filter($raccolta->messaggi, static fn (Message $m) => $m->toEmail === 'anna' . DOMINIO));

    EnrollmentModel::enroll($anna, $pubblicato, true);
    check('iscriverla di nuovo non rimette la conferma in coda', $pendente($anna, $pubblicato) === 0);

    check('presa una volta, non si prende la seconda', (static function () use ($pdo, $anna, $bozza): bool {
        $pdo->prepare('UPDATE courses SET is_published = 1 WHERE id = :c')->execute(['c' => $bozza]);
        $riga = array_values(array_filter(EnrollmentModel::conferme(500), static fn ($r) => (int) $r['course_id'] === $bozza));

        return isset($riga[0]) && EnrollmentModel::prendiConferma((int) $riga[0]['id'])
            && !EnrollmentModel::prendiConferma((int) $riga[0]['id']);
    })());

    // ---------------------------------------------------------------
    echo PHP_EOL . 'La richiesta di iscrizione va a chi puo\' decidere' . PHP_EOL;

    $pdo->prepare('INSERT INTO `groups` (name, tutor_id) VALUES (:n, :t)')
        ->execute(['n' => MARCHIO . ' gruppo', 't' => $tutorDelCorso]);
    $gruppo = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO group_course_access (group_id, course_id) VALUES (:g, :c)')
        ->execute(['g' => $gruppo, 'c' => $pubblicato]);
    $pdo->prepare('INSERT INTO `groups` (name, tutor_id) VALUES (:n, :t)')
        ->execute(['n' => MARCHIO . ' altro gruppo', 't' => $altroTutor]);
    $pdo->prepare('INSERT INTO group_course_access (group_id, course_id) VALUES (:g, :c)')
        ->execute(['g' => (int) $pdo->lastInsertId(), 'c' => $bozza]);

    $decisori = array_map(static fn ($r) => (string) $r['email'], UserModel::decisoriRichiesta($pubblicato));
    $admin = (string) $pdo->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1")->fetchColumn();

    check('il tutor del gruppo a cui il corso e\' assegnato', in_array('tutorcorso' . DOMINIO, $decisori, true));
    check('un amministratore attivo', in_array($admin, $decisori, true));
    check('non il tutor di un gruppo con un altro corso', !in_array('altrotutor' . DOMINIO, $decisori, true));
    check('non un amministratore disattivato', !in_array('adminspento' . DOMINIO, $decisori, true));
    check('non gli studenti', !in_array('anna' . DOMINIO, $decisori, true));
} finally {
    Mailer::setTransport(null);
    $pulisci();
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
