<?php

declare(strict_types=1);

/**
 * Test del benvenuto del tutor all'inizio di un corso (07/10).
 *
 * Esecuzione:  php tests/benvenuto_tutor_test.php
 *
 * Due parti. La regola completo/ridotto (`TutorWelcome::modo()`), senza
 * database. Poi, sul database dell'installazione con dati di prova a id alti
 * cancellati alla fine, quale benvenuto sente quale studente
 * (`TutorWelcomeModel::forStudent()`) e come contano le visite. Il test non
 * tocca nessun utente, corso o gruppo veri.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\TutorWelcome;
use App\Models\TutorWelcomeModel;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    if (!$condition && $detail !== '') {
        echo '         · ' . $detail . PHP_EOL;
    }
}

echo PHP_EOL . 'Completo o ridotto (la prima delle due cose, deciso da Elena)' . PHP_EOL;

check('prima visita: completo', TutorWelcome::modo(1, false) === 'completo');
check('terza visita: ancora completo', TutorWelcome::modo(3, false) === 'completo');
check('quarta visita: ridotto', TutorWelcome::modo(4, false) === 'ridotto');
check('ascoltato fino in fondo: ridotto anche alla seconda visita', TutorWelcome::modo(2, true) === 'ridotto');
check('ascoltato e alla prima visita: ridotto', TutorWelcome::modo(1, true) === 'ridotto');
check('le visite complete sono tre', TutorWelcome::VISITE_COMPLETE === 3);

echo PHP_EOL . 'Chi sente quale benvenuto' . PHP_EOL;

$db = Database::connection();
$corso = 999300;
$bianchi = 999301;     // tutor che viene dopo per nome
$aldi = 999302;        // tutor che viene prima per nome
$studente = 999303;
$senzaGruppo = 999304;
$gruppoBianchi = 999305;
$gruppoAldi = 999306;
$admin = (int) $db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

$pulisci = static function () use ($db, $corso, $bianchi, $aldi, $studente, $senzaGruppo, $gruppoBianchi, $gruppoAldi): void {
    $db->prepare('DELETE FROM courses WHERE id = :id')->execute(['id' => $corso]);
    $db->exec('DELETE FROM `groups` WHERE id IN (' . $gruppoBianchi . ', ' . $gruppoAldi . ')');
    $db->exec('DELETE FROM users WHERE id IN (' . implode(', ', [$bianchi, $aldi, $studente, $senzaGruppo]) . ')');
};

$pulisci();

try {
    foreach ([[$bianchi, 'Zeta Bianchi', 'tutor'], [$aldi, 'Anna Aldi', 'tutor'],
              [$studente, 'Studente di prova', 'studente'], [$senzaGruppo, 'Senza gruppo', 'studente']] as [$id, $nome, $ruolo]) {
        $db->prepare('INSERT INTO users (id, email, password_hash, full_name, role, email_verified_at)
                      VALUES (:id, :e, :h, :n, :r, NOW())')
            ->execute(['id' => $id, 'e' => 'benvenuto-' . $id . '@example.invalid', 'h' => 'x', 'n' => $nome, 'r' => $ruolo]);
    }

    $db->prepare('INSERT INTO courses (id, title, slug, created_by) VALUES (:id, :t, :s, :a)')
        ->execute(['id' => $corso, 't' => 'Corso di prova del benvenuto', 's' => 'prova-benvenuto-' . $corso, 'a' => $admin]);

    foreach ([[$gruppoBianchi, $bianchi], [$gruppoAldi, $aldi]] as [$g, $t]) {
        $db->prepare('INSERT INTO `groups` (id, name, tutor_id) VALUES (:id, :n, :t)')
            ->execute(['id' => $g, 'n' => 'Gruppo di prova ' . $g, 't' => $t]);
        $db->prepare('INSERT INTO group_course_access (group_id, course_id) VALUES (:g, :c)')
            ->execute(['g' => $g, 'c' => $corso]);
    }

    $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:g, :u)')
        ->execute(['g' => $gruppoBianchi, 'u' => $studente]);

    $tutors = array_map(static fn (array $r): int => (int) $r['tutor_id'], TutorWelcomeModel::tutorsForCourse($corso));
    check('i tutor del corso sono quelli dei suoi gruppi, per nome', $tutors === [$aldi, $bianchi], json_encode($tutors));

    check('nessun benvenuto caricato: lo studente non sente niente',
        TutorWelcomeModel::forStudent($corso, $studente) === null);

    TutorWelcomeModel::save($corso, $aldi, 'welcomes/x/a.jpg', 'welcomes/x/a.mp3', 'Testo di Aldi');
    check('c\'è il benvenuto di un altro tutor: lo studente non lo sente',
        TutorWelcomeModel::forStudent($corso, $studente) === null,
        'lo studente sta nel gruppo di Bianchi, non di Aldi');

    TutorWelcomeModel::save($corso, $bianchi, 'welcomes/x/b.jpg', 'welcomes/x/b.mp3', 'Testo di Bianchi');
    $sente = TutorWelcomeModel::forStudent($corso, $studente);
    check('sente il tutor del proprio gruppo', $sente !== null && (int) $sente['tutor_id'] === $bianchi);

    check('chi non è in un gruppo non sente niente (deciso da Elena)',
        TutorWelcomeModel::forStudent($corso, $senzaGruppo) === null);

    $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:g, :u)')
        ->execute(['g' => $gruppoAldi, 'u' => $studente]);
    $sente = TutorWelcomeModel::forStudent($corso, $studente);
    check('in due gruppi con due tutor: quello che viene prima per nome',
        $sente !== null && (int) $sente['tutor_id'] === $aldi);

    TutorWelcomeModel::save($corso, $bianchi, 'welcomes/x/b2.jpg', 'welcomes/x/b2.mp3', 'Testo nuovo');
    $riga = TutorWelcomeModel::findFor($corso, $bianchi);
    check('salvare di nuovo aggiorna, non duplica', $riga !== null && $riga['transcript'] === 'Testo nuovo'
        && (int) $db->query('SELECT COUNT(*) FROM course_tutor_welcomes WHERE course_id = ' . $corso)->fetchColumn() === 2);

    echo PHP_EOL . 'Le visite' . PHP_EOL;

    $modi = [];
    for ($i = 1; $i <= 4; $i++) {
        $stato = TutorWelcomeModel::recordVisit($studente, $corso);
        $modi[] = TutorWelcome::modo($stato['visits'], $stato['listened']);
    }
    check('quattro visite: completo, completo, completo, ridotto',
        $modi === ['completo', 'completo', 'completo', 'ridotto'], implode(', ', $modi));

    $db->prepare('DELETE FROM course_welcome_views WHERE user_id = :u')->execute(['u' => $studente]);
    TutorWelcomeModel::recordVisit($studente, $corso);
    TutorWelcomeModel::markListened($studente, $corso);
    $stato = TutorWelcomeModel::recordVisit($studente, $corso);
    check('ascoltato alla prima visita: ridotto già alla seconda',
        TutorWelcome::modo($stato['visits'], $stato['listened']) === 'ridotto');

    $primo = $db->query('SELECT listened_at FROM course_welcome_views WHERE user_id = ' . $studente)->fetchColumn();
    sleep(1);   // la data e' al secondo: senza attesa sarebbe uguale comunque
    TutorWelcomeModel::markListened($studente, $corso);
    $dopo = $db->query('SELECT listened_at FROM course_welcome_views WHERE user_id = ' . $studente)->fetchColumn();
    check('riascoltarlo non cambia la data del primo ascolto', $primo !== null && $primo === $dopo, $primo . ' → ' . $dopo);
} finally {
    $pulisci();
}

check('il test lascia il database com\'era',
    (int) $db->query('SELECT COUNT(*) FROM course_tutor_welcomes WHERE course_id = ' . $corso)->fetchColumn() === 0);

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
