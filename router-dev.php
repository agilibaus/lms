<?php

declare(strict_types=1);

/**
 * Instradatore per il server di sviluppo di PHP. **Solo per lo sviluppo.**
 *
 * Esecuzione:  php -S 127.0.0.1:8123 -t public router-dev.php
 *
 * PERCHE' ESISTE. Il server integrato di PHP non ha `.htaccess`: senza
 * questo file, `/reports/elenco/students` cerca una cartella con quel nome,
 * non la trova e risponde 404, mentre in produzione Apache riscrive tutto
 * verso `public/index.php`. I controlli automatici che hanno bisogno di un
 * server vero (`accessibilita.js`, `permessi.js`, `coerenza_moduli.js`) lo
 * citano nelle istruzioni di esecuzione, ma il file non era nel repo:
 * chi clonava non poteva eseguirli. Aggiunto con la 0086.
 *
 * Restituendo `false` si dice al server integrato «questo file esiste sul
 * disco, servilo tu»: e' cosi' che fogli di stile, script e immagini sotto
 * `public/` arrivano al browser senza passare dall'applicazione.
 */

$percorso = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($percorso !== '/' && is_file(__DIR__ . '/public' . $percorso)) {
    return false;
}

require __DIR__ . '/public/index.php';
