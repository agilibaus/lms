<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Settings;

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

        $from = (string) Settings::get('MAIL_FROM_ADDRESS', 'no-reply@localhost');
        $fromName = (string) Settings::get('MAIL_FROM_NAME', 'Pistacchio LMS');

        self::$transport = match (strtolower((string) Settings::get('MAIL_TRANSPORT', 'log'))) {
            'smtp' => new SmtpTransport(
                (string) Settings::get('MAIL_HOST', 'localhost'),
                (int) Settings::get('MAIL_PORT', '587'),
                (string) Settings::get('MAIL_USERNAME', ''),
                (string) Settings::get('MAIL_PASSWORD', ''),
                strtolower((string) Settings::get('MAIL_ENCRYPTION', 'tls')),
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
        return strtolower((string) Settings::get('MAIL_TRANSPORT', 'log')) === 'log';
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

    /**
     * Password temporanea generata da un amministratore.
     *
     * L'unica copia della password e' questa: l'admin la genera ma non la
     * vede, e in piattaforma resta solo la sua impronta.
     */
    public static function temporaryPassword(
        string $email,
        string $name,
        string $password,
        string $link
    ): Message {
        return new Message(
            $email,
            $name,
            'Password temporanea per il tuo account',
            "Ciao {$name},\n\n"
            . "chi amministra Pistacchio LMS ha generato una password temporanea per il tuo\n"
            . "account. La password precedente non funziona più, e le sessioni eventualmente\n"
            . "aperte sono state chiuse.\n\n"
            . "Password temporanea: {$password}\n\n"
            . "Entra da qui:\n\n"
            . "{$link}\n\n"
            . "Al primo accesso ti verrà chiesto di sceglierne una tua: fino ad allora non\n"
            . "potrai usare il resto della piattaforma. Non rispondere a questo messaggio\n"
            . "lasciando la password nel testo.\n"
        );
    }

    /**
     * Invito a chi e' stato importato da un elenco.
     *
     * NON SI RIUSA `temporaryPassword()`, ed e' la seconda volta che questo
     * progetto lo scopre dopo averlo fatto: quel testo dice «la password
     * precedente non funziona piu'» e «le sessioni aperte sono state
     * chiuse», due frasi vere per un account esistente e **false** per uno
     * appena creato, che una password precedente non ce l'ha mai avuta. Chi
     * la riceve si chiede quale password abbia perso, e quando abbia aperto
     * una sessione.
     */
    public static function invite(
        string $email,
        string $name,
        string $password,
        string $link
    ): Message {
        return new Message(
            $email,
            $name,
            'Il tuo accesso a Pistacchio LMS',
            "Ciao {$name},\n\n"
            . "è stato creato un account per te su Pistacchio LMS, la piattaforma dei corsi.\n\n"
            . "Indirizzo con cui entrare: {$email}\n"
            . "Password provvisoria: {$password}\n\n"
            . "Entra da qui:\n\n"
            . "{$link}\n\n"
            . "Al primo accesso ti verrà chiesto di scegliere una password tua: fino ad\n"
            . "allora non potrai usare il resto della piattaforma. Non rispondere a questo\n"
            . "messaggio lasciando la password nel testo.\n"
        );
    }

    /**
     * Avviso all'utente che la sua password e' cambiata. Non contiene la
     * password: serve solo a far accorgere di un cambio non voluto.
     */
    public static function passwordChanged(string $email, string $name, string $link): Message
    {
        return new Message(
            $email,
            $name,
            'La password del tuo account è stata cambiata',
            "Ciao {$name},\n\n"
            . "la password del tuo account Pistacchio LMS è appena stata cambiata, e le altre\n"
            . "sessioni aperte sono state chiuse.\n\n"
            . "Se sei stato tu, non devi fare nulla.\n\n"
            . "Se non sei stato tu, qualcuno conosce la tua password: reimpostala subito da\n"
            . "\"Password dimenticata\" nella pagina di accesso, e avvisa chi amministra la\n"
            . "piattaforma.\n\n"
            . "{$link}\n"
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

    /**
     * Un modulo a rilascio programmato si e' aperto (§8.7).
     *
     * La manda il comando `bin/rilascio-moduli`, non una richiesta web:
     * nessuno ha premuto niente, e' passata una data.
     */
    public static function moduleUnlocked(
        string $email,
        string $name,
        string $moduleTitle,
        string $courseTitle,
        string $link
    ): Message {
        return new Message(
            $email,
            $name,
            'Nuovo modulo disponibile: ' . $moduleTitle,
            "Ciao {$name},\n\n"
            . "nel corso \"{$courseTitle}\" si è aperto il modulo \"{$moduleTitle}\".\n\n"
            . "Puoi cominciarlo da qui:\n{$link}\n\n"
            // Il saluto chiude solo questa email, per scelta del 01/10. E'
            // l'unica che annuncia qualcosa di bello invece di chiedere
            // un'azione o confermare un fatto. Se un giorno si decide di
            // dare una chiusura a tutti i messaggi, questa e' la forma da
            // ripetere, non un'eccezione da togliere.
            . "Buono studio!\n"
            . "Pistacchio\n"
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
