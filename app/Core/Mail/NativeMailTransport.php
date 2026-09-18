<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Trasporto basato sulla funzione mail() di PHP.
 *
 * Dipende da un MTA configurato sul server (sendmail o equivalente). È la scelta
 * di chi ha un hosting che lo fornisce già; su Windows in locale non funziona.
 */
class NativeMailTransport implements Transport
{
    public function __construct(
        private string $fromEmail,
        private string $fromName = '',
    ) {
    }

    public function send(Message $message): void
    {
        $headers = array_merge(
            [
                'From: ' . (($this->fromName !== '' ? Message::encodeHeader($this->fromName) . ' ' : '') . '<' . $this->fromEmail . '>'),
                'MIME-Version: 1.0',
            ],
            // Con un allegato qui passa l'involucro multipart, e il corpo
            // contiene gia' le parti: mail() non deve saperne nulla.
            $message->contentHeaders()
        );

        $sent = mail(
            $message->recipient(),
            Message::encodeHeader($message->subject),
            $message->mimeBody(),
            implode("\r\n", $headers)
        );

        if (!$sent) {
            throw new MailException('La funzione mail() di PHP non è riuscita a consegnare il messaggio.');
        }
    }
}
