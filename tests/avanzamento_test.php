<?php

declare(strict_types=1);

/**
 * L'avanzamento degli iscritti quando il corso cambia (17/11, Alessandro:
 * la seconda lista della revisione del codice, voce 5).
 *
 * Esecuzione:  php tests/avanzamento_test.php
 *
 * Una lezione creata o eliminata, o un modulo eliminato, ricalcola
 * l'avanzamento di tutti gli iscritti del corso. Il «completato» resta a chi
 * l'aveva, come nella maggior parte degli LMS; chi arriva al 100% perche' si
 * e' tolta la lezione che gli mancava diventa completato adesso.
 *
 * Usa il database: un corso marcato `[prova-avanzamento]` con due studenti
 * creati qui, tolto alla fine.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Models\EnrollmentModel;
use App\Models\LessonModel;
use App\Models\LessonProgressModel;
use App\Models\ModuleModel;

const MARCHIO = '[prova-avanzamento]';

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
    exit(1);
}

$pulisci = static function () use ($pdo): void {
    $pdo->prepare('DELETE FROM courses WHERE title LIKE :m')->execute(['m' => MARCHIO . '%']);
    $pdo->prepare('DELETE FROM users WHERE email LIKE :m')->execute(['m' => 'prova-avanzamento-%@esempio.invalid']);
};

$pulisci();

try {
    $admin = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@test.it'")->fetchColumn();
    $pdo->prepare('INSERT INTO courses (title, slug, is_published, enrollment_mode, created_by) VALUES (:t, :s, 1, "open", :a)')
        ->execute(['t' => MARCHIO . ' corso', 's' => 'prova-avanzamento-' . bin2hex(random_bytes(4)), 'a' => $admin]);
    $corso = (int) $pdo->lastInsertId();

    $studente = static function (string $chi) use ($pdo, $corso): int {
        $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, is_active) VALUES (:e, 'x', 'Prova', :n, 'studente', 1)")
            ->execute(['e' => 'prova-avanzamento-' . $chi . '@esempio.invalid', 'n' => $chi]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (:u, :c)')->execute(['u' => $id, 'c' => $corso]);

        return $id;
    };
    $tutto = $studente('tutto');
    $meta = $studente('meta');

    $stato = static function (int $utente) use ($pdo, $corso): array {
        $s = $pdo->prepare('SELECT progress_pct, completed_at FROM enrollments WHERE user_id = :u AND course_id = :c');
        $s->execute(['u' => $utente, 'c' => $corso]);
        $r = $s->fetch();

        return ['pct' => (float) $r['progress_pct'], 'completato' => $r['completed_at']];
    };
    $completa = static function (int $utente, int $lezione) use ($corso): void {
        LessonProgressModel::markCompleted($utente, $lezione);
        $totale = LessonModel::countForCourse($corso);
        $fatte = LessonProgressModel::countCompletedForCourse($utente, $corso);
        EnrollmentModel::updateProgress($utente, $corso, $totale > 0 ? round($fatte / $totale * 100, 2) : 0.0);
    };

    $m1 = ModuleModel::create($corso, MARCHIO . ' modulo uno');
    $l1 = LessonModel::create($m1, 'Uno', '<p>Uno</p>', 'none', null, 0);
    $l2 = LessonModel::create($m1, 'Due', '<p>Due</p>', 'none', null, 0);
    $completa($tutto, $l1);
    $completa($tutto, $l2);
    $completa($meta, $l1);

    $prima = $stato($tutto);
    check('la partenza: uno al 100% e completato, uno al 50%', $prima['pct'] === 100.0 && $prima['completato'] !== null && $stato($meta)['pct'] === 50.0);

    echo PHP_EOL . '--- Una lezione in piu\'' . PHP_EOL;
    $l3 = LessonModel::create($m1, 'Tre', '<p>Tre</p>', 'none', null, 0);
    $s = $stato($tutto);
    check('chi aveva finito scende al 66,67%', $s['pct'] === 66.67, json_encode($s));
    check('e resta completato, con la data di prima', $s['completato'] === $prima['completato'], json_encode($s));
    check('l\'altro scende al 33,33% e resta non completato', $stato($meta)['pct'] === 33.33 && $stato($meta)['completato'] === null);

    echo PHP_EOL . '--- Lo studente completa una lezione dopo' . PHP_EOL;
    $m2 = ModuleModel::create($corso, MARCHIO . ' modulo due');
    $l4 = LessonModel::create($m2, 'Quattro', '<p>Quattro</p>', 'none', null, 0);
    $completa($tutto, $l3);
    $s = $stato($tutto);
    check('completando una lezione (75%) il «completato» non si perde', $s['pct'] === 75.0 && $s['completato'] === $prima['completato'], json_encode($s));

    echo PHP_EOL . '--- Una lezione in meno' . PHP_EOL;
    LessonModel::delete($l4);
    check('tolta la lezione che gli mancava, chi le ha fatte tutte torna al 100%', $stato($tutto)['pct'] === 100.0);
    $completa($meta, $l2);
    $l5 = LessonModel::create($m2, 'Cinque', '<p>Cinque</p>', 'none', null, 0);
    $completa($meta, $l5);
    check('il secondo: 3 su 4 (75%), non completato', $stato($meta)['pct'] === 75.0 && $stato($meta)['completato'] === null, json_encode($stato($meta)));
    LessonModel::delete($l3);
    $s = $stato($meta);
    check('tolta la sola lezione che gli mancava: 100% e completato adesso', $s['pct'] === 100.0 && $s['completato'] !== null, json_encode($s));

    echo PHP_EOL . '--- Un modulo in meno' . PHP_EOL;
    $m3 = ModuleModel::create($corso, MARCHIO . ' modulo tre');
    LessonModel::create($m3, 'Sei', '<p>Sei</p>', 'none', null, 0);
    LessonModel::create($m3, 'Sette', '<p>Sette</p>', 'none', null, 0);
    // Il primo ha fatto Uno e Due su Uno, Due, Cinque, Sei, Sette (Tre e
    // Quattro non ci sono piu'); il secondo Uno, Due e Cinque.
    check('due lezioni nuove in un modulo nuovo: 2 su 5 (40%) e 3 su 5 (60%)', $stato($tutto)['pct'] === 40.0 && $stato($meta)['pct'] === 60.0, json_encode([$stato($tutto), $stato($meta)]));
    ModuleModel::delete($m3);
    check('eliminato il modulo: 2 su 3 (66,67%) e 3 su 3 (100%), e tutti e due restano completati',
        $stato($tutto)['pct'] === 66.67 && $stato($meta)['pct'] === 100.0 && $stato($tutto)['completato'] === $prima['completato'] && $stato($meta)['completato'] !== null);

    echo PHP_EOL . '--- Gli altri corsi non si toccano' . PHP_EOL;
    $altro = $pdo->query("SELECT user_id, course_id, progress_pct FROM enrollments WHERE course_id <> {$corso} ORDER BY user_id, course_id LIMIT 1")->fetch();

    if ($altro !== false) {
        $pdo->prepare('UPDATE enrollments SET progress_pct = 12.34 WHERE user_id = :u AND course_id = :c')->execute(['u' => $altro['user_id'], 'c' => $altro['course_id']]);
        LessonModel::create($m1, 'Otto', '<p>Otto</p>', 'none', null, 0);
        $s = $pdo->prepare('SELECT progress_pct FROM enrollments WHERE user_id = :u AND course_id = :c');
        $s->execute(['u' => $altro['user_id'], 'c' => $altro['course_id']]);
        check('una lezione aggiunta qui non ricalcola le iscrizioni di un altro corso', (float) $s->fetchColumn() === 12.34);
        $pdo->prepare('UPDATE enrollments SET progress_pct = :p WHERE user_id = :u AND course_id = :c')
            ->execute(['p' => $altro['progress_pct'], 'u' => $altro['user_id'], 'c' => $altro['course_id']]);
    }
} finally {
    $pulisci();
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
