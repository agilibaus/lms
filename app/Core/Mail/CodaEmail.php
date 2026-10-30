<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Database;
use App\Core\Settings;

/**
 * La coda delle email (30/10, Alessandro, strada C).
 *
 * Ci finiscono le email a piu' persone, quelle a una persona sola che non
 * sono partite subito, e quelle del cron. Le manda `svuota()`, chiamata da
 * `bin/avvisi` a ogni giro: una riga si cancella solo a invio riuscito,
 * quindi un'email si considera partita solo quando e' partita davvero.
 * Se non parte si ritenta a ogni giro finche' `retry_until` (24 ore) non e'
 * passato; dopo resta «non partita», e la si vede in «Impostazioni → Posta →
 * Email in coda e non partite», con «Riprova» e «Rimuovi».
 *
 * Gli inviti agli utenti importati non passano di qui: contengono la
 * password provvisoria, che qui resterebbe salvata in chiaro (`Invites`
 * li ritenta gia' da solo).
 */
final class CodaEmail
{
    /** Per quante ore si ritenta un'email che non parte. */
    public const ORE_DI_TENTATIVI = 24;

    /** Per quanto un giro tiene un'email, perche' un altro giro non la mandi due volte. */
    private const MINUTI_DI_PRESA = 10;

    /**
     * Ogni quanti minuti gira `bin/avvisi` (il cron consigliato nel README):
     * lo dicono le frasi delle pagine («entro 15 minuti»).
     */
    public const MINUTI_DEL_GIRO = 15;

    /** Quante email al massimo per giro. */
    public const PER_GIRO = 500;

    /** Gli esiti di `aggiungi()`. */
    public const IN_CODA = 'in_coda';
    public const GIA_IN_CODA = 'gia_in_coda';
    public const ERRORE = 'errore';

    /**
     * Mette un'email in coda. Con `$chiave` non la rimette se un'email con
     * la stessa chiave e' ancora in coda (gli inviti di un incontro, se lo
     * staff ripreme «Invia inviti»): torna GIA_IN_CODA. Con `$errore`
     * l'email arriva da un invio subito che non e' riuscito, e conta gia' un
     * tentativo. Non lancia mai: come l'invio di prima, una coda che non
     * risponde non deve far fallire l'operazione che ha generato l'email
     * (torna ERRORE, e l'errore va nel log).
     */
    public static function aggiungi(Message $messaggio, ?string $chiave = null, ?string $errore = null): string
    {
        try {
            return self::scrivi($messaggio, $chiave, $errore);
        } catch (\Throwable $e) {
            error_log('[Coda email] non messa in coda, ' . $messaggio->toEmail . ': ' . $e->getMessage());

            return self::ERRORE;
        }
    }

    private static function scrivi(Message $messaggio, ?string $chiave, ?string $errore): string
    {
        $db = Database::connection();

        if ($chiave !== null) {
            $gia = $db->prepare('SELECT 1 FROM email_queue WHERE dedupe_key = :k AND retry_until > NOW() LIMIT 1');
            $gia->execute(['k' => $chiave]);

            if ($gia->fetchColumn() !== false) {
                return self::GIA_IN_CODA;
            }
        }

        $allegati = $messaggio->attachments === [] ? null : json_encode(array_map(
            static fn (array $a): array => [
                'filename' => $a['filename'],
                'mimeType' => $a['mimeType'],
                'content' => base64_encode($a['content']),
            ],
            $messaggio->attachments
        ), JSON_THROW_ON_ERROR);

        $db->prepare(
            'INSERT INTO email_queue (to_email, to_name, subject, body, reply_to, attachments, dedupe_key,
                                      attempts, last_error, last_attempt_at, retry_until)
             VALUES (:email, :nome, :oggetto, :corpo, :rispondi, :allegati, :chiave,
                     :tentativi, :errore, :quando, NOW() + INTERVAL ' . self::ORE_DI_TENTATIVI . ' HOUR)'
        )->execute([
            'email' => $messaggio->toEmail,
            'nome' => $messaggio->toName,
            'oggetto' => mb_substr($messaggio->subject, 0, 500),
            'corpo' => $messaggio->body,
            'rispondi' => $messaggio->replyTo,
            'allegati' => $allegati,
            'chiave' => $chiave,
            'tentativi' => $errore === null ? 0 : 1,
            'errore' => $errore === null ? null : mb_substr($errore, 0, 500),
            'quando' => $errore === null ? null : date('Y-m-d H:i:s'),
        ]);

