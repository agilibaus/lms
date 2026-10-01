<?php

declare(strict_types=1);

/**
 * Semina i dati su cui gira la verifica dei permessi.
 *
 * Esecuzione:  php tests/semina_permessi.php
 * Stampa su stdout un JSON con gli identificativi creati; lo legge
 * `tests/permessi.js`, che non deve indovinare nessun numero.
 *
 * PERCHE' SERVONO DUE MONDI. Un controllo sui permessi ha senso solo se c'e'
 * qualcosa che non si deve poter toccare. Con un corso solo e un tutor solo
 * non c'e' niente da violare: ogni pagina e' legittimamente di chi la apre,
 * e il controllo direbbe sempre di si' senza aver verificato niente.
 *
 * Quindi si creano **due mondi paralleli e simmetrici**, A e B, con
 * proprietari diversi:
 *
 *   mondo A   corso, gruppo (tutor 1), modulo, lezione, quiz, domanda,
 *             incontro dal vivo, e uno studente iscritto solo ad A
 *   mondo B   gli stessi oggetti, ma gruppo del tutor 2 e un altro studente
 *
 * La domanda a cui risponde la verifica e' allora precisa: lo studente di A
 * riesce ad aprire la lezione di B scrivendone l'indirizzo? Il tutor di A
 * riesce a cancellare la domanda di B?
 *
 * SI RISEMINA A OGNI GIRO, cancellando prima quello che c'era. Due ragioni:
 * i controlli devono partire da uno stato noto, e fra le prove ce ne sono
 * alcune che **provano a cancellare** — se una di quelle riuscisse, cioe' se
 * trovasse un difetto vero, il giro successivo ripartirebbe comunque intero.
 *
 * ATTENZIONE: tocca il database dell'installazione su cui gira. E' pensato
 * per il container di sviluppo, non per la produzione. Per non fare danni
 * cancella **solo** le righe che ha creato lui, riconoscibili dal prefisso
 * nel titolo.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;

/** Prefisso che marca tutto cio' che appartiene a questa semina. */
const MARCHIO = '[prova-permessi]';

$pdo = Database::connection();

// --- utenti: devono esistere gia', sono quelli dell'installazione di prova --
function utente(PDO $pdo, string $email): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        fwrite(STDERR, "Manca l'utente $email: la semina ha bisogno degli utenti di prova.\n");
        exit(1);
    }

    return (int) $id;
}

$admin = utente($pdo, 'admin@test.it');
$tutorA = utente($pdo, 'tutor1@test.it');
$tutorB = utente($pdo, 'tutor2@test.it');
$assistente = utente($pdo, 'assist@test.it');
$studenteA = utente($pdo, 'stud@test.it');
$studenteB = utente($pdo, 'strano@test.it');

// --- pulizia di quello che c'era ------------------------------------------
//
// L'ordine conta solo dove non c'e' una cascata: i corsi si portano dietro
// moduli, lezioni, quiz e iscrizioni, i gruppi i propri membri.

$pdo->prepare('DELETE FROM live_sessions WHERE title LIKE :m')->execute(['m' => MARCHIO . '%']);
$pdo->prepare('DELETE FROM courses WHERE title LIKE :m')->execute(['m' => MARCHIO . '%']);
$pdo->prepare('DELETE FROM `groups` WHERE name LIKE :m')->execute(['m' => MARCHIO . '%']);

// --- un mondo ---------------------------------------------------------------

/**
 * @return array<string, int>
 */
