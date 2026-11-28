<?php

declare(strict_types=1);

/**
 * Un gruppo grande per `tests/gruppo_anelli.js` (27/11): il tutor di prova e
 * N partecipanti, fra cui lo studente di prova (`stud@test.it`), che cosi'
 * vede in cima al riquadro «Gli altri ti vedono come …».
 *
 * I partecipanti inventati si riconoscono dall'indirizzo
 * `gruppo-anelli-NN@test.it`, il gruppo dal nome «[gruppo-anelli] prova». Uno
 * ha un nome lungo, che va su tre righe. `crea` ricomincia da capo e stampa
 * l'id del gruppo; `togli` toglie tutto.
 *
 * Esecuzione:  php tests/gruppo_anelli_semina.php crea N|togli
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;

$pdo = Database::connection();
$azione = $argv[1] ?? '';

$pdo->exec("DELETE FROM `groups` WHERE name = '[gruppo-anelli] prova'");
$pdo->exec("DELETE FROM users WHERE email LIKE 'gruppo-anelli-%@test.it'");

if ($azione === 'togli') {
    echo "tolti\n";
    exit(0);
}

$n = (int) ($argv[2] ?? 0);

if ($azione !== 'crea' || $n < 1 || $n > 99) {
    fwrite(STDERR, "Uso: php tests/gruppo_anelli_semina.php crea N|togli\n");
    exit(1);
}

$nomi = ['Giulia', 'Marco', 'Francesca', 'Luca', 'Chiara', 'Alessandro', 'Sara', 'Matteo', 'Elena', 'Davide',
    'Martina', 'Simone', 'Valentina', 'Andrea', 'Federica', 'Lorenzo', 'Alessandra', 'Stefano', 'Silvia', 'Paolo',
    'Roberta', 'Nicola', 'Anna', 'Giovanni', 'Laura', 'Riccardo', 'Ilaria', 'Michele', 'Claudia', 'Fabio',
    'Beatrice', 'Tommaso', 'Serena', 'Daniele', 'Camilla', 'Emanuele', 'Veronica', 'Pietro', 'Monica', 'Filippo',
    'Eleonora', 'Gabriele', 'Cristina', 'Massimo', 'Irene', 'Antonio', 'Noemi', 'Giorgio', 'Rebecca', 'Leonardo',
    'Agnese', 'Bruno', 'Carla', 'Dario', 'Emma'];
$cognomi = ['Rossi', 'Bianchi', 'Esposito', 'Colombo', 'Ricci', 'Bonaventura Cavalieri', 'Greco', 'Bruno', 'Gallo', 'Conti',
    'De Luca', 'Mancini', 'Costa', 'Giordano', 'Rizzo', 'Lombardi', 'Moretti', 'Barbieri', 'Fontana', 'Santoro',
    'Mariani', 'Rinaldi', 'Caruso', 'Ferrara', 'Galli', 'Martini', 'Leone', 'Longo', 'Gentile', 'Martinelli',
    'Vitale', 'Lombardo', 'Serra', 'Coppola', 'De Santis', "D'Angelo", 'Marchetti', 'Parisi', 'Villa', 'Conte',
    'Ferraro', 'Ferri', 'Fabbri', 'Bianco', 'Marini', 'Grasso', 'Valentini', 'Messina', 'Sala', 'Marino',
    'Neri', 'Pace', 'Riva', 'Testa', 'Monti'];

$tutor = (int) $pdo->query("SELECT id FROM users WHERE email = 'tutor1@test.it'")->fetchColumn();
$pdo->prepare('INSERT INTO `groups` (name, tutor_id) VALUES (?, ?)')->execute(['[gruppo-anelli] prova', $tutor]);
$gruppo = (int) $pdo->lastInsertId();

$membro = $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)');
$membro->execute([$gruppo, (int) $pdo->query("SELECT id FROM users WHERE email = 'stud@test.it'")->fetchColumn()]);

$hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
$utente = $pdo->prepare(
    "INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at, name_display)
     VALUES (?, ?, ?, ?, 'studente', 1, NOW(), NOW(), 'full')"
);

for ($i = 0; $i < $n - 1; $i++) {
    $utente->execute([sprintf('gruppo-anelli-%02d@test.it', $i), $hash, $nomi[$i % count($nomi)], $cognomi[$i % count($cognomi)]]);
    $membro->execute([$gruppo, (int) $pdo->lastInsertId()]);
}

echo $gruppo . "\n";
