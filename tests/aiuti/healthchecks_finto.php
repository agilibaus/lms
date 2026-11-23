<?php

declare(strict_types=1);

/**
 * Un Healthchecks.io finto, per le prove della sorveglianza (23/11): il
 * contenitore di sviluppo non raggiunge il servizio vero, e una prova non
 * deve toccare i check veri.
 *
 *   php -S 127.0.0.1:8125 tests/aiuti/healthchecks_finto.php
 *
 * Ogni richiesta finisce in una riga JSON di HC_FINTO_REGISTRO (metodo,
 * indirizzo, corpo). Risponde «OK» con 200; «not found» con 404 ai nomi
 * scritti in HC_FINTO_SCONOSCIUTI (uno per riga); aspetta 8 secondi
 * sugli indirizzi che contengono «lento».
 */

$registro = getenv('HC_FINTO_REGISTRO') ?: sys_get_temp_dir() . '/hc_finto.log';
$sconosciuti = getenv('HC_FINTO_SCONOSCIUTI') ?: sys_get_temp_dir() . '/hc_finto_404.txt';
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

file_put_contents($registro, json_encode([
    'metodo' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $uri,
    'corpo' => (string) file_get_contents('php://input'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

if (str_contains($uri, 'lento')) {
    sleep(8);
}

$nomi = is_file($sconosciuti) ? array_filter(array_map('trim', file($sconosciuti) ?: [])) : [];
foreach ($nomi as $nome) {
    if (preg_match('#/' . preg_quote($nome, '#') . '(/fail)?$#', $uri) === 1) {
        http_response_code(404);
        echo 'not found';
        return true;
    }
}

echo 'OK';
return true;
