<?php

declare(strict_types=1);

/**
 * Test del rilascio progressivo dei moduli (§8.7).
 *
 * Esecuzione:  php tests/rilascio_test.php
 *
 * ATTENZIONE: scrive davvero nel database indicato nel .env. E' pensato per
 * l'installazione di sviluppo, non per la produzione. Tutto quello che crea
 * sta sotto il marchio qui sotto e viene cancellato alla fine, anche se
 * un'asserzione fallisce.
 *
 * COSA VERIFICA, e perche' serve oltre a `tests/permessi.js`. Quello prova
 * la porta: lo studente riesce ad aprire la lezione di un modulo chiuso? Qui
 * si provano le tre cose che da fuori non si vedono:
 *
 *   1. **La catena.** Un modulo chiuso da una data, che ha il quiz
 *      obbligatorio, chiude anche quelli dopo: il suo quiz non si puo' fare,
 *      quindi la condizione per proseguire non si puo' soddisfare. Se un
 *      giorno le due regole venissero separate, da fuori il modulo 3
 *      sembrerebbe legittimamente aperto.
 *   2. **Il non mandare due volte.** `claim()` deve riuscire una volta sola,
 *      e a deciderlo dev'essere la chiave unica del database, non una
 *      lettura fatta prima: e' quello che tiene anche con due esecuzioni
 *      sovrapposte del comando.
 *   3. **I conti della frase** mostrata allo studente: fra le lezioni
 *      disponibili, non fra tutte.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\CourseAccess;
use App\Core\Database;
use App\Models\LessonProgressModel;
use App\Models\ModuleUnlockModel;

const MARCHIO = '[prova-rilascio]';

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
    $pdo->prepare('DELETE FROM courses WHERE title LIKE :m')->execute(['m' => MARCHIO . '%']);
};

$pulisci();

// Lo studente deve esistere: e' quello dell'installazione di prova.
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e');
$stmt->execute(['e' => 'stud@test.it']);
$studente = (int) $stmt->fetchColumn();

if ($studente === 0) {
    echo "Manca l'utente stud@test.it: il test ha bisogno degli utenti di prova." . PHP_EOL;
    exit(1);
}

// --- il corso di prova -----------------------------------------------------
//
// Tre moduli: aperto, chiuso da una data e con quiz obbligatorio, aperto.
// E' la forma minima in cui la catena si puo' vedere.

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e');
$stmt->execute(['e' => 'admin@test.it']);
$admin = (int) $stmt->fetchColumn();

$pdo->prepare('INSERT INTO courses (title, slug, is_published, enrollment_mode, created_by)
               VALUES (:t, :s, 1, "open", :a)')
    ->execute([
        't' => MARCHIO . ' corso',
        's' => 'prova-rilascio-' . bin2hex(random_bytes(4)),
        'a' => $admin,
    ]);
$corso = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (:u, :c)')
    ->execute(['u' => $studente, 'c' => $corso]);

/** @return array{0: int, 1: int} id del modulo e della sua lezione */
$modulo = static function (string $titolo, ?string $quando, bool $quizObbligatorio, int $posizione)
use ($pdo, $corso): array {
    $sql = $quando === null
        ? 'INSERT INTO modules (course_id, title, position, quiz_required, available_from)
           VALUES (:c, :t, :p, :q, NULL)'
        : 'INSERT INTO modules (course_id, title, position, quiz_required, available_from)
           VALUES (:c, :t, :p, :q, ' . $quando . ')';

    $pdo->prepare($sql)->execute([
        'c' => $corso,
        't' => MARCHIO . ' ' . $titolo,
        'p' => $posizione,
        'q' => $quizObbligatorio ? 1 : 0,
    ]);
    $id = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO lessons (module_id, title, content_html, position)
                   VALUES (:m, :t, "<p>.</p>", 0)')
        ->execute(['m' => $id, 't' => MARCHIO . ' lezione di ' . $titolo]);

    return [$id, (int) $pdo->lastInsertId()];
};

[$aperto, $lezioneAperta] = $modulo('aperto', null, false, 0);
[$chiuso, ] = $modulo('chiuso', 'DATE_ADD(NOW(), INTERVAL 30 DAY)', true, 1);
[$dopo, ] = $modulo('dopo il chiuso', null, false, 2);

// Il modulo chiuso ha un quiz vero: senza domande, `CourseAccess` lo
// ignorerebbe per non creare un vicolo cieco, e la catena non si vedrebbe.
$pdo->prepare('INSERT INTO quizzes (module_id, title, passing_score_pct) VALUES (:m, :t, 60)')
    ->execute(['m' => $chiuso, 't' => MARCHIO . ' quiz']);
$quiz = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, question_type, position)
               VALUES (:q, "Domanda?", "single_choice", 0)')
    ->execute(['q' => $quiz]);

