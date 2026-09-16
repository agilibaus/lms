<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Un modo di recapitare un messaggio. Le implementazioni sono tre: SMTP,
 * la funzione mail() di PHP e il salvataggio su file per lo sviluppo locale.
 */
interface Transport
{
    /**
     * @throws MailException se il messaggio non è stato consegnato al server
     */
    public function send(Message $message): void;
}
