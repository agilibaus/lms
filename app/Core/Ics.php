<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Generatore di file iCalendar (.ics) per gli inviti alle sessioni live.
 *
 * Scritto in casa come il client Google e come il client SMTP: serve un solo
 * tipo di oggetto — un evento con un invitato — e una libreria porterebbe piu'
 * dipendenze che vantaggi.
 *
 * Il file viene allegato all'email: chi lo apre aggiunge l'incontro al proprio
 * calendario con un clic e si ritrova la notifica il giorno stesso, che e' la
 * cosa che manca di piu' a un invito fatto di solo testo.
 */
class Ics
{
    private const CRLF = "\r\n";

    /** Larghezza massima di una riga secondo RFC 5545, prima della piegatura. */
    private const FOLD_AT = 74;

    /**
     * Momento da cui si contano i numeri di revisione.
     *
     * SEQUENCE deve crescere a ogni versione dello stesso evento, altrimenti i
     * calendari ignorano l'aggiornamento e tengono l'orario vecchio. Invece di
     * tenere un contatore in tabella si usano i secondi trascorsi da questa
     * data: cresce da solo, non ha stato da mantenere e resta molto sotto al
     * limite di un intero a 32 bit ancora per decenni.
     */
    private const SEQUENCE_EPOCH = 1577836800; // 2020-01-01T00:00:00Z

    public static function sequence(?int $timestamp = null): int
    {
        return max(0, ($timestamp ?? time()) - self::SEQUENCE_EPOCH);
    }

    /**
     * Identificativo stabile dell'evento: deve restare lo stesso fra invito,
     * aggiornamento e annullamento, altrimenti il calendario di chi riceve si
     * ritrova con due voci invece di una modificata.
     */
    public static function uid(int $sessionId, string $host = ''): string
    {
        $host = trim($host);

        if ($host === '') {
            $host = (string) (parse_url(Url::base(), PHP_URL_HOST) ?: 'pistacchio.local');
        }

        return 'sessione-live-' . $sessionId . '@' . $host;
    }

    /**
     * Invito o aggiornamento: METHOD:REQUEST.
     */
    public static function request(
        string $uid,
        int $sequence,
        string $summary,
        ?string $description,
        ?string $location,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        string $organizerEmail,
        string $organizerName = '',
        string $attendeeEmail = '',
        string $attendeeName = '',
    ): string {
        return self::build(
            'REQUEST',
            'CONFIRMED',
            $uid,
            $sequence,
            $summary,
            $description,
            $location,
            $startsAt,
            $endsAt,
            $organizerEmail,
            $organizerName,
            $attendeeEmail,
            $attendeeName
        );
    }

    /**
     * Annullamento: METHOD:CANCEL con STATUS:CANCELLED.
     */
    public static function cancel(
        string $uid,
        int $sequence,
        string $summary,
        ?string $description,
        ?string $location,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        string $organizerEmail,
        string $organizerName = '',
        string $attendeeEmail = '',
        string $attendeeName = '',
    ): string {
        return self::build(
            'CANCEL',
            'CANCELLED',
            $uid,
            $sequence,
            $summary,
            $description,
            $location,
            $startsAt,
            $endsAt,
            $organizerEmail,
            $organizerName,
            $attendeeEmail,
            $attendeeName
        );
    }

    // ---------------------------------------------------------------

    private static function build(
        string $method,
        string $status,
        string $uid,
        int $sequence,
        string $summary,
        ?string $description,
        ?string $location,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        string $organizerEmail,
        string $organizerName,
        string $attendeeEmail,
        string $attendeeName,
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Pistacchio LMS//Sessioni live//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            'UID:' . self::escape($uid),
            'SEQUENCE:' . $sequence,
            'DTSTAMP:' . self::utc(new \DateTimeImmutable('now')),
            'DTSTART:' . self::utc($startsAt),
            'DTEND:' . self::utc($endsAt),
            'SUMMARY:' . self::escape($summary),
        ];

        if ($description !== null && trim($description) !== '') {
            $lines[] = 'DESCRIPTION:' . self::escape($description);
        }

        if ($location !== null && trim($location) !== '') {
            // LOCATION lo mostrano tutti i calendari; URL quasi nessuno, ma chi
            // lo legge ci ricava il pulsante "partecipa".
            $lines[] = 'LOCATION:' . self::escape($location);
            $lines[] = 'URL;VALUE=URI:' . self::escape($location);
        }

        $lines[] = 'ORGANIZER' . self::person($organizerName) . ':mailto:' . $organizerEmail;

        if ($attendeeEmail !== '') {
            $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE'
                . self::person($attendeeName) . ':mailto:' . $attendeeEmail;
        }

        $lines[] = 'STATUS:' . $status;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map(self::fold(...), $lines)) . self::CRLF;
    }

    private static function person(string $name): string
    {
        return $name === '' ? '' : ';CN=' . self::escape($name);
    }

    /**
     * Gli orari viaggiano in UTC (la "Z" finale): cosi' il file non dipende
     * dalla tabella dei fusi del calendario che lo legge.
     */
    private static function utc(\DateTimeInterface $moment): string
    {
        return \DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /**
     * Caratteri speciali di RFC 5545. La barra rovesciata va sostituita per
     * prima, altrimenti raddoppierebbe quelle introdotte dalle altre.
     */
    private static function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $value
        );
    }

    /**
     * Piegatura delle righe lunghe: si spezza e si riprende con uno spazio.
     *
     * Il limite di RFC 5545 e' in ottetti, non in caratteri: spezzare a meta'
     * di una lettera accentata produrrebbe byte non validi, quindi il taglio
     * arretra fino all'inizio del carattere.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_AT) {
            return $line;
        }

        $pieces = [];

        while (strlen($line) > self::FOLD_AT) {
            $cut = self::FOLD_AT;

            // 10xxxxxx e' un byte di continuazione UTF-8: si torna indietro
            // fino al primo byte del carattere.
            while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $pieces[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
        }

        $pieces[] = $line;

        return implode(self::CRLF . ' ', $pieces);
    }
}
