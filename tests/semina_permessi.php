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

// Uno studente che non ha mai fatto accesso.
//
// PERCHE' UN UTENTE APPOSTA. Il benvenuto si mostra a chi ha la casella
// `welcome_seen_at` vuota, e gli altri utenti di prova non possono averla
// vuota: verrebbero dirottati alla pagina del benvenuto a ogni richiesta e
// non proverebbero piu' niente di quello per cui esistono. Questo invece
// nasce vergine a ogni semina ed e' l'unico su cui si prova il primo
// accesso. Lo crea la semina se non c'e': e' roba sua, non una delle sei
// utenze che il pacchetto si aspetta di trovare.
$pdo->prepare(
    'INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at)
     VALUES (:e, :p, :n, :c, "studente", 1, NOW())
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = 1'
)->execute([
    'e' => 'nuovo@test.it',
    'p' => password_hash('Password1!', PASSWORD_DEFAULT),
    // Nome e cognome separati dal 07/10: `full_name` e' calcolato.
    'n' => MARCHIO,
    'c' => 'studente al primo accesso',
]);
$studenteNuovo = utente($pdo, 'nuovo@test.it');

// La casella torna vuota a ogni semina, cosi' la prova del primo accesso
// si puo' ripetere.
$pdo->prepare('UPDATE users SET welcome_seen_at = NULL, must_change_password = 0 WHERE id = :id')
    ->execute(['id' => $studenteNuovo]);