function mondo(PDO $pdo, string $lettera, int $tutor, int $studente, int $admin): array
{
    $nome = MARCHIO . ' mondo ' . $lettera;

    $pdo->prepare('INSERT INTO courses (title, slug, description, is_published, enrollment_mode, created_by)
                   VALUES (:t, :s, :d, 1, "open", :a)')
        ->execute([
            't' => $nome,
            's' => 'prova-permessi-' . strtolower($lettera) . '-' . bin2hex(random_bytes(4)),
            'd' => 'Corso creato dalla verifica dei permessi.',
            'a' => $admin,
        ]);
    $corso = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO `groups` (name, tutor_id) VALUES (:n, :t)')
        ->execute(['n' => $nome, 't' => $tutor]);
    $gruppo = (int) $pdo->lastInsertId();

    // E' questo legame — gruppo del tutor → corso — a decidere che cosa un
    // tutor puo' modificare (`CourseRights::tutorCourseIds`).
    $pdo->prepare('INSERT INTO group_course_access (group_id, course_id) VALUES (:g, :c)')
        ->execute(['g' => $gruppo, 'c' => $corso]);

    $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:g, :u)')
        ->execute(['g' => $gruppo, 'u' => $studente]);

    $pdo->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (:u, :c)')
        ->execute(['u' => $studente, 'c' => $corso]);

    $pdo->prepare('INSERT INTO modules (course_id, title, position) VALUES (:c, :t, 0)')
        ->execute(['c' => $corso, 't' => $nome . ' modulo']);
    $modulo = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO lessons (module_id, title, content_html, position)
                   VALUES (:m, :t, :h, 0)')
        ->execute(['m' => $modulo, 't' => $nome . ' lezione', 'h' => '<p>Contenuto.</p>']);
    $lezione = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO quizzes (module_id, title, passing_score_pct) VALUES (:m, :t, 60)')
        ->execute(['m' => $modulo, 't' => $nome . ' quiz']);
    $quiz = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, question_type, position)
                   VALUES (:q, :t, "single_choice", 0)')
        ->execute(['q' => $quiz, 't' => 'Domanda del mondo ' . $lettera . '?']);
    $domanda = (int) $pdo->lastInsertId();

    foreach ([['Giusta', 1], ['Sbagliata', 0]] as $i => [$testo, $giusta]) {
        $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, position)
                       VALUES (:d, :t, :c, :p)')
            ->execute(['d' => $domanda, 't' => $testo, 'c' => $giusta, 'p' => $i]);
    }

    $pdo->prepare('INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, meet_link, created_by)
                   VALUES (:m, :g, :t, DATE_ADD(NOW(), INTERVAL 3 DAY),
                           DATE_ADD(NOW(), INTERVAL 3 DAY) + INTERVAL 60 MINUTE,
                           "https://meet.google.com/prova-permessi", :a)')
        ->execute(['m' => $modulo, 'g' => $gruppo, 't' => $nome . ' incontro', 'a' => $admin]);
    $incontro = (int) $pdo->lastInsertId();

    return [
        'corso' => $corso,
        'gruppo' => $gruppo,
        'modulo' => $modulo,
        'lezione' => $lezione,
        'quiz' => $quiz,
        'domanda' => $domanda,
        'incontro' => $incontro,
        'studente' => $studente,
        'tutor' => $tutor,
    ];
}

$a = mondo($pdo, 'A', $tutorA, $studenteA, $admin);
$b = mondo($pdo, 'B', $tutorB, $studenteB, $admin);

// L'assistente segue il tutor di A e **non** quello di B: e' cosi' che si
// vede se il suo perimetro e' davvero quello del proprio tutor.
$pdo->prepare('DELETE FROM assistant_tutors WHERE assistant_id = :a')->execute(['a' => $assistente]);
$pdo->prepare('INSERT INTO assistant_tutors (assistant_id, tutor_id) VALUES (:a, :t)')
    ->execute(['a' => $assistente, 't' => $tutorA]);

echo json_encode([
    'utenti' => [
        'admin' => $admin,
        'tutorA' => $tutorA,
        'tutorB' => $tutorB,
        'assistente' => $assistente,
        'studenteA' => $studenteA,
        'studenteB' => $studenteB,
    ],
    'A' => $a,
    'B' => $b,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
