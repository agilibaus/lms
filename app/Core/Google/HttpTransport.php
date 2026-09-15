<?php

declare(strict_types=1);

namespace App\Core\Google;

/**
 * Trasporto HTTP usato dal client Google.
 *
 * È un'interfaccia (e non una chiamata cURL diretta nel client) per poter
 * sostituire la rete con una risposta preconfezionata nei test, senza
 * dipendere da credenziali reali.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): array;
}
