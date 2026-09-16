<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Scrive i messaggi in storage/mail invece di spedirli.
 *
 * È il trasporto pensato per lo sviluppo locale: i link di verifica e di reset
 * si leggono aprendo il file .eml, senza configurare alcun server SMTP.
 */
class LogTransport implements Transport
{
    public function __construct(
        private string $directory,
        private string $fromEmail,
        private string $fromName = '',
    ) {
    }

    public function send(Message $message): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MailException('Impossibile creare la cartella dei messaggi: ' . $this->directory);
        }

        $file = sprintf(
            '%s/%s-%s.eml',
            rtrim($this->directory, '/'),
            date('Ymd-His'),
            substr(preg_replace('/[^a-z0-9]+/i', '-', $message->toEmail) ?? 'mail', 0, 40)
        );

        if (file_put_contents($file, $message->toRfc822($this->fromEmail, $this->fromName)) === false) {
            throw new MailException('Impossibile scrivere il messaggio in ' . $file);
        }
    }
}
