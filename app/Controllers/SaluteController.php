<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Salute;

/**
 * `/salute` (23/11): «ok» con 200 se Pistacchio risponde, database
 * compreso; 503 altrimenti, senza dire altro. La apre `bin/sorveglia`
 * dall'indirizzo pubblico, come un visitatore: passa per DNS, certificato,
 * server web, PHP e database. Pubblica perche' non c'e' niente da
 * proteggere, e nessuna pagina la collega.
 */
class SaluteController
{
    public function show(array $params = []): void
    {
        $bene = Salute::risponde();

        http_response_code($bene ? 200 : 503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $bene ? 'ok' : 'non disponibile';
    }
}
