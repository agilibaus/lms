<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * I pagamenti ricevuti da Stripe (06/11, tabella `payments`): una riga per
 * pagamento, con l'esito. La scrive solo l'avviso di Stripe
 * (`StripeController`); la legge «Impostazioni › Pagamenti».
 */
class PaymentModel
{
    /** Se questo avviso, o questo pagamento, e' gia' stato contato. */
    public static function giaContato(string $eventId, string $sessionId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM payments WHERE stripe_event_id = :e OR stripe_session_id = :s LIMIT 1'
        );
        $stmt->execute(['e' => $eventId, 's' => $sessionId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Scrive il pagamento. Torna false se un avviso uguale arrivato insieme
     * l'ha gia' scritto (le chiavi uniche decidono, non una lettura fatta
     * prima).
     *
     * @param array{event: string, session: string, livemode: bool, course_id: ?int, user_id: ?int, email: ?string,
     *              total: int, subtotal: int, currency: string, esito: string, motivo: ?string} $p
     */
    public static function registra(array $p): bool
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO payments
                (stripe_event_id, stripe_session_id, livemode, course_id, user_id, email,
                 amount_total, amount_subtotal, currency, esito, motivo)
             VALUES (:e, :s, :l, :c, :u, :m, :t, :st, :cur, :es, :mo)'
        );
        $stmt->execute([
            'e' => $p['event'], 's' => $p['session'], 'l' => $p['livemode'] ? 1 : 0,
            'c' => $p['course_id'], 'u' => $p['user_id'], 'm' => $p['email'],
            't' => $p['total'], 'st' => $p['subtotal'], 'cur' => $p['currency'],
            'es' => $p['esito'], 'mo' => $p['motivo'],
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * I pagamenti, dal piu' recente, di tutti i corsi o di uno.
     *
     * @return list<array<string, mixed>>
     */
    public static function elenco(?int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, c.title AS course_title, u.full_name, u.email AS user_email
             FROM payments p
             LEFT JOIN courses c ON c.id = p.course_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE (:c1 IS NULL OR p.course_id = :c2)
             ORDER BY p.received_at DESC, p.id DESC'
        );
        $stmt->execute(['c1' => $courseId, 'c2' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * I corsi che hanno pagamenti, per il filtro.
     *
     * @return list<array{id: int, title: string}>
     */
    public static function corsi(): array
    {
        return Database::connection()->query(
            'SELECT DISTINCT c.id, c.title
             FROM payments p INNER JOIN courses c ON c.id = p.course_id
             ORDER BY c.title, c.id'
        )->fetchAll();
    }

    public static function quanti(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    }
}
