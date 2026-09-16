<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Env;

/**
 * Punto unico di invio: sceglie il trasporto in base a `MAIL_TRANSPORT`
 * e compone i messaggi della piattaforma.
 *
 * Trasporti: `smtp` (server esterno), `mail` (funzione mail() di PHP),
 * `log` (salva i messaggi in storage/mail — predefinito, così un'installazione
 * appena fatta non tenta invii verso un server che non esiste).
 */
class Mailer
{
    private static ?Transport $transport = null;

    public static function transport(): Transport
    {
        if (self::$transport !== null) {
            return self::$transport;
        }

        $from = (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@localhost');
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Pistacchio LMS');

        self::$transport = match (strtolower((string) Env::get('MAIL_TRANSPORT', 'log'))) {
            'smtp' => new SmtpTransport(
                (string) Env::get('MAIL_HOST', 'localhost'),
                (int) Env::get('MAIL_PORT', '587'),
                (string) Env::get('MAIL_USERNAME', ''),
                (string) Env::get('MAIL_PASSWORD', ''),
                strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls')),
                $from,
                $fromName
            ),
            'mail' => new NativeMailTransport($from, $fromName),
            default => new LogTransport(__DIR__ . '/../../../storage/mail', $from, $fromName),
        };

        return self::$transport;
    }

    /**
     * Sostituisce il trasporto (usato dai test).
     */
    public static function setTransport(?Transport $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * @throws MailException
     */
    public static function send(Message $message): void
    {
        self::transport()->send($message);
    }

    /**
     * Invio "non bloccante": un avviso che non arriva non deve far fallire
     * l'operazione che lo ha generato (un'iscrizione, un'approvazione).
     */
    public static function sendQuietly(Message $message): bool
    {
        try {
            self::send($message);

            return true;
        } catch (MailException $e) {
            error_log('[Mail] ' . $e->getMessage());

            return false;
        }
    }

    public static function isLogTransport(): bool
    {
        return strtolower((string) Env::get('MAIL_TRANSPORT', 'log')) === 'log';
    }

    // ---------------------------------------------------------------
    // Messaggi della piattaforma
    // ---------------------------------------------------------------

    public static function verification(string $email, string $name, string $link): Message
    {
        return new Message(
            $email,
            $name,
            'Conferma il tuo indirizzo email',
            "Ciao {$name},\n\n"
            . "per completare la registrazione a Pistacchio LMS conferma il tuo indirizzo email\n"
            . "aprendo questo link:\n\n"
            . "{$link}\n\n"
            . "Il link resta valido 24 ore. Se non hai richiesto tu la registrazione, ignora\n"
            . "questo messaggio: senza conferma l'account non viene attivato.\n"
        );
    }

    public static function passwordReset(string $email, string $name, string $link): Message
    {
        return new Message(
            $email,
            $name,
            'Reimposta la password',
            "Ciao {$name},\n\n"
            . "hai chiesto di reimpostare la password del tuo account Pistacchio LMS.\n"
            . "Puoi farlo da qui:\n\n"
            . "{$link}\n\n"
            . "Il link vale un'ora e può essere usato una sola volta. Se non sei stato tu,\n"
            . "non devi fare nulla: la password attuale resta valida.\n"
        );
    }

    public static function enrollmentConfirmed(string $email, string $name, string $courseTitle, string $link): Message
    {
        return new Message(
            $email,
            $name,
            'Iscrizione confermata: ' . $courseTitle,
            "Ciao {$name},\n\n"
            . "sei iscritto al corso \"{$courseTitle}\".\n\n"
            . "Puoi iniziare da qui:\n{$link}\n"
        );
    }

    public static function enrollmentRequested(
        string $email,
        string $name,
        string $studentName,
        string $courseTitle,
        string $link
    ): Message {
        return new Message(
            $email,
            $name,
            'Richiesta di iscrizione: ' . $courseTitle,
            "Ciao {$name},\n\n"
            . "{$studentName} ha chiesto di iscriversi al corso \"{$courseTitle}\".\n\n"
            . "Puoi approvare o rifiutare la richiesta qui:\n{$link}\n"
        );
    }

    public static function enrollmentRejected(string $email, string $name, string $courseTitle): Message
    {
        return new Message(
            $email,
            $name,
            'Richiesta non accolta: ' . $courseTitle,
            "Ciao {$name},\n\n"
            . "la tua richiesta di iscrizione al corso \"{$courseTitle}\" non è stata accolta.\n"
            . "Per capirne il motivo puoi contattare chi tiene il corso.\n"
        );
    }
}
