<?php

declare(strict_types=1);

/**
 * Test del cambio password: generatore delle password temporanee, testi
 * delle due email nuove, e — con il database — le colonne che fanno cadere
 * le altre sessioni.
 *
 * Esecuzione:  php tests/password_test.php
 *
 * L'ultima parte richiede il database: lavora su un utente di prova con un
 * identificativo altissimo, che viene creato all'inizio e tolto alla fine.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\Mail\Mailer;
use App\Core\PasswordGenerator;
use App\Core\PasswordPolicy;
use App\Models\UserModel;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

echo PHP_EOL . '--- Generatore di password temporanee' . PHP_EOL;

$password = PasswordGenerator::genera();

check('lunghezza predefinita di 14 caratteri', strlen($password) === 14);
check('lunghezza su richiesta', strlen(PasswordGenerator::genera(20)) === 20);

// Nascono per essere lette in un'email e ribattute a mano: i caratteri che si
// confondono fra loro sono esclusi apposta.
$confondibili = ['O', '0', 'l', 'I', '1'];
$trovati = [];

for ($i = 0; $i < 400; $i++) {
    foreach (str_split(PasswordGenerator::genera()) as $carattere) {
        if (in_array($carattere, $confondibili, true)) {
            $trovati[$carattere] = true;
        }
    }
}

check('nessun carattere confondibile in 400 password', $trovati === []);

$alfabeto = [];

for ($i = 0; $i < 400; $i++) {
    foreach (str_split(PasswordGenerator::genera()) as $carattere) {
        $alfabeto[$carattere] = true;
    }
}

check('usa maiuscole, minuscole e cifre', (bool) preg_match('/[A-Z]/', implode('', array_keys($alfabeto)))
    && (bool) preg_match('/[a-z]/', implode('', array_keys($alfabeto)))
    && (bool) preg_match('/[2-9]/', implode('', array_keys($alfabeto))));

$generate = [];

for ($i = 0; $i < 200; $i++) {
    $generate[] = PasswordGenerator::genera();
}

check('200 password tutte diverse', count(array_unique($generate)) === 200);

check('ogni password generata soddisfa la regola', (function (): bool {
    for ($i = 0; $i < 300; $i++) {
        if (PasswordPolicy::problem(PasswordGenerator::genera()) !== null) {
            return false;
        }
    }

    return true;
})());

// Una lunghezza sotto il minimo renderebbe la regola impossibile da
// soddisfare, e il generatore girerebbe all'infinito.
check('una lunghezza troppo corta viene alzata al minimo',
    strlen(PasswordGenerator::genera(3)) === PasswordPolicy::MIN_LENGTH);

echo PHP_EOL . '--- Regola delle password' . PHP_EOL;

check('sette caratteri non bastano', PasswordPolicy::problem('Abcde12') !== null);
check('otto con lettera e cifra vanno bene', PasswordPolicy::problem('Abcdef12') === null);
check('solo lettere non basta', PasswordPolicy::problem('Abcdefghij') !== null);
check('solo cifre non basta', PasswordPolicy::problem('1234567890') !== null);
check('solo simboli non basta', PasswordPolicy::problem('!!!!!!!!!!') !== null);
check('i simboli restano ammessi in piu\'', PasswordPolicy::problem('Abcde12!@#') === null);
check('vuota non basta', PasswordPolicy::problem('') !== null);

// La frase mostrata e la regola applicata devono restare la stessa cosa.
check('la frase mostrata parla di caratteri alfanumerici', str_contains(PasswordPolicy::HINT, 'alfanumerici'));
check('la frase mostrata dice la lunghezza minima', str_contains(PasswordPolicy::HINT, (string) PasswordPolicy::MIN_LENGTH));

echo PHP_EOL . '--- Testo delle email' . PHP_EOL;

$temporanea = Mailer::temporaryPassword('tizio@example.com', 'Tizio', 'AbCdEf234567', 'https://esempio.it/login');

check('la temporanea va all\'indirizzo giusto', $temporanea->toEmail === 'tizio@example.com');
check('la temporanea contiene la password', str_contains($temporanea->body, 'AbCdEf234567'));
check('la temporanea contiene il link di accesso', str_contains($temporanea->body, 'https://esempio.it/login'));
check('la temporanea avvisa del cambio obbligatorio', str_contains($temporanea->body, 'sceglierne una tua'));

$avviso = Mailer::passwordChanged('tizio@example.com', 'Tizio', 'https://esempio.it/login');

// L'avviso serve a far accorgere di un cambio non voluto: se contenesse la
// password, un'email intercettata basterebbe a prendersi l'account.
check('l\'avviso non contiene nessuna password', !str_contains($avviso->body, 'AbCdEf234567'));
check('l\'avviso dice cosa fare se non e\' stato l\'utente', str_contains($avviso->body, 'Password dimenticata'));

echo PHP_EOL . '--- Colonne del cambio password (richiede il database)' . PHP_EOL;

try {
    Database::connection();
} catch (Throwable $e) {
    echo '  Database non raggiungibile: questa parte non gira.' . PHP_EOL;
    echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
    exit($fail === 0 ? 0 : 1);
}

$db = Database::connection();
$idProva = 999123;

$db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $idProva]);
$db->prepare(
    'INSERT INTO users (id, email, password_hash, full_name, role, email_verified_at)
     VALUES (:id, :email, :hash, :nome, \'studente\', NOW())'
)->execute([
    'id' => $idProva,
    'email' => 'prova-password-' . $idProva . '@example.invalid',
    'hash' => password_hash('PrimaPassword1', PASSWORD_DEFAULT),
    'nome' => 'Utente di prova',
]);

try {
    $stato = UserModel::passwordState($idProva);

    check('appena creato, nessun cambio registrato', $stato !== null && $stato['password_changed_at'] === null);
    check('appena creato, nessuna password temporanea', $stato !== null && (int) $stato['must_change_password'] === 0);

    check(
        'l\'impronta si legge dal metodo suo',
        password_verify('PrimaPassword1', (string) UserModel::passwordHash($idProva))
    );

    $utente = UserModel::find($idProva);
    check('l\'impronta non gira insieme agli altri campi', $utente !== null && !array_key_exists('password_hash', $utente));

    UserModel::updatePassword($idProva, 'SecondaPassword2');
    $dopo = UserModel::passwordState($idProva);

    check('cambiando la password l\'istante viene scritto', $dopo !== null && $dopo['password_changed_at'] !== null);
    check('un cambio normale non chiede di ricambiarla', $dopo !== null && (int) $dopo['must_change_password'] === 0);
    check('la nuova password vale', password_verify('SecondaPassword2', (string) UserModel::passwordHash($idProva)));
    check('la vecchia non vale piu\'', !password_verify('PrimaPassword1', (string) UserModel::passwordHash($idProva)));

    // E' il confronto fra questo valore e la copia in sessione a far cadere
    // le altre sessioni: se non cambiasse, non cadrebbe niente.
    sleep(1);
    UserModel::updatePassword($idProva, 'TerzaPassword3', true);
    $temporanea = UserModel::passwordState($idProva);

    check(
        'un cambio successivo sposta l\'istante',
        $temporanea !== null && $dopo !== null
            && $temporanea['password_changed_at'] !== $dopo['password_changed_at']
    );
    check('la password generata dall\'admin chiede di cambiarla', $temporanea !== null && (int) $temporanea['must_change_password'] === 1);

    UserModel::updatePassword($idProva, 'QuartaPassword4');
    $finale = UserModel::passwordState($idProva);

    check('scegliendone una propria il vincolo cade', $finale !== null && (int) $finale['must_change_password'] === 0);

    check('per un utente che non esiste lo stato e\' null', UserModel::passwordState(999999999) === null);
} finally {
    $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $idProva]);
}

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
