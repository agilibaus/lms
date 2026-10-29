<?php

declare(strict_types=1);

/**
 * Test dell'orologio del database (29/10, revisione del codice): la
 * connessione usa lo stesso scarto orario di PHP (APP_TIMEZONE), perche' le
 * date dei moduli e degli incontri si scrivono in ora italiana e si
 * confrontano con NOW() in SQL.
 *
 * Esecuzione:  php tests/fuso_orario_test.php
 *
 * Sul computer di sviluppo MySQL e PHP sono gia' sullo stesso fuso, e la
 * seconda prova passerebbe anche senza la correzione: la prima no. Per
 * vederla fallire davvero si mette MySQL in UTC (`SET GLOBAL time_zone =
 * '+00:00'`, poi `'SYSTEM'`) e si toglie la riga in `Database::connection()`.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;

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

$pdo = Database::connection();
$fuso = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
check('la connessione usa lo scarto di PHP (' . date('P') . ')', $fuso === date('P'), $fuso);

$sql = (string) $pdo->query('SELECT NOW()')->fetchColumn();
$scarto = abs(strtotime($sql) - time());
check('NOW() del database e l\'ora di PHP coincidono', $scarto < 5, $sql . ' contro ' . date('Y-m-d H:i:s'));

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
