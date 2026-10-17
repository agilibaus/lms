<?php

declare(strict_types=1);

/**
 * Gli studenti in piu' per `tests/ordinamento_pagine.js` (17/10).
 *
 * PERCHE'. L'elenco degli studenti dei report mostra 50 righe per pagina
 * (`ReportSections::PER_PAGINA`). Con meno di 51 studenti la seconda pagina
 * non c'e', e la prova «l'ordine sopravvive alla paginazione» restava senza
 * niente da provare: un FAIL fisso, e i due controlli che ne dipendono
 * saltati. Qui si creano abbastanza studenti di prova da avere sempre
 * almeno due pagine, qualunque sia il database.
 *
 * Sono utenti veri ma riconoscibili (elenco-NN@test.it, nome «[prova-elenco]»),
 * senza gruppi ne' iscrizioni: compaiono solo negli elenchi di tutti gli
 * utenti. La prova li crea all'inizio e li toglie alla fine; se una prova
 * si interrompe a meta', il giro dopo li ritrova e li riusa.
 *
 * Esecuzione:  php tests/semina_elenco.php crea|togli
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\ReportSections;

$pdo = Database::connection();
$azione = $argv[1] ?? '';

if ($azione === 'togli') {
    $n = $pdo->exec("DELETE FROM users WHERE email LIKE 'elenco-%@test.it' AND first_name = '[prova-elenco]'");
    echo "tolti {$n}\n";
    exit(0);
}

if ($azione !== 'crea') {
    fwrite(STDERR, "Uso: php tests/semina_elenco.php crea|togli\n");
    exit(1);
}

// Abbastanza per due pagine anche su un database vuoto.
$quanti = ReportSections::PER_PAGINA + 5;
$hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
$ins = $pdo->prepare(
    "INSERT INTO users (email, password_hash, first_name, last_name, role, is_active, email_verified_at, welcome_seen_at, name_display)
     VALUES (:e, :p, '[prova-elenco]', :c, 'studente', 1, NOW(), NOW(), 'full')
     ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), role = 'studente'"
);

for ($i = 1; $i <= $quanti; $i++) {
    $ins->execute(['e' => sprintf('elenco-%02d@test.it', $i), 'p' => $hash, 'c' => sprintf('studente %02d', $i)]);
}

echo "creati {$quanti}\n";
