<?php

declare(strict_types=1);

namespace App\Core\Google;

/**
 * Errore nel dialogo con le API Google.
 *
 * L'applicazione non deve mai fermarsi per colpa di Google: chi la intercetta
 * salva comunque la sessione live e lascia al tutor il link Meet manuale.
 */
class GoogleException extends \RuntimeException
{
}
