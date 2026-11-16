<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per `live_session_attendance`.
 *
 * Una riga con `joined_at` valorizzato significa "presente". L'origine del dato
 * resta tracciata: `platform` se lo studente ha aperto il Meet dal link interno,
 * `manual` se e' stato il tutor a segnarlo.
 */
class LiveSessionAttendanceModel
{
    /**
     * Registra l'ingresso dello studente (idempotente: il primo ingresso
     * resta quello buono, le riaperture del link non lo sovrascrivono).
     */
    public static function markJoined(int $sessionId, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO live_session_attendance (session_id, user_id, joined_at, source)
             VALUES (:session_id, :user_id, NOW(), \'platform\')
             ON DUPLICATE KEY UPDATE joined_at = COALESCE(joined_at, NOW())'
        );
        $stmt->execute(['session_id' => $sessionId, 'user_id' => $userId]);
    }

    /**
     * Presenza segnata a mano dal tutor (anche a posteriori).
     */
    public static function markManually(int $sessionId, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO live_session_attendance (session_id, user_id, joined_at, source)
             VALUES (:session_id, :user_id, NOW(), \'manual\')
             ON DUPLICATE KEY UPDATE joined_at = COALESCE(joined_at, NOW())'
        );
        $stmt->execute(['session_id' => $sessionId, 'user_id' => $userId]);
    }

    public static function remove(int $sessionId, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM live_session_attendance WHERE session_id = :session_id AND user_id = :user_id'
        );
        $stmt->execute(['session_id' => $sessionId, 'user_id' => $userId]);
    }

    /**
     * Presenze di una sessione, indicizzate per id utente.
     *
     * @return array<int, array{joined_at: string|null, source: string}>
     */
    public static function forSession(int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, joined_at, source FROM live_session_attendance WHERE session_id = :session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);

        $byUser = [];

        foreach ($stmt->fetchAll() as $row) {
            $byUser[(int) $row['user_id']] = [
                'joined_at' => $row['joined_at'],
                'source' => (string) $row['source'],
            ];
        }

        return $byUser;
    }

    public static function hasJoined(int $sessionId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM live_session_attendance
             WHERE session_id = :session_id AND user_id = :user_id AND joined_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute(['session_id' => $sessionId, 'user_id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }
}
