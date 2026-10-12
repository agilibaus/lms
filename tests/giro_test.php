<?php

declare(strict_types=1);

/**
 * Test della pagina del gruppo che si scalda (12/10): «Il giro del cerchio»
 * e «Ci sarò».
 *
 *   - le regole senza database (`App\Core\GiroDelCerchio`): chi modera, chi
 *     partecipa, chi vede una risposta nascosta, l'ordine con la propria per
 *     prima, i conti e le frasi;
 *   - il modello sul database: la domanda aperta e' l'ultima, le altre sono
 *     l'archivio, una risposta per persona, nascondere e rimettere, «Ci
 *     sarò» e il suo contrario.
 *
 * Esecuzione:  php tests/giro_test.php
 *
 * La seconda parte scrive nel database del .env (quello di sviluppo): tutto
 * quello che crea ha il marchio qui sotto e si cancella alla fine.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\GiroDelCerchio;
use App\Models\GroupQuestionModel;
use App\Models\LiveSessionModel;

const MARCHIO = '[prova-giro]';

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

// ---------------------------------------------------------------
echo 'Le regole' . PHP_EOL;

$gruppo = ['tutor_id' => 7];
check('l\'admin modera ogni gruppo', GiroDelCerchio::modera($gruppo, 99, true));
check('il tutor modera il suo', GiroDelCerchio::modera($gruppo, 7, false));
check('un altro tutor no', !GiroDelCerchio::modera($gruppo, 8, false));
check('un gruppo senza tutor lo modera solo l\'admin', !GiroDelCerchio::modera(['tutor_id' => null], 0, false));
check('partecipa chi e\' fra i membri', GiroDelCerchio::partecipa([3, 5], 5) && !GiroDelCerchio::partecipa([3, 5], 7));

$risposte = [
    ['id' => 1, 'user_id' => 3, 'hidden_at' => null],
    ['id' => 2, 'user_id' => 5, 'hidden_at' => '2026-10-12 10:00:00'],
    ['id' => 3, 'user_id' => 9, 'hidden_at' => null],
];
$ids = static fn (array $l): array => array_map(static fn (array $r): int => $r['id'], $l);
check('una nascosta non la vede un compagno', $ids(GiroDelCerchio::visibili($risposte, 3, false)) === [1, 3]);
check('la vede chi l\'ha scritta, per prima', $ids(GiroDelCerchio::visibili($risposte, 5, false)) === [2, 1, 3]);
check('la vede chi modera, al suo posto', $ids(GiroDelCerchio::visibili($risposte, 7, true)) === [1, 2, 3]);
check('la propria va per prima', $ids(GiroDelCerchio::visibili($risposte, 9, false)) === [3, 1]);
check('nel conto le nascoste non contano', GiroDelCerchio::contate($risposte) === 2);

check('«3 risposte su 6»', GiroDelCerchio::conto(3, 6) === '3 risposte su 6');
check('«1 risposta su 6»', GiroDelCerchio::conto(1, 6) === '1 risposta su 6');
check('nessuna risposta', GiroDelCerchio::conto(0, 6) === 'Nessuna risposta ancora');
check('«Stefania chiede al gruppo»', GiroDelCerchio::chiChiede('Stefania') === 'Stefania chiede al gruppo');
check('senza autore', GiroDelCerchio::chiChiede(null) === 'Domanda al gruppo');
check('la data dell\'incontro, in italiano', GiroDelCerchio::quando('2026-10-16 14:00:00', '2026-10-16 15:00:00')
    === 'venerdì 16 ottobre · 14:00–15:00', GiroDelCerchio::quando('2026-10-16 14:00:00', '2026-10-16 15:00:00'));

// ---------------------------------------------------------------
echo PHP_EOL . 'Il database' . PHP_EOL;

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo '  (saltati: database non raggiungibile)' . PHP_EOL;
    echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
    exit($fail > 0 ? 1 : 0);
}

$pulisci = static function () use ($pdo): void {
    $pdo->prepare('DELETE FROM live_sessions WHERE title LIKE :m')->execute(['m' => MARCHIO . '%']);
    $pdo->prepare('DELETE FROM `groups` WHERE name LIKE :m')->execute(['m' => MARCHIO . '%']);
    $pdo->prepare('DELETE FROM users WHERE email LIKE :m')->execute(['m' => '%@prova-giro.invalid']);
};
$pulisci();

try {
    $utente = static function (string $chi, string $ruolo) use ($pdo): int {
        $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role) VALUES (:e, 'x', :n, 'Prova', :r)")
            ->execute(['e' => $chi . '@prova-giro.invalid', 'n' => ucfirst($chi), 'r' => $ruolo]);

        return (int) $pdo->lastInsertId();
    };
    $tutor = $utente('tutor', 'tutor');
    $anna = $utente('anna', 'studente');
    $bruno = $utente('bruno', 'studente');

    $pdo->prepare('INSERT INTO `groups` (name, tutor_id) VALUES (:n, :t)')->execute(['n' => MARCHIO . ' gruppo', 't' => $tutor]);
    $g = (int) $pdo->lastInsertId();

    check('senza domande non c\'e\' la domanda aperta, ne\' archivio',
        GroupQuestionModel::current($g) === null && GroupQuestionModel::archive($g) === [] && GroupQuestionModel::countArchive($g) === 0);

    $prima = GroupQuestionModel::create($g, $tutor, 'Prima domanda?');
    $pdo->prepare('UPDATE group_questions SET created_at = NOW() - INTERVAL 1 DAY WHERE id = :id')->execute(['id' => $prima]);
    check('una domanda: e\' quella aperta, con il nome di chi la pone',
        (int) (GroupQuestionModel::current($g)['id'] ?? 0) === $prima
            && (GroupQuestionModel::current($g)['author_first_name'] ?? '') === 'Tutor');

    GroupQuestionModel::saveAnswer($prima, $anna, 'Prima risposta di Anna');
    GroupQuestionModel::saveAnswer($prima, $anna, 'Anna ci ripensa');
    $ofAnna = GroupQuestionModel::answers($prima);
    check('una risposta per persona: riscriverla la sostituisce',
        count($ofAnna) === 1 && $ofAnna[0]['answer'] === 'Anna ci ripensa' && $ofAnna[0]['updated_at'] !== null);

    $seconda = GroupQuestionModel::create($g, $tutor, 'Seconda domanda?');
    check('una domanda nuova diventa quella aperta', (int) (GroupQuestionModel::current($g)['id'] ?? 0) === $seconda);
    check('e la precedente va in archivio, con le sue risposte',
        array_column(GroupQuestionModel::archive($g), 'id') === [$prima] && GroupQuestionModel::countArchive($g) === 1
            && count(GroupQuestionModel::answers($prima)) === 1);

    GroupQuestionModel::saveAnswer($seconda, $bruno, 'Risposta di Bruno');
    $risposta = GroupQuestionModel::answerOf($seconda, $bruno);
    GroupQuestionModel::setHidden((int) $risposta['id'], $tutor);
    $nascosta = GroupQuestionModel::findAnswer((int) $risposta['id']);
    check('nascondere segna quando e chi, senza cancellare',
        $nascosta !== null && $nascosta['hidden_at'] !== null && (int) $nascosta['hidden_by'] === $tutor
            && (int) $nascosta['group_id'] === $g);
    GroupQuestionModel::setHidden((int) $risposta['id'], null);
    check('rimettere la toglie dalle nascoste', GroupQuestionModel::findAnswer((int) $risposta['id'])['hidden_at'] === null);

    GroupQuestionModel::deleteAnswer($seconda, $bruno);
    check('togliere la propria risposta', GroupQuestionModel::answerOf($seconda, $bruno) === null);

    // «Ci sarò»: il prossimo incontro del gruppo e chi ci sara'.
    $incontro = static function (string $titolo, string $quando) use ($pdo, $g, $tutor): int {
        $pdo->prepare('INSERT INTO live_sessions (group_id, title, starts_at, ends_at, created_by)
                       VALUES (:g, :t, ' . $quando . ', DATE_ADD(' . $quando . ', INTERVAL 1 HOUR), :c)')
            ->execute(['g' => $g, 't' => MARCHIO . ' ' . $titolo, 'c' => $tutor]);

        return (int) $pdo->lastInsertId();
    };
    $incontro('passato', 'NOW() - INTERVAL 3 DAY');
    $dopo = $incontro('dopo', 'NOW() + INTERVAL 9 DAY');
    $prossimo = $incontro('prossimo', 'NOW() + INTERVAL 2 DAY');
    check('il prossimo incontro del gruppo e\' il primo non concluso', (int) (LiveSessionModel::prossimoDelGruppo($g)['id'] ?? 0) === $prossimo);

    LiveSessionModel::segnaCiSaro($prossimo, $anna, true);
    LiveSessionModel::segnaCiSaro($prossimo, $anna, true);
    check('«Ci sarò» una volta sola anche premuto due volte', array_column(LiveSessionModel::ciSaranno($prossimo), 'id') === [$anna]);
    LiveSessionModel::segnaCiSaro($prossimo, $anna, false);
    check('«Non posso più» lo toglie', LiveSessionModel::ciSaranno($prossimo) === []);
    check('l\'incontro dopo non ne risente', $dopo !== $prossimo && LiveSessionModel::ciSaranno($dopo) === []);
} finally {
    $pulisci();
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
