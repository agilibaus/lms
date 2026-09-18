<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Ics;
use App\Core\Settings;
use App\Core\Url;

/**
 * I tre messaggi delle sessioni live: invito, cambio di orario, annullamento.
 *
 * Oggetto e testo stanno in tabella `settings` con gli stessi nomi delle altre
 * chiavi, e si modificano dal pannello; se non sono stati scritti valgono i
 * testi predefiniti qui sotto. Svuotare un campo nel pannello significa
 * tornare a quello predefinito, come per le altre impostazioni svuotare un
 * campo restituisce il comando al .env.
 *
 * I segnaposto sconosciuti restano scritti come sono: un {tiotlo} sbagliato si
 * vede nel messaggio di prova invece di sparire in silenzio.
 */
class LiveSessionMail
{
    public const SUBJECT_KEYS = [
        'LIVE_INVITE_SUBJECT',
        'LIVE_UPDATE_SUBJECT',
        'LIVE_CANCEL_SUBJECT',
    ];

    public const BODY_KEYS = [
        'LIVE_INVITE_BODY',
        'LIVE_UPDATE_BODY',
        'LIVE_CANCEL_BODY',
    ];

    /** @var array<string, string> */
    public const DEFAULTS = [
        'LIVE_INVITE_SUBJECT' => 'Invito: {titolo} — {data} alle {ora_inizio}',
        'LIVE_INVITE_BODY' => <<<'TESTO'
            Ciao {nome},

            ti aspettiamo all'incontro dal vivo "{titolo}".

            Quando: {data}, dalle {ora_inizio} alle {ora_fine}
            Dove: {link_meet}

            {descrizione}

            La pagina dell'incontro su {piattaforma}:
            {link_sessione}

            In allegato trovi il file per aggiungere l'incontro al tuo calendario.
            TESTO,

        'LIVE_UPDATE_SUBJECT' => 'Cambio di orario: {titolo}',
        'LIVE_UPDATE_BODY' => <<<'TESTO'
            Ciao {nome},

            l'incontro "{titolo}" è stato spostato.

            Prima era: {data_precedente}, dalle {ora_inizio_precedente} alle {ora_fine_precedente}
            Adesso è: {data}, dalle {ora_inizio} alle {ora_fine}

            Il collegamento per partecipare non cambia:
            {link_meet}

            La pagina dell'incontro su {piattaforma}:
            {link_sessione}

            In allegato il file aggiornato per il calendario.
            TESTO,

        'LIVE_CANCEL_SUBJECT' => 'Annullato: {titolo} del {data}',
        'LIVE_CANCEL_BODY' => <<<'TESTO'
            Ciao {nome},

            l'incontro "{titolo}", previsto per il {data} alle {ora_inizio}, è stato annullato.

            Ci scusiamo per il cambiamento.
            TESTO,
    ];

    /**
     * I segnaposto utilizzabili, con la spiegazione mostrata nel pannello.
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            '{nome}' => 'nome di chi riceve il messaggio',
            '{titolo}' => 'titolo dell’incontro',
            '{descrizione}' => 'descrizione dell’incontro, se c’è',
            '{data}' => 'data dell’incontro (18/09/2026)',
            '{ora_inizio}' => 'ora di inizio (18:30)',
            '{ora_fine}' => 'ora di fine (20:00)',
            '{link_meet}' => 'indirizzo della riunione su Google Meet',
            '{link_sessione}' => 'indirizzo della pagina dell’incontro sulla piattaforma',
            '{corso}' => 'titolo del corso, se l’incontro è legato a un modulo',
            '{modulo}' => 'titolo del modulo, se c’è',
            '{gruppo}' => 'nome del gruppo, se c’è',
            '{piattaforma}' => 'nome del mittente configurato nella pagina Posta elettronica',
            '{data_precedente}' => 'solo nel cambio di orario: la data di prima',
            '{ora_inizio_precedente}' => 'solo nel cambio di orario: l’ora di inizio di prima',
            '{ora_fine_precedente}' => 'solo nel cambio di orario: l’ora di fine di prima',
        ];
    }

    /**
     * Il testo in vigore per una chiave: quello del pannello, o il predefinito.
     */
    public static function template(string $key): string
    {
        $stored = Settings::get($key, '');

        return $stored !== null && trim($stored) !== ''
            ? $stored
            : (self::DEFAULTS[$key] ?? '');
    }