try {
    // ---------------------------------------------------------------
    echo PHP_EOL . 'Quali moduli sono chiusi, e perche' . "'" . PHP_EOL;

    $locks = CourseAccess::locks($studente, $corso);

    check('il modulo senza data e aperto', !isset($locks[$aperto]));
    check('il modulo con data futura e chiuso', isset($locks[$chiuso]));
    check(
        'ed e chiuso per data, non per quiz',
        ($locks[$chiuso]['motivo'] ?? '') === CourseAccess::MOTIVO_DATA,
        'motivo: ' . ($locks[$chiuso]['motivo'] ?? 'nessuno')
    );
    check(
        'la data di apertura torna insieme al motivo',
        ($locks[$chiuso]['available_from'] ?? null) !== null
    );

    // La catena: il modulo 3 non ha nessuna data, ed e' chiuso lo stesso.
    check(
        'il modulo dopo quello chiuso e chiuso a sua volta',
        isset($locks[$dopo]),
        'un quiz obbligatorio che non si puo fare non deve lasciar passare oltre'
    );
    check(
        'e il suo motivo e il quiz, non la data',
        ($locks[$dopo]['motivo'] ?? '') === CourseAccess::MOTIVO_QUIZ,
        'motivo: ' . ($locks[$dopo]['motivo'] ?? 'nessuno')
    );

    check(
        'la prossima apertura e quella del modulo chiuso',
        CourseAccess::nextUnlockAt($studente, $corso) !== null
    );

    // ---------------------------------------------------------------
    echo PHP_EOL . 'I conti della frase mostrata allo studente' . PHP_EOL;

    $riepilogo = LessonProgressModel::availabilitySummary($studente)[$corso] ?? null;

    check('il corso compare nel riepilogo', $riepilogo !== null);
    check(
        'conta solo le lezioni dei moduli gia aperti',
        ($riepilogo['disponibili'] ?? -1) === 2,
        'ottenuto: ' . ($riepilogo['disponibili'] ?? 'niente') . ' invece di 2 su 3 lezioni'
    );
    check('nessuna ancora fatta', ($riepilogo['fatte'] ?? -1) === 0);
    check('sa quando si apre il prossimo', ($riepilogo['prossima'] ?? null) !== null);

    // Il conto in SQL guarda solo le date, quindi include il modulo 3, che
    // la catena dei quiz tiene chiuso. La correzione che il controller
    // applica alla frase passa di qui: senza, lo studente leggerebbe «0 di
    // 2 disponibili» con una delle due che non si apre.
    $perQuiz = [];

    foreach (CourseAccess::locks($studente, $corso) as $moduleId => $lock) {
        if ($lock['motivo'] === CourseAccess::MOTIVO_QUIZ) {
            $perQuiz[] = $moduleId;
        }
    }

    $tolti = LessonProgressModel::countsForModules($studente, $perQuiz);

    check(
        'i moduli chiusi dalla catena si tolgono dal conto',
        ($riepilogo['disponibili'] - $tolti['lezioni']) === 1,
        'corretto: ' . ($riepilogo['disponibili'] - $tolti['lezioni']) . ' invece di 1'
    );

    $pdo->prepare('INSERT IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (:u, :l)')
        ->execute(['u' => $studente, 'l' => $lezioneAperta]);

    $riepilogo = LessonProgressModel::availabilitySummary($studente)[$corso] ?? null;
    check('dopo una lezione completata il conto sale', ($riepilogo['fatte'] ?? -1) === 1);

    // ---------------------------------------------------------------
    echo PHP_EOL . 'Le email non partono due volte' . PHP_EOL;

    // Un modulo gia' aperto e mai annunciato: e' il caso che il comando
    // trova il giorno dell'apertura.
    $pdo->prepare('UPDATE modules SET available_from = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = :m')
        ->execute(['m' => $chiuso]);

    $inSospeso = ModuleUnlockModel::pending();
    $miei = array_values(array_filter(
        $inSospeso,
        static fn (array $r): bool => (int) $r['module_id'] === $chiuso
    ));

    check('il modulo appena aperto e da annunciare', count($miei) === 1);
    check(
        'un modulo senza data non entra mai fra gli avvisi',
        !in_array($aperto, array_map(static fn ($r) => (int) $r['module_id'], $inSospeso), true),
        'altrimenti la prima esecuzione manderebbe un email per ogni modulo esistente'
    );

    check('la presa in carico riesce la prima volta', ModuleUnlockModel::claim($chiuso, $studente));
    check(
        'e non riesce la seconda',
        !ModuleUnlockModel::claim($chiuso, $studente),
        'a decidere deve essere la chiave unica, non una lettura fatta prima'
    );

    $ancora = array_filter(
        ModuleUnlockModel::pending(),
        static fn (array $r): bool => (int) $r['module_id'] === $chiuso
    );
    check('e dopo la presa in carico non e piu in sospeso', $ancora === []);
} finally {
    $pulisci();
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
