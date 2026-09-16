<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Client SMTP minimale: connessione, EHLO, STARTTLS, autenticazione e invio.
 *
 * Scritto in casa come il client Google, per non aggiungere dipendenze a un
 * progetto che ne ha volutamente poche. Copre quello che serve a un LMS:
 * pochi messaggi transazionali verso un server SMTP con autenticazione.
 */
class SmtpTransport implements Transport
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $username = '',
        private string $password = '',
        private string $encryption = 'tls',
        private string $fromEmail = '',
        private string $fromName = '',
        private int $timeout = 15,
    ) {
    }

    public function send(Message $message): void
    {
        $this->connect();

        try {
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $this->fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $message->toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);

            $this->write($message->toRfc822($this->fromEmail, $this->fromName) . self::CRLF . '.' . self::CRLF);
            $this->expect([250]);

            $this->command('QUIT', [221]);
        } finally {
            $this->disconnect();
        }
    }

    // ---------------------------------------------------------------

    private function connect(): void
    {
        // 'ssl' significa canale cifrato da subito (porta 465); 'tls' parte in
        // chiaro e passa a cifrato con STARTTLS (porta 587).
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->timeout
        );

        if ($socket === false) {
            throw new MailException(sprintf(
                'Connessione al server SMTP %s:%d non riuscita: %s',
                $this->host,
                $this->port,
                $errorMessage !== '' ? $errorMessage : 'errore ' . $errorCode
            ));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->expect([220]);
    }

    private function handshake(): void
    {
        $hostname = gethostname() ?: 'localhost';
        $this->command('EHLO ' . $hostname, [250]);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', [220]);

            if (!stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            )) {
                throw new MailException('Attivazione di STARTTLS non riuscita.');
            }

            // Dopo STARTTLS il dialogo ricomincia: va rifatto EHLO.
            $this->command('EHLO ' . $hostname, [250]);
        }
    }

    private function authenticate(): void
    {
        if ($this->username === '') {
            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);
        $this->command(base64_encode($this->password), [235]);
    }

    /**
     * @param int[] $expectedCodes
     */
    private function command(string $command, array $expectedCodes): string
    {
        $this->write($command . self::CRLF);

        return $this->expect($expectedCodes);
    }

    private function write(string $data): void
    {
        if ($this->socket === null || fwrite($this->socket, $data) === false) {
            throw new MailException('Scrittura verso il server SMTP non riuscita.');
        }
    }

    /**
     * Legge la risposta (anche su più righe) e verifica il codice.
     *
     * @param int[] $expectedCodes
     */
    private function expect(array $expectedCodes): string
    {
        $response = '';

        while (true) {
            $line = fgets($this->socket, 4096);

            if ($line === false) {
                throw new MailException('Il server SMTP ha chiuso la connessione senza rispondere.');
            }

            $response .= $line;

            // Una risposta su più righe ha un trattino dopo il codice: "250-..."
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            throw new MailException('Il server SMTP ha risposto: ' . trim($response));
        }

        return $response;
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
