<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Invio non riuscito. Chi la intercetta decide se la cosa sia bloccante:
 * per la verifica dell'indirizzo lo è, per un avviso al tutor no.
 */
class MailException extends \RuntimeException
{
}