        return self::IN_CODA;
    }

    /**
     * Manda le email in coda, ciascuna una volta. Quella partita si cancella;
     * quella che non parte conta un tentativo, si tiene l'errore e si
     * riprova al giro dopo.
     *
     * @return array{mandate: int, fallite: list<string>}
     */
    public static function svuota(int $quante = self::PER_GIRO): array
    {
        $db = Database::connection();
        $ids = $db->query(
            'SELECT id FROM email_queue
             WHERE retry_until > NOW() AND (locked_until IS NULL OR locked_until < NOW())
             ORDER BY id LIMIT ' . max(1, $quante)
        )->fetchAll(\PDO::FETCH_COLUMN);

        $mandate = 0;
        $fallite = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            // La presa: se un altro giro l'ha gia' presa, si passa oltre.
            $presa = $db->prepare(
                'UPDATE email_queue SET locked_until = NOW() + INTERVAL ' . self::MINUTI_DI_PRESA . ' MINUTE
                 WHERE id = :id AND retry_until > NOW() AND (locked_until IS NULL OR locked_until < NOW())'
            );
            $presa->execute(['id' => $id]);

            if ($presa->rowCount() !== 1) {
                continue;
            }

            $riga = self::trova($id);

            if ($riga === null) {
                continue;
            }

            try {
                Mailer::send(self::messaggio($riga));
            } catch (MailException $e) {
                $db->prepare(
                    'UPDATE email_queue SET attempts = attempts + 1, last_error = :errore, last_attempt_at = NOW(),
                                            locked_until = NULL
                     WHERE id = :id'
                )->execute(['id' => $id, 'errore' => mb_substr($e->getMessage(), 0, 500)]);
                error_log('[Coda email] ' . $riga['to_email'] . ': ' . $e->getMessage());
                $fallite[] = (string) $riga['to_email'];
                continue;
            }

            $db->prepare('DELETE FROM email_queue WHERE id = :id')->execute(['id' => $id]);
            $mandate++;
        }

        return ['mandate' => $mandate, 'fallite' => $fallite];
    }

    /**
     * Le email della pagina «Email in coda e non partite», le piu' vecchie
     * prima: quelle il cui tempo e' finito, e quelle che si stanno ancora
     * tentando.
     *
     * @return array{non_partite: list<array<string, mixed>>, in_coda: list<array<string, mixed>>}
     */
    public static function elenco(): array
    {
        $righe = Database::connection()->query(
            'SELECT id, to_email, subject, attempts, last_error, created_at, (retry_until <= NOW()) AS finita
             FROM email_queue ORDER BY created_at, id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $elenco = ['non_partite' => [], 'in_coda' => []];

        foreach ($righe as $riga) {
            $elenco[(int) $riga['finita'] === 1 ? 'non_partite' : 'in_coda'][] = $riga;
        }

        return $elenco;
    }

    /** «Riprova»: altre 24 ore di tentativi, a partire dal prossimo giro. */
    public static function riprova(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE email_queue SET retry_until = NOW() + INTERVAL ' . self::ORE_DI_TENTATIVI . ' HOUR, locked_until = NULL
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** «Riprova tutte»: quante ne ha rimesse in coda. */
    public static function riprovaTutte(): int
    {
        return Database::connection()->exec(
            'UPDATE email_queue SET retry_until = NOW() + INTERVAL ' . self::ORE_DI_TENTATIVI . ' HOUR, locked_until = NULL'
        ) ?: 0;
    }

    /** «Rimuovi»: l'email non parte piu'. */
    public static function rimuovi(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM email_queue WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Quando ha girato l'ultima volta `bin/avvisi`, o null. */
    public static function ultimoGiro(): ?string
    {
        $quando = Settings::get(\App\Core\Avvisi::ULTIMO_GIRO);

        return $quando === null || $quando === '' ? null : (string) $quando;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function trova(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_queue WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $riga = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $riga === false ? null : $riga;
    }

    /**
     * @param array<string, mixed> $riga
     */
    private static function messaggio(array $riga): Message
    {
        $allegati = [];

        if (!empty($riga['attachments'])) {
            foreach (json_decode((string) $riga['attachments'], true, 512, JSON_THROW_ON_ERROR) as $a) {
                $allegati[] = [
                    'filename' => (string) $a['filename'],
                    'mimeType' => (string) $a['mimeType'],
                    'content' => (string) base64_decode((string) $a['content'], true),
                ];
            }
        }

        $messaggio = new Message(
            (string) $riga['to_email'],
            (string) $riga['to_name'],
            (string) $riga['subject'],
            (string) $riga['body'],
            $allegati
        );
        $messaggio->replyTo = $riga['reply_to'] === null ? null : (string) $riga['reply_to'];

        return $messaggio;
    }
}
