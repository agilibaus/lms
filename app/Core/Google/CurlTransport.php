<?php

declare(strict_types=1);

namespace App\Core\Google;

/**
 * Trasporto HTTP predefinito, basato su cURL.
 */
class CurlTransport implements HttpTransport
{
    public function __construct(private int $timeoutSeconds = 15)
    {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new GoogleException('Impossibile inizializzare la richiesta HTTP verso Google.');
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new GoogleException('Richiesta a Google fallita: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
