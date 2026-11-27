<?php

declare(strict_types=1);

/**
 * I dati per `tests/agenda_bozze.js` (27/11): un corso pubblicato e uno in
 * bozza, con le aperture dei moduli per gruppo e gli incontri.
 *
 * Tutto si riconosce dal prefisso «[agenda-bozze]» (corsi e gruppi) e da
 * `agenda-bozze-studente@test.it`. Le date sono relative a NOW() del
 * database, cosi' la prova vale in qualunque giorno.
 *
 *   corso pubblicato, un modulo che si apre fra 20 giorni:
 *     - Alfa (tutor1@test.it) ha una data sua, fra 8 giorni;
 *     - Beta e Gamma seguono la data del modulo;
 *     - uno studente iscritto senza gruppo;
 *   corso in bozza, assegnato ad Alfa:
 *     - un modulo che si apre fra 12 giorni e uno che si e' aperto 3 giorni fa;
 *     - un incontro di Alfa fra 12 giorni e uno finito 2 giorni fa.
 *
 * Esecuzione:  php tests/agenda_bozze_semina.php crea|pubblica|togli
 * (`crea` stampa gli id in JSON; ricomincia da capo se trova dati vecchi)
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;

$pdo = Database::connection();
$azione = $argv[1] ?? '';
$studente = 'agenda-bozze-studente@test.it';

$togli = static function () use ($pdo, $studente): void {
    $corsi = $pdo->query("SELECT id FROM courses WHERE title LIKE '[agenda-bozze]%'")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($corsi as $c) {
        $pdo->prepare('DELETE ls FROM live_sessions ls INNER JOIN modules m ON m.id = ls.module_id WHERE m.course_id = ?')->execute([$c]);
        $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$c]);
    }

    $pdo->exec("DELETE FROM `groups` WHERE name LIKE '[agenda-bozze]%'");
    $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$studente]);
};

if ($azione === 'togli') {
    $togli();
    echo "tolti\n";
    exit(0);
}

if ($azione === 'pubblica') {
    $pdo->exec("UPDATE courses SET is_published = 1 WHERE title = '[agenda-bozze] bozza'");
    echo "pubblicato\n";
    exit(0);
}

if ($azione !== 'crea') {
    fwrite(STDERR, "Uso: php tests/agenda_bozze_semina.php crea|pubblica|togli\n");
    exit(1);
}

$togli();

$uno = static function (string $sql, array $p = []) use ($pdo): string {
    $st = $pdo->prepare($sql);
    $st->execute($p);

    return (string) $st->fetchColumn();
};
$admin = (int) $uno("SELECT id FROM users WHERE email = 'admin@test.it'");
$tutor = (int) $uno("SELECT id FROM users WHERE email = 'tutor1@test.it'");

$corso = static function (string $titolo, int $pubblicato) use ($pdo, $admin): int {
    $pdo->prepare("INSERT INTO courses (title, slug, is_published, enrollment_mode, created_by) VALUES (?, ?, ?, 'closed', ?)")
        ->execute([$titolo, 'agenda-bozze-' . bin2hex(random_bytes(4)), $pubblicato, $admin]);

    return (int) $pdo->lastInsertId();
};
$modulo = static function (int $corso, string $titolo, string $quando, int $pos) use ($pdo): int {
    $pdo->prepare("INSERT INTO modules (course_id, title, position, available_from) VALUES (?, ?, ?, DATE_FORMAT(NOW() + INTERVAL {$quando}, '%Y-%m-%d 09:00:00'))")
        ->execute([$corso, $titolo, $pos]);

    return (int) $pdo->lastInsertId();
};
$gruppo = static function (string $nome, ?int $tutorId) use ($pdo): int {
    $pdo->prepare('INSERT INTO `groups` (name, tutor_id) VALUES (?, ?)')->execute([$nome, $tutorId]);

    return (int) $pdo->lastInsertId();
};
$assegna = static fn (int $g, int $c) => $pdo->prepare('INSERT INTO group_course_access (group_id, course_id) VALUES (?, ?)')->execute([$g, $c]);

$pubblicato = $corso('[agenda-bozze] pubblicato', 1);
$bozza = $corso('[agenda-bozze] bozza', 0);

$moduloP = $modulo($pubblicato, '[agenda-bozze] modulo pubblicato', '20 DAY', 0);
$moduloB = $modulo($bozza, '[agenda-bozze] modulo in bozza', '12 DAY', 0);
$moduloBPassato = $modulo($bozza, '[agenda-bozze] modulo in bozza passato', '-3 DAY', 1);

$alfa = $gruppo('[agenda-bozze] Alfa', $tutor);
$beta = $gruppo('[agenda-bozze] Beta', null);
$gamma = $gruppo('[agenda-bozze] Gamma', null);

foreach ([$alfa, $beta, $gamma] as $g) {
    $assegna($g, $pubblicato);
}
$assegna($alfa, $bozza);

$pdo->prepare("INSERT INTO group_module_dates (group_id, module_id, available_from) VALUES (?, ?, DATE_FORMAT(NOW() + INTERVAL 8 DAY, '%Y-%m-%d 09:00:00'))")
    ->execute([$alfa, $moduloP]);

$pdo->prepare(
    "INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at, name_display)
     VALUES (?, ?, 'Studente', '[agenda-bozze]', 'studente', 1, NOW(), NOW(), 'full')"
)->execute([$studente, password_hash('Password1!', PASSWORD_DEFAULT)]);
$idStudente = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)')->execute([$idStudente, $pubblicato]);

$incontro = static function (string $titolo, string $inizio, string $fine) use ($pdo, $moduloB, $alfa, $admin): int {
    $pdo->prepare("INSERT INTO live_sessions (module_id, group_id, title, starts_at, ends_at, created_by)
                   VALUES (?, ?, ?, DATE_FORMAT(NOW() + INTERVAL {$inizio}, '%Y-%m-%d %H:00:00'), DATE_FORMAT(NOW() + INTERVAL {$fine}, '%Y-%m-%d %H:00:00'), ?)")
        ->execute([$moduloB, $alfa, $titolo, $admin]);

    return (int) $pdo->lastInsertId();
};
$incontroB = $incontro('[agenda-bozze] incontro in bozza', '12 DAY', '12 DAY + INTERVAL 1 HOUR');
$incontroBPassato = $incontro('[agenda-bozze] incontro in bozza passato', '-2 DAY - INTERVAL 2 HOUR', '-2 DAY - INTERVAL 1 HOUR');

echo json_encode([
    'pubblicato' => $pubblicato,
    'bozza' => $bozza,
    'modulo_pubblicato' => $moduloP,
    'modulo_bozza' => $moduloB,
    'modulo_bozza_passato' => $moduloBPassato,
    'incontro_bozza' => $incontroB,
    'incontro_bozza_passato' => $incontroBPassato,
    'data_alfa' => $uno("SELECT available_from FROM group_module_dates WHERE module_id = ?", [$moduloP]),
    'data_modulo' => $uno('SELECT available_from FROM modules WHERE id = ?', [$moduloP]),
    'data_bozza' => $uno('SELECT available_from FROM modules WHERE id = ?', [$moduloB]),
]) . "\n";
