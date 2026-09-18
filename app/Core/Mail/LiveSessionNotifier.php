<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Models\LiveSessionModel;

/**
 * Manda ai partecipanti attesi i messaggi di una sessione live.
 *
 * L'invio e' "non bloccante", come per gli altri avvisi della piattaforma: un
 * indirizzo che rifiuta il messaggio non deve far fallire la modifica o
 * l'eliminazione che lo ha generato. Il conteggio torna a chi ha premuto il
 * pulsante, cosi' un fallimento si vede invece di restare solo nel log.
 */
class LiveSessionNotifier
{
    /**
     * Invito iniziale, e ogni volta che si ripreme "Invia inviti".
     *
     * @param array<string, mixed> $session
     * @param array<int, array{email: string, full_name?: string}>|null $recipients
     * @return array{recipients: int, sent: int, failed: int}
     */
    public static function invite(array $session, ?array $recipients = null): array
    {
        return self::dispatch(
            $recipients ?? self::participants($session),
            static fn (array $person): Message => LiveSessionMail::invite($session, $person)
        );
    }

    /**
     * Avviso di cambio di orario.
     *
     * @param array<string, mixed> $session
     * @param array{starts_at: string, ends_at: string} $previous
     * @param array<int, array{email: string, full_name?: string}>|null $recipients
     * @return array{recipients: int, sent: int, failed: int}
     */
    public static function change(array $session, array $previous, ?array $recipients = null): array
    {
        return self::dispatch(
            $recipients ?? self::participants($session),
            static fn (array $person): Message => LiveSessionMail::change($session, $person, $previous)
        );
    }

    /**
     * Avviso di annullamento.
     *
     * I destinatari vanno letti prima di eliminare la sessione: dopo la
     * cancellazione la riga non c'e' piu' e la lista tornerebbe vuota.
     *
     * @param array<string, mixed> $session
     * @param array<int, array{email: string, full_name?: string}>|null $recipients
     * @return array{recipients: int, sent: int, failed: int}
     */
    public static function cancellation(array $session, ?array $recipients = null): array
    {
        return self::dispatch(
            $recipients ?? self::participants($session),
            static fn (array $person): Message => LiveSessionMail::cancellation($session, $person)
        );
    }

    /**
     * Frase pronta per il messaggio di conferma nel pannello.
     *
     * @param array{recipients: int, sent: int, failed: int} $outcome
     */
    public static function summary(array $outcome, string $what = 'Inviti'): string
    {
        if ($outcome['recipients'] === 0) {
            return 'Nessun partecipante da avvisare: il modulo o il gruppo collegati non hanno iscritti.';
        }

        $message = $outcome['failed'] === 0
            ? sprintf('%s inviati a %d %s.', $what, $outcome['sent'], self::people($outcome['sent']))
            : sprintf(
                '%s: %d inviati, %d non partiti (il motivo è nel log del server).',
                $what,
                $outcome['sent'],
                $outcome['failed']
            );

        if ($outcome['sent'] > 0 && Mailer::isLogTransport()) {
            $message .= ' In modalità registro i messaggi restano in storage/mail: non esce niente dal server.';
        }

        return $message;
    }

    // ---------------------------------------------------------------

    /**
     * @param array<int, array{email: string, full_name?: string}> $recipients
     * @param callable(array): Message $build
     * @return array{recipients: int, sent: int, failed: int}
     */
    private static function dispatch(array $recipients, callable $build): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $person) {
            $email = trim((string) ($person['email'] ?? ''));

            // Un indirizzo malformato in tabella non deve interrompere il giro
            // degli altri: si conta come non partito e si tira avanti.
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $failed++;
                continue;
            }

            Mailer::sendQuietly($build($person)) ? $sent++ : $failed++;
        }

        return ['recipients' => count($recipients), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * @param array<string, mixed> $session
     * @return array<int, array{email: string, full_name?: string}>
     */
    private static function participants(array $session): array
    {
        return LiveSessionModel::participants((int) $session['id']);
    }

    private static function people(int $count): string
    {
        return $count === 1 ? 'partecipante' : 'partecipanti';
    }
}