// I due studenti di prova hanno gia' visto il video di benvenuto.
//
// Senza questa riga uno studente con la casella vuota verrebbe dirottato
// alla pagina del benvenuto a ogni richiesta, e **tutte** le prove che
// aprono una pagina da studente finirebbero li' invece che dove dovevano:
// non sarebbe un difetto del benvenuto, ma una semina che consegna utenti
// in uno stato ambiguo.
//
// LO STAFF RESTA CON LA CASELLA VUOTA, ED E' VOLUTO. Il benvenuto non si
// mostra allo staff per via del **ruolo**, non perche' l'abbia gia' visto:
// se lo si segnasse come visto, la prova «l'amministratore non viene
// portato al benvenuto» sarebbe verde anche togliendo la regola sul ruolo,
// cioe' verde per il motivo sbagliato. Verificato: con lo staff a NULL,
// togliere quella regola fa diventare rosse tutte e tre quelle prove.
$pdo->prepare('UPDATE users SET welcome_seen_at = NOW()
                WHERE welcome_seen_at IS NULL AND id IN (:sa, :sb)')
    ->execute(['sa' => $studenteA, 'sb' => $studenteB]);

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

    // --- i casi che i controlli di accessibilita' non vedevano ------------
    //
    // Il 06/10, montato l'ambiente con il database vero di Elena, sono usciti
    // sette difetti che con i dati di prova non comparivano mai: le pagine
    // che li avrebbero mostrati erano vuote, o mostravano un altro caso. Le
    // righe qui sotto li rendono visibili anche a chi non ha quel database,
    // e `accessibilita.js` apre le pagine con gli id che questa semina
    // restituisce invece di sperare che l'id 1 sia il caso giusto.

    // Una domanda vero/falso: e' il solo tipo il cui modulo di modifica
    // scriveva «Vero» e «Falso» fuori da un'etichetta.
    $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, question_type, position)
                   VALUES (:q, :t, "true_false", 1)')
        ->execute(['q' => $quiz, 't' => 'Affermazione del mondo ' . $lettera . '.']);
    $domandaVeroFalso = (int) $pdo->lastInsertId();

    foreach ([['Vero', 1], ['Falso', 0]] as $i => [$testo, $giusta]) {
        $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, position)
                       VALUES (:d, :t, :c, :p)')
            ->execute(['d' => $domandaVeroFalso, 't' => $testo, 'c' => $giusta, 'p' => $i]);
    }

    // Un materiale su una lezione **aperta**: quello del modulo chiuso non si
    // vede nella pagina della lezione, e il nome del file e' un comando a se'
    // che deve essere alto almeno 24 px.
    $materialeFile = __DIR__ . '/../storage/materials/prova-permessi.pdf';

    if (!is_dir(dirname($materialeFile))) {
        mkdir(dirname($materialeFile), 0775, true);
    }

    if (!is_file($materialeFile)) {
        file_put_contents($materialeFile, "%PDF-1.4\n% file di prova della verifica dei permessi\n");
    }

    $pdo->prepare('INSERT INTO lesson_materials (lesson_id, file_name, file_path, file_type, file_size_bytes)
                   VALUES (:l, "dispensa del mondo.pdf", "materials/prova-permessi.pdf", "application/pdf", :s)')
        ->execute(['l' => $lezione, 's' => filesize($materialeFile)]);
    $materiale = (int) $pdo->lastInsertId();

    // Un certificato emesso allo studente: senza, l'elenco dei certificati e
    // la colonna del report per gruppo restano vuoti, e una tabella vuota
    // non sfora mai. Si cancella con il corso, per cascata.
    $pdo->prepare('INSERT INTO certificates (user_id, course_id, certificate_code, file_path, issued_by)
                   VALUES (:u, :c, :k, "certificates/prova-permessi.pdf", :a)')
        ->execute([
            'u' => $studente,
            'c' => $corso,
            'k' => 'PROVA-' . $lettera . '-' . strtoupper(bin2hex(random_bytes(6))),
            'a' => $admin,
        ]);
    $certificato = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, meet_link, created_by)
                   VALUES (:m, :g, :t, DATE_ADD(NOW(), INTERVAL 3 DAY),
                           DATE_ADD(NOW(), INTERVAL 3 DAY) + INTERVAL 60 MINUTE,
                           "https://meet.google.com/prova-permessi", :a)')
        ->execute(['m' => $modulo, 'g' => $gruppo, 't' => $nome . ' incontro', 'a' => $admin]);
    $incontro = (int) $pdo->lastInsertId();

    // Un incontro gia' concluso, che nell'agenda finisce nello Storico.
    // Serve a provare che li' non si offra di metterlo in calendario: con
    // soli incontri futuri non ci sarebbe nessuno Storico da guardare.
    $pdo->prepare('INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, meet_link, created_by)
                   VALUES (:m, :g, :t, DATE_SUB(NOW(), INTERVAL 2 DAY),
                           DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 60 MINUTE,
                           "https://meet.google.com/prova-permessi-concluso", :a)')
        ->execute(['m' => $modulo, 'g' => $gruppo, 't' => $nome . ' incontro concluso', 'a' => $admin]);
    $incontroConcluso = (int) $pdo->lastInsertId();

    // Un incontro dentro alla finestra d'ingresso: comincia fra cinque
    // minuti. Serve a provare che «Entra» compaia quando deve — con soli
    // incontri lontani la prova sarebbe verde anche se il comando non
    // comparisse mai.
    $pdo->prepare('INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, meet_link, created_by)
                   VALUES (:m, :g, :t, DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                           DATE_ADD(NOW(), INTERVAL 65 MINUTE),
                           "https://meet.google.com/prova-permessi-imminente", :a)')
        ->execute(['m' => $modulo, 'g' => $gruppo, 't' => $nome . ' incontro imminente', 'a' => $admin]);
    $incontroImminente = (int) $pdo->lastInsertId();

    // --- un secondo modulo, chiuso da una data nel futuro ------------------
    //
    // Serve al rilascio progressivo (§8.7). Senza un modulo chiuso non c'e'
    // niente da provare a scavalcare: con uno, si puo' chiedere allo
    // studente iscritto — che quel corso lo puo' aprire legittimamente — di
    // raggiungerne la lezione, il quiz, il materiale e l'incontro scrivendo
    // l'indirizzo. Sono quattro ingressi distinti, e il controllo va in
    // ciascuno.
    //
    // La data e' relativa a NOW() e non scritta a mano: un file di prova con
    // dentro "2027-01-01" smette di provare quello che prova nel 2027.

    $pdo->prepare('INSERT INTO modules (course_id, title, position, available_from)
                   VALUES (:c, :t, 1, DATE_ADD(NOW(), INTERVAL 30 DAY))')
        ->execute(['c' => $corso, 't' => $nome . ' modulo chiuso']);
    $moduloChiuso = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO lessons (module_id, title, content_html, position)
                   VALUES (:m, :t, :h, 0)')
        ->execute([
            'm' => $moduloChiuso,
            't' => $nome . ' lezione chiusa',
            'h' => '<p>Contenuto non ancora disponibile.</p>',
        ]);
    $lezioneChiusa = (int) $pdo->lastInsertId();

    // **Il file deve esistere davvero sul disco.** Senza, la richiesta
    // arriverebbe in fondo e risponderebbe 404 per il file mancante: un
    // rifiuto che il controllo conterebbe come buono, mentre del permesso
    // non avrebbe provato niente. E' un verde falso, ed e' stato trovato
    // proprio rompendo di proposito la regola della data per vedere se il
    // controllo diventava rosso: tre prove su quattro lo diventavano,
    // questa no.
    $materialeFile = __DIR__ . '/../storage/materials/prova-permessi.pdf';

    if (!is_dir(dirname($materialeFile))) {
        mkdir(dirname($materialeFile), 0775, true);
    }

    if (!is_file($materialeFile)) {
        file_put_contents($materialeFile, "%PDF-1.4\n% file di prova della verifica dei permessi\n");
    }

    $pdo->prepare('INSERT INTO lesson_materials (lesson_id, file_name, file_path, file_type, file_size_bytes)
                   VALUES (:l, "dispensa.pdf", "materials/prova-permessi.pdf", "application/pdf", :s)')
        ->execute(['l' => $lezioneChiusa, 's' => filesize($materialeFile)]);
    $materialeChiuso = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO quizzes (module_id, title, passing_score_pct) VALUES (:m, :t, 60)')
        ->execute(['m' => $moduloChiuso, 't' => $nome . ' quiz chiuso']);
    $quizChiuso = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, meet_link, created_by)
                   VALUES (:m, :g, :t, DATE_ADD(NOW(), INTERVAL 31 DAY),
                           DATE_ADD(NOW(), INTERVAL 31 DAY) + INTERVAL 60 MINUTE,
                           "https://meet.google.com/prova-permessi-chiuso", :a)')
        ->execute(['m' => $moduloChiuso, 'g' => $gruppo, 't' => $nome . ' incontro chiuso', 'a' => $admin]);
    $incontroChiuso = (int) $pdo->lastInsertId();

    return [
        'corso' => $corso,
        'gruppo' => $gruppo,
        'modulo' => $modulo,
        'lezione' => $lezione,
        'quiz' => $quiz,
        'domanda' => $domanda,
        'domanda_vero_falso' => $domandaVeroFalso,
        'materiale' => $materiale,
        'certificato' => $certificato,
        'incontro' => $incontro,
        'incontro_concluso' => $incontroConcluso,
        'incontro_imminente' => $incontroImminente,
        'studente' => $studente,
        'tutor' => $tutor,
        'modulo_chiuso' => $moduloChiuso,
        'lezione_chiusa' => $lezioneChiusa,
        'materiale_chiuso' => $materialeChiuso,
        'quiz_chiuso' => $quizChiuso,
        'incontro_chiuso' => $incontroChiuso,
    ];
}

$a = mondo($pdo, 'A', $tutorA, $studenteA, $admin);
$b = mondo($pdo, 'B', $tutorB, $studenteB, $admin);

// L'assistente segue il tutor di A e **non** quello di B: e' cosi' che si
// vede se il suo perimetro e' davvero quello del proprio tutor.
$pdo->prepare('DELETE FROM assistant_tutors WHERE assistant_id = :a')->execute(['a' => $assistente]);
$pdo->prepare('INSERT INTO assistant_tutors (assistant_id, tutor_id) VALUES (:a, :t)')
    ->execute(['a' => $assistente, 't' => $tutorA]);

// --- le foto del profilo e la pagina del gruppo (06/10) --------------------
//
// Chi vede la foto di chi lo decide `GroupPeers`. Per provarlo servono foto
// **vere sul disco**: senza file la richiesta risponderebbe 404 per la foto
// mancante, cioe' un rifiuto che il controllo conterebbe come buono senza
// aver provato la regola — lo stesso verde falso del materiale chiuso. Per
// questo ce l'hanno lo studente di A, lo studente di B e il tutor di A, e
// l'admin fa la controprova sulla foto di B.

function fotoDiProva(PDO $pdo, int $userId, array $colore): void
{
    $relativo = 'avatars/' . $userId . '/prova-permessi.png';
    $assoluto = __DIR__ . '/../storage/' . $relativo;

    if (!is_dir(dirname($assoluto))) {
        mkdir(dirname($assoluto), 0775, true);
    }

    if (!is_file($assoluto)) {
        $img = imagecreatetruecolor(96, 96);
        imagefill($img, 0, 0, imagecolorallocate($img, $colore[0], $colore[1], $colore[2]));
        imagepng($img, $assoluto);
        imagedestroy($img);
    }

    $pdo->prepare('UPDATE users SET avatar_path = :p WHERE id = :id')
        ->execute(['p' => $relativo, 'id' => $userId]);
}

fotoDiProva($pdo, $studenteA, [79, 114, 86]);
fotoDiProva($pdo, $studenteB, [160, 90, 60]);
fotoDiProva($pdo, $tutorA, [70, 90, 140]);

// Le presentazioni (06/10). Il tutor e lo studente di A ne hanno una, e una
// la ha lo studente di B: e' quella che la pagina del gruppo di A non deve
// mostrare. Le frasi sono riconoscibili apposta, perche' `permessi.js` le
// cerca nel testo della pagina.
$presentazione = $pdo->prepare('UPDATE users SET bio = :b WHERE id = :id');
$presentazione->execute(['id' => $tutorA, 'b' => "Presentazione del tutor del mondo A.\nSeconda riga, dopo un a capo."]);
$presentazione->execute(['id' => $studenteA, 'b' => 'Presentazione dello studente del mondo A.']);
$presentazione->execute(['id' => $studenteB, 'b' => 'Presentazione dello studente del mondo B, che A non deve leggere.']);

// Il benvenuto del tutor (07/10): uno per il tutor di ciascun mondo, nel
// suo corso, con file veri sul disco — una foto verticale e un audio. Come
// per le foto del profilo: senza file, un rifiuto sarebbe verde per il
// motivo sbagliato. L'audio non e' un MP3 che si possa ascoltare: ai
// controlli serve che esista e che arrivi a chi deve.
function benvenutoDiProva(PDO $pdo, int $corso, int $tutor, string $lettera): int
{
    $cartella = __DIR__ . '/../storage/welcomes/' . $corso;

    if (!is_dir($cartella)) {
        mkdir($cartella, 0775, true);
    }

    $foto = 'welcomes/' . $corso . '/prova-permessi.jpg';
    $img = imagecreatetruecolor(300, 400);
    imagefill($img, 0, 0, imagecolorallocate($img, 217, 223, 217));
    imagefilledellipse($img, 150, 150, 140, 140, imagecolorallocate($img, 169, 181, 170));
    imagefilledellipse($img, 150, 420, 260, 300, imagecolorallocate($img, 169, 181, 170));
    imagejpeg($img, __DIR__ . '/../storage/' . $foto, 85);
    imagedestroy($img);

    $audio = 'welcomes/' . $corso . '/prova-permessi.mp3';
    file_put_contents(__DIR__ . '/../storage/' . $audio, "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\x00", 2048));

    $pdo->prepare('INSERT INTO course_tutor_welcomes (course_id, tutor_id, photo_path, audio_path, transcript)
                   VALUES (:c, :t, :p, :a, :x)')
        ->execute([
            'c' => $corso,
            't' => $tutor,
            'p' => $foto,
            'a' => $audio,
            'x' => "Benvenuti nel corso del mondo " . $lettera . ".\nSono il vostro tutor.",
        ]);

    return (int) $pdo->lastInsertId();
}

$a['benvenuto'] = benvenutoDiProva($pdo, $a['corso'], $tutorA, 'A');

// Una copertina e una descrizione al corso del mondo A (07/10): la testa
// della pagina del corso — copertina a fascia, descrizione, benvenuto — si
// controlla solo se c'e' tutta. Prima i blocchi avevano quattro larghezze
// diverse e nessun controllo lo vedeva, perche' il corso di prova non aveva
// copertina.
$copertina = 'course-covers/prova-permessi.jpg';
if (!is_dir(__DIR__ . '/../storage/course-covers')) {
    mkdir(__DIR__ . '/../storage/course-covers', 0775, true);
}
$img = imagecreatetruecolor(1600, 900);
imagefill($img, 0, 0, imagecolorallocate($img, 79, 114, 86));
imagejpeg($img, __DIR__ . '/../storage/' . $copertina, 80);
imagedestroy($img);
$pdo->prepare('UPDATE courses SET cover_image = :c, description = :d WHERE id = :id')
    ->execute(['c' => $copertina, 'd' => 'Il corso di prova del mondo A, con una descrizione di una riga.', 'id' => $a['corso']]);
$b['benvenuto'] = benvenutoDiProva($pdo, $b['corso'], $tutorB, 'B');

// Le domande (07/10): in ciascun corso una pubblicata sul primo modulo, una
// pubblicata sul corso in generale, una in attesa assegnata al tutor del
// mondo e una scartata. L'archivio, la pagina «Domande» e i permessi hanno
// sempre qualcosa da mostrare e da negare.
function domandeDiProva(PDO $pdo, array $mondo, int $studente, int $tutor, string $lettera): void
{
    $ins = $pdo->prepare(
        'INSERT INTO course_questions (course_id, module_id, student_id, tutor_id, question, answer, status, answered_by, answered_at)
         VALUES (:c, :m, :s, :t, :q, :a, :st, :by, :at)'
    );
    $righe = [
        [$mondo['modulo'], 'Domanda pubblicata sul modulo del mondo ' . $lettera . '?', 'Risposta del tutor del mondo ' . $lettera . '.', 'published'],
        [null, 'Domanda pubblicata sul corso in generale del mondo ' . $lettera . '?', 'Risposta generale del mondo ' . $lettera . '.', 'published'],
        [$mondo['modulo'], 'Domanda in attesa del mondo ' . $lettera . '?', null, 'pending'],
        [null, 'Domanda scartata del mondo ' . $lettera . '?', null, 'discarded'],
    ];
    foreach ($righe as [$modulo, $domanda, $risposta, $stato]) {
        $ins->execute([
            'c' => $mondo['corso'], 'm' => $modulo, 's' => $studente, 't' => $tutor, 'q' => $domanda, 'a' => $risposta,
            'st' => $stato, 'by' => $stato === 'pending' ? null : $tutor, 'at' => $stato === 'pending' ? null : date('Y-m-d H:i:s'),
        ]);
        if ($stato === 'pending') {
            $GLOBALS['domandaInAttesa'][$lettera] = (int) $pdo->lastInsertId();
        }
        if ($stato === 'published' && $modulo !== null) {
            $GLOBALS['domandaPubblicata'][$lettera] = (int) $pdo->lastInsertId();
        }
    }
}

domandeDiProva($pdo, $a, $studenteA, $tutorA, 'A');
domandeDiProva($pdo, $b, $studenteB, $tutorB, 'B');
$a['domanda_in_attesa'] = $GLOBALS['domandaInAttesa']['A'];
$b['domanda_in_attesa'] = $GLOBALS['domandaInAttesa']['B'];
$a['domanda_pubblicata'] = $GLOBALS['domandaPubblicata']['A'];
$b['domanda_pubblicata'] = $GLOBALS['domandaPubblicata']['B'];

// I contatti del benvenuto (07/10): il tutor di A ha l'email per gli
// studenti e il gruppo di A il link WhatsApp; il tutor di B e il suo gruppo
// no, e nel loro benvenuto non deve comparire niente. Si riscrivono a ogni
// semina, cosi' un controllo che li cambia non lascia tracce al giro dopo.
$pdo->prepare('UPDATE users SET contact_email = :e WHERE id = :id')
    ->execute(['e' => 'tutor.uno.studenti@example.invalid', 'id' => $tutorA]);
$pdo->prepare('UPDATE users SET contact_email = NULL WHERE id = :id')->execute(['id' => $tutorB]);
$pdo->prepare('UPDATE `groups` SET whatsapp_url = :u WHERE id = :id')
    ->execute(['u' => 'https://chat.whatsapp.com/ProvaPermessiMondoA', 'id' => $a['gruppo']]);

// Un gruppo con abbastanza persone da fare un cerchio, per i controlli di
// accessibilita': con il solo studente del mondo A ci sarebbe un punto, non
// un cerchio, e i nomi sui due fianchi non si vedrebbero mai. Otto
// partecipanti — lo studente di A e sette compagni senza foto, cosi' si
// vedono anche le iniziali — e il tutor di A al centro. Uno dei nomi e'
// lungo apposta: e' lui che deve andare a capo senza uscire dalla pagina.
//
// I compagni sono utenti veri e restano fra una semina e l'altra (come
// `nuovo@test.it`); il gruppo invece si cancella e si ricrea con il marchio.
$pdo->prepare('INSERT INTO `groups` (name, tutor_id) VALUES (:n, :t)')
    ->execute(['n' => MARCHIO . ' cerchio', 't' => $tutorA]);
$gruppoCerchio = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:g, :u)')
    ->execute(['g' => $gruppoCerchio, 'u' => $studenteA]);

for ($n = 1; $n <= 7; $n++) {
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at)
         VALUES (:e, :p, :nome, :cognome, "studente", 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name)'
    )->execute([
        'e' => 'compagno' . $n . '@test.it',
        'p' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'nome' => $n === 3 ? 'Compagna' : 'Compagno',
        'cognome' => $n === 3 ? 'con un nome davvero molto lungo Bartolomeo-Castiglioni' : (string) $n,
    ]);

    $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:g, :u)')
        ->execute(['g' => $gruppoCerchio, 'u' => utente($pdo, 'compagno' . $n . '@test.it')]);
}

