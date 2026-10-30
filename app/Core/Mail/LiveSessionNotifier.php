<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Models\LiveSessionModel;

/**
 * Mette in coda per i partecipanti attesi i messaggi di una sessione live.
 *
 * Dal 30/10 (strada C) non si manda niente dalla pagina: le email vanno nella
 * coda (`CodaEmail`) e partono al giro della posta, cosi' la pagina non resta
 * ferma con un gruppo grande o un server di posta lento. Gli inviti hanno una
 * chiave per persona e incontro: ripremendo «Invia inviti» quelli ancora in
 * coda non si rimettono, e nessuno li riceve due volte. Il conteggio torna a
 * chi ha premuto il pulsante.
 */
class LiveSessionNotifier
{
    /**
     * Invito iniziale, e ogni volta che si ripreme "Invia inviti".
     *
     * @param array<string, mixed> $session
     * @param array<int, array{email: string, full_name?: string}>|null $recipients
     * @return array{recipients: int, queued: int, already: int, failed: int}
     */
    public static function invite(array $session, ?array $recipients = null): array
    {
        return self::dispatch(
            $recipients ?? self::participants($session),
            static fn (array $person): Message => LiveSessionMail::invite($session, $person),
            static fn (string $email): string => 'incontro-invito:' . (int) $session['id'] . ':' . strtolower($email)
        );
    }

    /**
     * Avviso di cambio di orario.
     *
     * @param array<string, mixed> $session
     * @param array{starts_at: string, ends_at: string} $previous
     * @param array<int, array{email: string, full_name?: string}>|null $recipients
     * @return array{recipients: int, queued: int, already: int, failed: int}
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
     * @return array{recipients: int, queued: int, already: int, failed: int}
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
     * @param array{recipients: int, queued: int, already: int, failed: int} $outcome
     */
    public static function summary(array $outcome, string $what = 'Inviti'): string
    {
        if ($outcome['recipients'] === 0) {
            return 'Nessun partecipante da avvisare: il modulo o il gruppo collegati non hanno iscritti.';
        }

        $giro = 'partono al prossimo giro della posta, entro ' . CodaEmail::MINUTI_DEL_GIRO . ' minuti.';

        if ($outcome['queued'] === 0 && $outcome['already'] > 0) {
            // Ripremuto con gli inviti ancora tutti in coda (30/10).
            $message = sprintf(
                'Gli inviti per questo incontro sono già in coda per %s %d %s: %s Non li ho rimessi in coda una seconda volta.',
                $outcome['already'] === 1 ? 'il' : 'tutti i',
                $outcome['already'],
                self::people($outcome['already']),
                $giro
            );
        } else {
            $message = sprintf('%s in coda per %d %s: %s', $what, $outcome['queued'], self::people($outcome['queued']), $giro);

            if ($outcome['already'] > 0) {
                $message .= sprintf(
                    ' Per %s %d erano già in coda, e non li ho rimessi.',
                    $outcome['already'] === 1 ? "l'altro" : 'gli altri',
                    $outcome['already']
                );
            }
        }

        if ($outcome['failed'] > 0) {
            $message .= sprintf(' %d non messi in coda (il motivo è nel log del server).', $outcome['failed']);
        }

        if ($outcome['queued'] > 0 && Mailer::isLogTransport()) {
            $message .= ' In modalità registro i messaggi restano in storage/mail: non esce niente dal server.';
        }

        return $message;
    }

    // ---------------------------------------------------------------

    /**
     * @param array<int, array{email: string, full_name?: string}> $recipients
     * @param callable(array): Message $build
     * @param (callable(string): string)|null $chiave la chiave che impedisce i doppioni in coda
     * @return array{recipients: int, queued: int, already: int, failed: int}
     */
    private static function dispatch(array $recipients, callable $build, ?callable $chiave = null): array
    {
        $queued = 0;
        $already = 0;
        $failed = 0;

        foreach ($recipients as $person) {
            $email = trim($person['email']);

            // Un indirizzo malformato in tabella non deve interrompere il giro
            // degli altri: si conta come non partito e si tira avanti.
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $failed++;
                continue;
            }

            $esito = CodaEmail::aggiungi($build($person), $chiave === null ? null : $chiave($email));

            match ($esito) {
                CodaEmail::IN_CODA => $queued++,
                CodaEmail::GIA_IN_CODA => $already++,
                default => $failed++,
            };
        }

        return ['recipients' => count($recipients), 'queued' => $queued, 'already' => $already, 'failed' => $failed];
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