    /**
     * Sostituisce i segnaposto e ripulisce i vuoti lasciati dai campi assenti:
     * senza descrizione il testo predefinito avrebbe tre righe bianche di fila.
     *
     * @param array<string, string> $variables
     */
    public static function render(string $template, array $variables): string
    {
        $text = strtr($template, $variables);
        $text = preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text)) ?? $text;

        return trim($text) . "\n";
    }

    /**
     * I valori dei segnaposto per una sessione e un destinatario.
     *
     * @param array<string, mixed> $session
     * @param array{starts_at?: string, ends_at?: string}|null $previous orari di prima, nel cambio di orario
     * @return array<string, string>
     */
    public static function variables(array $session, string $recipientName, ?array $previous = null): array
    {
        $starts = new \DateTimeImmutable((string) $session['starts_at']);
        $ends = new \DateTimeImmutable((string) $session['ends_at']);

        $variables = [
            '{nome}' => $recipientName,
            '{titolo}' => (string) $session['title'],
            '{descrizione}' => (string) ($session['description'] ?? ''),
            '{data}' => $starts->format('d/m/Y'),
            '{ora_inizio}' => $starts->format('H:i'),
            '{ora_fine}' => $ends->format('H:i'),
            '{link_meet}' => (string) ($session['meet_link'] ?? ''),
            '{link_sessione}' => Url::to('/live/' . (int) $session['id']),
            '{corso}' => (string) ($session['course_title'] ?? ''),
            '{modulo}' => (string) ($session['module_title'] ?? ''),
            '{gruppo}' => (string) ($session['group_name'] ?? ''),
            '{piattaforma}' => (string) Settings::get('MAIL_FROM_NAME', 'Pistacchio LMS'),
        ];

        if ($previous !== null && isset($previous['starts_at'], $previous['ends_at'])) {
            $before = new \DateTimeImmutable((string) $previous['starts_at']);
            $beforeEnd = new \DateTimeImmutable((string) $previous['ends_at']);

            $variables['{data_precedente}'] = $before->format('d/m/Y');
            $variables['{ora_inizio_precedente}'] = $before->format('H:i');
            $variables['{ora_fine_precedente}'] = $beforeEnd->format('H:i');
        }

        return $variables;
    }

    // ---------------------------------------------------------------
    // I tre messaggi
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $session
     * @param array{full_name?: string, email: string} $recipient
     */
    public static function invite(array $session, array $recipient): Message
    {
        return self::compose($session, $recipient, 'LIVE_INVITE_SUBJECT', 'LIVE_INVITE_BODY', null, false);
    }

    /**
     * @param array<string, mixed> $session
     * @param array{full_name?: string, email: string} $recipient
     * @param array{starts_at: string, ends_at: string} $previous
     */
    public static function change(array $session, array $recipient, array $previous): Message
    {
        return self::compose($session, $recipient, 'LIVE_UPDATE_SUBJECT', 'LIVE_UPDATE_BODY', $previous, false);
    }

    /**
     * @param array<string, mixed> $session
     * @param array{full_name?: string, email: string} $recipient
     */
    public static function cancellation(array $session, array $recipient): Message
    {
        return self::compose($session, $recipient, 'LIVE_CANCEL_SUBJECT', 'LIVE_CANCEL_BODY', null, true);
    }

    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $session
     * @param array{full_name?: string, email: string} $recipient
     * @param array{starts_at: string, ends_at: string}|null $previous
     */
    private static function compose(
        array $session,
        array $recipient,
        string $subjectKey,
        string $bodyKey,
        ?array $previous,
        bool $cancelled,
    ): Message {
        $name = (string) ($recipient['full_name'] ?? '');
        $variables = self::variables($session, $name, $previous);

        // L'oggetto sta su una riga sola: un a capo lasciato in un segnaposto
        // spezzerebbe l'intestazione in due.
        $subject = trim(str_replace(["\r", "\n"], ' ', self::render(self::template($subjectKey), $variables)));

        return new Message(
            (string) $recipient['email'],
            $name,
            $subject,
            self::render(self::template($bodyKey), $variables),
            [self::calendarAttachment($session, $recipient, $cancelled)]
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array{full_name?: string, email: string} $recipient
     * @return array{filename: string, mimeType: string, content: string}
     */
    private static function calendarAttachment(array $session, array $recipient, bool $cancelled): array
    {
        $uid = Ics::uid((int) $session['id']);
        $sequence = Ics::sequence();
        $starts = new \DateTimeImmutable((string) $session['starts_at']);
        $ends = new \DateTimeImmutable((string) $session['ends_at']);
        $organizer = (string) Settings::get('MAIL_FROM_ADDRESS', 'no-reply@localhost');
        $organizerName = (string) Settings::get('MAIL_FROM_NAME', 'Pistacchio LMS');
        $method = $cancelled ? 'CANCEL' : 'REQUEST';

        $content = $cancelled
            ? Ics::cancel(
                $uid,
                $sequence,
                (string) $session['title'],
                $session['description'] !== null ? (string) $session['description'] : null,
                $session['meet_link'] !== null ? (string) $session['meet_link'] : null,
                $starts,
                $ends,
                $organizer,
                $organizerName,
                (string) $recipient['email'],
                (string) ($recipient['full_name'] ?? '')
            )
            : Ics::request(
                $uid,
                $sequence,
                (string) $session['title'],
                $session['description'] !== null ? (string) $session['description'] : null,
                $session['meet_link'] !== null ? (string) $session['meet_link'] : null,
                $starts,
                $ends,
                $organizer,
                $organizerName,
                (string) $recipient['email'],
                (string) ($recipient['full_name'] ?? '')
            );

        return [
            'filename' => $cancelled ? 'annullamento.ics' : 'invito.ics',
            'mimeType' => 'text/calendar; charset=UTF-8; method=' . $method,
            'content' => $content,
        ];
    }
}