// Come compaiono agli altri studenti (07/10): il compagno 5 ha scelto le
// sole iniziali, il 6 il solo nome, gli altri il predefinito. Si riscrive a
// ogni semina, cosi' una prova che lo cambia non lascia tracce.
$pdo->exec("UPDATE users SET name_display = 'full' WHERE email LIKE 'compagno%@test.it' OR email IN ('stud@test.it', 'strano@test.it')");

// Il primo accesso (08/10): chi non ha ancora scelto come lo vedono gli
// altri va alla pagina «Primo accesso». Gli studenti delle prove hanno gia'
// scelto (qui sopra); lo studente «al primo accesso» no, e «primo@test.it»
// ha in piu' una password temporanea da cambiare, come chi riceve le
// credenziali per email. Si rimettono cosi' a ogni semina.
$pdo->exec("UPDATE users SET name_display = NULL WHERE email = 'nuovo@test.it'");
$pdo->prepare(
    'INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, must_change_password, name_display)
     VALUES (:e, :p, :n, :c, "studente", 1, NOW(), 1, NULL)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), must_change_password = 1, name_display = NULL,
                             password_changed_at = NULL, welcome_seen_at = NULL'
)->execute([
    'e' => 'primo@test.it',
    'p' => password_hash('Password1!', PASSWORD_DEFAULT),
    'n' => 'Marta',
    'c' => 'Rossi',
]);
$pdo->exec("UPDATE users SET name_display = 'initials' WHERE email = 'compagno5@test.it'");
$pdo->exec("UPDATE users SET name_display = 'first' WHERE email = 'compagno6@test.it'");

// La compagna dal nome lungo ha anche la presentazione piu' lunga possibile,
// mille caratteri e una parola senza spazi: e' lei che deve stare nella
// scheda senza farla uscire dallo schermo, sul telefono come su computer.
$lunga = 'Unaparolalunghissimasenzaspazichenonsapreidovemandareacapo'
    . str_repeat(' Una frase qualunque per arrivare al limite.', 30);
$presentazione->execute([
    'id' => utente($pdo, 'compagno3@test.it'),
    'b' => mb_substr($lunga, 0, 1000),
]);

echo json_encode([
    'utenti' => [
        'admin' => $admin,
        'tutorA' => $tutorA,
        'tutorB' => $tutorB,
        'assistente' => $assistente,
        'studenteA' => $studenteA,
        'studenteB' => $studenteB,
        'studenteNuovo' => $studenteNuovo,
    ],
    'gruppo_cerchio' => $gruppoCerchio,
    'A' => $a,
    'B' => $b,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
