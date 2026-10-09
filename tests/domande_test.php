<?php

declare(strict_types=1);

/**
 * Test delle domande degli studenti e dell'archivio (07/10).
 *
 * Esecuzione:  php tests/domande_test.php
 *
 * Sul database dell'installazione, con dati di prova a id alti cancellati
 * alla fine: un corso con due moduli e due gruppi con due tutor. Si prova a
 * chi va una domanda, chi la vede in attesa, che si pubblica e si scarta una
 * volta sola, e come e' fatto l'archivio: diviso per modulo nell'ordine del
 * corso, il corso in generale in fondo, la ricerca.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Models\QuestionModel as Q;

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

$db = Database::connection();
$corso = 999400;
[$modulo1, $modulo2] = [999401, 999402];
[$tutorA, $tutorB, $studente1, $studente2, $senzaGruppo] = [999403, 999404, 999405, 999406, 999407];
[$gruppoA, $gruppoB] = [999408, 999409];
$admin = (int) $db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

$pulisci = static function () use ($db, $corso, $tutorA, $tutorB, $studente1, $studente2, $senzaGruppo, $gruppoA, $gruppoB): void {
    $db->prepare('DELETE FROM courses WHERE id = :id')->execute(['id' => $corso]);
    $db->exec("DELETE FROM `groups` WHERE id IN ($gruppoA, $gruppoB)");
    $db->exec("DELETE FROM users WHERE id IN ($tutorA, $tutorB, $studente1, $studente2, $senzaGruppo)");
};
$pulisci();

try {
    foreach ([[$tutorA, 'Anna', 'Tutor', 'tutor'], [$tutorB, 'Bruno', 'Tutor', 'tutor'], [$studente1, 'Mario', 'Rossi', 'studente'],
              [$studente2, 'Luisa', 'Verdi', 'studente'], [$senzaGruppo, 'Nessun', 'Gruppo', 'studente']] as [$id, $n, $c, $r]) {
        $db->prepare('INSERT INTO users (id, email, password_hash, first_name, last_name, role, email_verified_at)
                      VALUES (:id, :e, "x", :n, :c, :r, NOW())')
            ->execute(['id' => $id, 'e' => 'domande-' . $id . '@example.invalid', 'n' => $n, 'c' => $c, 'r' => $r]);
    }
    $db->prepare('INSERT INTO courses (id, title, slug, created_by) VALUES (:id, "Corso delle domande", :s, :a)')
        ->execute(['id' => $corso, 's' => 'prova-domande-' . $corso, 'a' => $admin]);
    // Il modulo 2 viene prima del modulo 1 nel corso: l'archivio deve seguire
    // l'ordine del corso, non quello degli id.
    $db->prepare('INSERT INTO modules (id, course_id, title, position) VALUES (:id, :c, :t, :p)')
        ->execute(['id' => $modulo1, 'c' => $corso, 't' => 'Secondo nel corso', 'p' => 2]);
    $db->prepare('INSERT INTO modules (id, course_id, title, position) VALUES (:id, :c, :t, :p)')
        ->execute(['id' => $modulo2, 'c' => $corso, 't' => 'Primo nel corso', 'p' => 1]);
    foreach ([[$gruppoA, $tutorA, $studente1], [$gruppoB, $tutorB, $studente2]] as [$g, $t, $s]) {
        $db->prepare('INSERT INTO `groups` (id, name, tutor_id) VALUES (:id, :n, :t)')->execute(['id' => $g, 'n' => 'Gruppo ' . $g, 't' => $t]);
        $db->prepare('INSERT INTO group_course_access (group_id, course_id) VALUES (:g, :c)')->execute(['g' => $g, 'c' => $corso]);
        $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:g, :u)')->execute(['g' => $g, 'u' => $s]);
    }

    echo PHP_EOL . 'A chi va una domanda: all\'esperto (09/10)' . PHP_EOL;

    // Dal 09/10 risponde l'esperto, cioe' l'admin: le domande nascono senza
    // tutor (QuestionController::store), e le vede tutte chi ha
    // question.answer.
    $d1 = Q::create($corso, $modulo1, $studente1, null, 'Prima domanda del gruppo A?');
    $d2 = Q::create($corso, null, $studente2, null, 'Domanda del gruppo B?');
    $d3 = Q::create($corso, $modulo2, $senzaGruppo, null, 'Domanda senza gruppo?');

    $inAttesa = array_map(
        static fn (array $q): int => (int) $q['id'],
        array_values(array_filter(Q::pending($admin, true), static fn (array $q): bool => (int) $q['course_id'] === $corso))
    );
    check('l\'esperto le vede tutte, di ogni gruppo e anche di chi non ha gruppo', $inAttesa === [$d1, $d2, $d3],
        json_encode($inAttesa));
    $perAdmin = array_values(array_filter(Q::pending($admin, true), static fn (array $q): bool => (int) $q['id'] === $d1))[0] ?? [];
    check('e vede lo studente per intero, con il gruppo',
        ($perAdmin['student_name'] ?? '') === 'Mario Rossi' && ($perAdmin['group_names'] ?? '') === 'Gruppo ' . $gruppoA);
    check('il conteggio nel menu dell\'esperto le conta', Q::pendingCount($admin, true) >= 3);

    echo PHP_EOL . 'Pubblicare e scartare' . PHP_EOL;

    check('pubblicare con il testo corretto e il modulo cambiato',
        Q::publish($d1, 'Domanda corretta dal tutor?', $modulo2, 'Risposta con il 50% di sconto.', $tutorA));
    $riga = Q::find($d1);
    check('il testo e il modulo sono quelli corretti', $riga['question'] === 'Domanda corretta dal tutor?' && (int) $riga['module_id'] === $modulo2);
    check('una domanda già pubblicata non si pubblica una seconda volta', !Q::publish($d1, 'Altro', null, 'Altro', $tutorB));
    check('né si scarta dopo', !Q::discard($d1, $tutorB));
    check('scartare una domanda in attesa', Q::discard($d3, $admin) && Q::find($d3)['status'] === 'discarded');
    check('lo studente vede le sue con lo stato',
        array_column(Q::ofStudent($corso, $studente1), 'status') === ['published']);

    echo PHP_EOL . 'L\'archivio' . PHP_EOL;

    Q::publish($d2, 'Domanda generale del gruppo B?', null, 'Risposta generale.', $tutorB);
    $d4 = Q::create($corso, $modulo1, $studente1, $tutorA, 'Domanda sul modulo che viene secondo?');
    Q::publish($d4, 'Domanda sul modulo che viene secondo?', $modulo1, 'Risposta sul secondo.', $tutorA);

    $ordine = array_map(static fn (array $q): string => (string) ($q['module_title'] ?? 'generale'), Q::published($corso));
    check('diviso per modulo nell\'ordine del corso, il corso in generale in fondo',
        $ordine === ['Primo nel corso', 'Secondo nel corso', 'generale'], json_encode($ordine));
    check('le scartate e quelle in attesa non ci sono', count(Q::published($corso)) === 3);
    check('le domande pubblicate da tutor diversi stanno insieme: lo vedono tutti gli iscritti',
        in_array($d2, array_map(static fn (array $q): int => (int) $q['id'], Q::published($corso)), true));

    $trovate = static fn (string $t): int => count(Q::published($corso, $t));
    check('la ricerca trova nella domanda', $trovate('corretta') === 1);
    check('e nella risposta', $trovate('sconto') === 1);
    check('senza badare alle maiuscole', $trovate('SCONTO') === 1);
    check('«%» si cerca come testo', $trovate('50%') === 1 && $trovate('5%') === 0);
    check('«_» si cerca come testo, non come un carattere qualunque', $trovate('5_') === 0);
    check('una ricerca che non trova niente: archivio vuoto', $trovate('zzz-nessuna') === 0);
    check('l\'autore arriva con i campi per decidere come mostrarlo',
        array_key_exists('name_display', Q::published($corso)[0]) && array_key_exists('first_name', Q::published($corso)[0]));

    echo PHP_EOL . 'Correggere una pubblicata e toglierla dall\'archivio (08/10)' . PHP_EOL;

    $tutteDelCorso = static fn (int $chi, bool $tutte): array => array_map(
        static fn (array $q): int => (int) $q['id'],
        array_values(array_filter(Q::publishedFor($chi, $tutte), static fn (array $q): bool => (int) $q['course_id'] === $corso))
    );
    check('l\'esperto ha da gestire tutte le pubblicate', count($tutteDelCorso($admin, true)) === 3);

    check('correggere testo, modulo e risposta di una pubblicata', Q::update($d4, 'Domanda corretta dopo?', null, 'Risposta corretta dopo.'));
    $riga = Q::find($d4);
    check('la correzione è salvata, e chi aveva risposto resta lo stesso',
        $riga['question'] === 'Domanda corretta dopo?' && $riga['module_id'] === null && $riga['answer'] === 'Risposta corretta dopo.'
        && (int) $riga['answered_by'] === $tutorA);
    check('salvare senza cambiare niente non è un errore', Q::update($d4, 'Domanda corretta dopo?', null, 'Risposta corretta dopo.'));
    $attesa = Q::create($corso, null, $studente1, $tutorA, 'Ancora in attesa?');
    check('una domanda in attesa non si «corregge»: si pubblica', !Q::update($attesa, 'x', null, 'y') && Q::find($attesa)['status'] === 'pending');

    check('togliere dall\'archivio', Q::withdraw($d4));
    $riga = Q::find($d4);
    check('torna «Non pubblicata», con la risposta e chi l\'aveva data',
        $riga['status'] === 'discarded' && $riga['answer'] === 'Risposta corretta dopo.' && (int) $riga['answered_by'] === $tutorA);
    check('non è più nell\'archivio', !in_array($d4, array_map(static fn (array $q): int => (int) $q['id'], Q::published($corso)), true));
    check('lo studente la vede ancora fra le sue, «Non pubblicata» (Elena)',
        in_array('discarded', array_column(array_values(array_filter(Q::ofStudent($corso, $studente1),
            static fn (array $q): bool => (int) $q['id'] === $d4)), 'status'), true));
    check('una tolta non si toglie una seconda volta, né si corregge', !Q::withdraw($d4) && !Q::update($d4, 'x', null, 'y'));
    check('una in attesa non si toglie dall\'archivio', !Q::withdraw($attesa));
} finally {
    $pulisci();
}

check('il test lascia il database com\'era',
    (int) $db->query('SELECT COUNT(*) FROM course_questions WHERE course_id = ' . $corso)->fetchColumn() === 0);

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
