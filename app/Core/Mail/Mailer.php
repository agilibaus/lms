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
        // Senza un nome impostato, le email partono con quello
        // dell'organizzazione (12/10).
        $fromName = Modelli::nomeMittente();

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
    //
    // Oggetto e testo stanno in `Modelli` (12/10): qui solo i valori dei
    // segnaposto propri di ogni email. Il nome di chi riceve diventa il solo
    // nome di battesimo, letto dal suo account.
    // ---------------------------------------------------------------

    public static function verification(string $email, string $name, string $link): Message
    {
        return Modelli::messaggio('verifica', $email, $name, ['{link}' => $link]);
    }

    public static function passwordReset(string $email, string $name, string $link): Message
    {
        return Modelli::messaggio('reimposta', $email, $name, ['{link}' => $link]);
    }

    /**
     * Password temporanea generata da un amministratore per un account che
     * esiste gia'.
     *
     * L'unica copia della password e' questa: l'admin la genera ma non la
     * vede, e in piattaforma resta solo la sua impronta.
     */
    public static function temporaryPassword(string $email, string $name, string $password, string $link): Message
    {
        return Modelli::messaggio('password_temporanea', $email, $name, ['{password}' => $password, '{link}' => $link]);
    }

    /**
     * «Il tuo accesso»: a chi riceve un account nuovo, creato da chi
     * amministra o importato da un elenco.
     *
     * NON SI RIUSA `temporaryPassword()`, ed e' la terza volta che questo
     * progetto lo scopre (la creazione dal pannello lo faceva fino al 12/10):
     * quel testo dice «la password precedente non funziona piu'» e «le
     * sessioni aperte sono state chiuse», due frasi vere per un account
     * esistente e **false** per uno appena creato, che una password
     * precedente non ce l'ha mai avuta.
     */
    public static function invite(string $email, string $name, string $password, string $link): Message
    {
        return Modelli::messaggio('accesso', $email, $name, [
            '{email}' => $email,
            '{password}' => $password,
            '{link}' => $link,
        ]);
    }

    /**
     * Avviso all'utente che la sua password e' cambiata. Non contiene la
     * password: serve solo a far accorgere di un cambio non voluto.
     */
    public static function passwordChanged(string $email, string $name, string $link): Message
    {
        return Modelli::messaggio('password_cambiata', $email, $name, ['{link}' => $link]);
    }

    public static function enrollmentConfirmed(string $email, string $name, string $courseTitle, string $link): Message
    {
        return Modelli::messaggio('iscrizione_confermata', $email, $name, ['{corso}' => $courseTitle, '{link}' => $link]);
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
        return Modelli::messaggio('nuovo_modulo', $email, $name, [
            '{corso}' => $courseTitle,
            '{modulo}' => $moduleTitle,
            '{link}' => $link,
        ]);
    }

    /**
     * @param string $message quello che lo studente ha scritto nella
     *                        richiesta; vuoto se non ha scritto niente
     */
    public static function enrollmentRequested(
        string $email,
        string $name,
        string $studentName,
        string $courseTitle,
        string $link,
        string $message = ''
    ): Message {
        $message = trim($message);

        return Modelli::messaggio('richiesta_iscrizione', $email, $name, [
            '{studente}' => $studentName,
            '{corso}' => $courseTitle,
            '{messaggio}' => $message !== '' ? 'Ha scritto: «' . $message . '»' : '',
            '{link}' => $link,
        ]);
    }

    /**
     * Una domanda nuova di uno studente (07/10). Con il testo intero, cosi'
     * si capisce dall'email se e' urgente.
     */
    public static function newQuestion(
        string $email,
        string $name,
        string $studentName,
        string $courseTitle,
        string $moduleTitle,
        string $question,
        string $link
    ): Message {
        return Modelli::messaggio('nuova_domanda', $email, $name, [
            '{studente}' => $studentName,
            '{corso}' => $courseTitle,
            '{modulo}' => $moduleTitle,
            '{domanda}' => $question,
            '{link}' => $link,
        ]);
    }

    /** Il certificato e' stato emesso (12/10). */
    public static function courseCompleted(string $email, string $name, string $courseTitle, string $link): Message
    {
        return Modelli::messaggio('corso_completato', $email, $name, ['{corso}' => $courseTitle, '{link}' => $link]);
    }

    /** L'esperto ha pubblicato la risposta alla domanda di uno studente (12/10). */
    public static function questionAnswered(
        string $email,
        string $name,
        string $courseTitle,
        string $question,
        string $answer,
        string $link
    ): Message {
        return Modelli::messaggio('risposta_esperto', $email, $name, [
            '{corso}' => $courseTitle,
            '{domanda}' => $question,
            '{risposta}' => $answer,
            '{link}' => $link,
        ]);
    }

    public static function enrollmentRejected(string $email, string $name, string $courseTitle): Message
    {
        return Modelli::messaggio('richiesta_rifiutata', $email, $name, ['{corso}' => $courseTitle]);
    }
}
