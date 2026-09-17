<?php

declare(strict_types=1);

namespace App\Core\Google;

use App\Core\Settings;

/**
 * Client minimale per le API Google con account di servizio.
 *
 * Firma una JWT assertion RS256 con openssl, la scambia per un access token
 * (flusso "JWT bearer") e la usa per chiamate REST autenticate. Nessuna
 * dipendenza esterna: bastano ext-openssl, ext-curl ed ext-json.
 *
 * Con la delega a livello di dominio (`GOOGLE_IMPERSONATE_EMAIL`) l'account di
 * servizio agisce per conto di un utente Workspace: e' la condizione necessaria
 * perche' Google generi un link Meet insieme all'evento di calendario.
 */
class ServiceAccountClient
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const TOKEN_LIFETIME = 3600;

    /** Margine di sicurezza sulla scadenza del token, in secondi. */
    private const EXPIRY_SKEW = 60;

    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private string $clientEmail,
        private string $privateKey,
        private ?string $impersonateEmail = null,
        private ?HttpTransport $transport = null,
    ) {
        $this->transport ??= new CurlTransport();
    }

    /**
     * Costruisce il client dalla configurazione (.env), oppure null se le
     * credenziali non sono presenti: in quel caso l'applicazione funziona
     * ugualmente, con i link Meet inseriti a mano.
     */
    public static function fromEnv(?HttpTransport $transport = null): ?self
    {
        $keyFile = (string) Settings::get('GOOGLE_SERVICE_ACCOUNT_JSON', '');

        if ($keyFile === '' || !is_file($keyFile)) {
            return null;
        }

        $credentials = json_decode((string) file_get_contents($keyFile), true);

        if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            error_log('[Google] File credenziali non valido: ' . $keyFile);

            return null;
        }

        $impersonate = (string) Settings::get('GOOGLE_IMPERSONATE_EMAIL', '');

        return new self(
            (string) $credentials['client_email'],
            (string) $credentials['private_key'],
            $impersonate === '' ? null : $impersonate,
            $transport
        );
    }

    /**
     * Richiesta autenticata che restituisce il JSON decodificato.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     * @throws GoogleException
     */
    public function requestJson(string $method, string $url, string $scope, ?array $payload = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken($scope),
            'Accept' => 'application/json',
        ];

        $body = null;

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = $this->transport->send($method, $url, $headers, $body);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new GoogleException(sprintf(
                'Google ha risposto %d: %s',
                $response['status'],
                $this->errorMessage($response['body'])
            ));
        }

        if (trim($response['body']) === '') {
            return [];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            throw new GoogleException('Risposta di Google non interpretabile come JSON.');
        }

        return $decoded;
    }

    /**
     * Access token valido per lo scope indicato (con cache per richiesta).
     *
     * @throws GoogleException
     */
    public function accessToken(string $scope): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - self::EXPIRY_SKEW) {
            return $this->accessToken;
        }

        $response = $this->transport->send(
            'POST',
            self::TOKEN_ENDPOINT,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => self::ASSERTION_TYPE,
                'assertion' => $this->buildAssertion($scope),
            ])
        );

        if ($response['status'] !== 200) {
            throw new GoogleException(
                'Autenticazione con Google fallita: ' . $this->errorMessage($response['body'])
            );
        }

        $token = json_decode($response['body'], true);

        if (!is_array($token) || empty($token['access_token'])) {
            throw new GoogleException('Google non ha restituito un access token.');
        }

        $this->accessToken = (string) $token['access_token'];
        $this->accessTokenExpiresAt = time() + (int) ($token['expires_in'] ?? self::TOKEN_LIFETIME);

        return $this->accessToken;
    }

    /**
     * JWT firmata con la chiave privata dell'account di servizio.
     *
     * @throws GoogleException
     */
    public function buildAssertion(string $scope, ?int $issuedAt = null): string
    {
        $issuedAt ??= time();

        $claims = [
            'iss' => $this->clientEmail,
            'scope' => $scope,
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TOKEN_LIFETIME,
        ];

        // Con la delega a livello di dominio l'account di servizio agisce
        // "per conto di" questo utente del dominio.
        if ($this->impersonateEmail !== null) {
            $claims['sub'] = $this->impersonateEmail;
        }

        $signingInput = self::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.' . self::base64UrlEncode((string) json_encode($claims));

        $key = openssl_pkey_get_private($this->privateKey);

        if ($key === false) {
            throw new GoogleException('Chiave privata dell\'account di servizio non valida.');
        }

        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GoogleException('Firma della JWT per Google non riuscita.');
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    public function impersonateEmail(): ?string
    {
        return $this->impersonateEmail;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Estrae il messaggio d'errore dalla risposta, senza riversare in pagina
     * il JSON completo di Google.
     */
    private function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return (string) ($decoded['error']['message']
                ?? $decoded['error_description']
                ?? $decoded['error']
                ?? 'errore non specificato');
        }

        return substr(trim($body), 0, 200) ?: 'risposta vuota';
    }
}
