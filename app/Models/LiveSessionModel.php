<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `live_sessions`.
 *
 * Una sessione e' legata a un modulo di corso e/o a un gruppo: il pubblico
 * sono gli iscritti al corso del modulo piu' i membri del gruppo.
 */
class LiveSessionModel
{
    private const BASE_SELECT =
        'SELECT ls.*,
                m.title AS module_title, m.course_id,
                c.title AS course_title,
                g.name AS group_name,
                u.full_name AS created_by_name
         FROM live_sessions ls
         LEFT JOIN modules m ON m.id = ls.module_id
         LEFT JOIN courses c ON c.id = m.course_id
         LEFT JOIN `groups` g ON g.id = ls.group_id
         LEFT JOIN users u ON u.id = ls.created_by';

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::BASE_SELECT . ' WHERE ls.id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $session = $stmt->fetch();

        return $session ?: null;
    }

    /**
     * Tutte le sessioni (vista staff), dalla piu' recente.
     */
    public static function all(): array
    {
        return Database::connection()
            ->query(self::BASE_SELECT . ' ORDER BY ls.starts_at DESC')
            ->fetchAll();
    }

    /**
     * Sessioni visibili a uno studente: quelle dei corsi a cui e' iscritto
     * e quelle dei gruppi di cui fa parte.
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            self::BASE_SELECT . '
             WHERE (ls.module_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM enrollments e
                        WHERE e.user_id = :user_id_course AND e.course_id = m.course_id))
                OR (ls.group_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM group_members gm
                        WHERE gm.user_id = :user_id_group AND gm.group_id = ls.group_id))
             ORDER BY ls.starts_at DESC'
        );
        $stmt->execute(['user_id_course' => $userId, 'user_id_group' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Sessioni di un modulo ancora da fare o in corso, per la pagina della
     * lezione. Le passate non compaiono: il link a una riunione finita e'
     * solo rumore in mezzo al contenuto.
     *
     * `joinable` dice se il pulsante va reso attivo: da un quarto d'ora prima
     * dell'inizio fino alla fine. `started` dice se l'incontro e' davvero
     * cominciato, che non e' la stessa cosa: si entra anche prima. Entrambi
     * sono calcolati in SQL e non in PHP, perche' le due macchine possono
     * trovarsi su fusi diversi.
     */
    public static function upcomingForModule(int $moduleId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ls.*,
                    m.title AS module_title, m.course_id,
                    c.title AS course_title,
                    (NOW() >= DATE_SUB(ls.starts_at, INTERVAL 15 MINUTE) AND NOW() <= ls.ends_at) AS joinable,
                    (NOW() >= ls.starts_at AND NOW() <= ls.ends_at) AS started
             FROM live_sessions ls
             LEFT JOIN modules m ON m.id = ls.module_id
             LEFT JOIN courses c ON c.id = m.course_id
             WHERE ls.module_id = :module_id
               AND ls.ends_at >= NOW()
             ORDER BY ls.starts_at'
        );
        $stmt->execute(['module_id' => $moduleId]);

        return $stmt->fetchAll();
    }

    /**
     * Sessioni di un corso (per la scheda corso), dalla piu' imminente.
     */
    public static function forCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            self::BASE_SELECT . ' WHERE m.course_id = :course_id ORDER BY ls.starts_at'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    public static function create(
        ?int $moduleId,
        ?int $groupId,
        string $title,
        ?string $description,
        string $startsAt,
        string $endsAt,
        ?string $googleEventId,
        ?string $meetLink,
        int $createdBy
    ): int {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO live_sessions
                (module_id, group_id, title, description, starts_at, ends_at, google_event_id, meet_link, created_by)
             VALUES
                (:module_id, :group_id, :title, :description, :starts_at, :ends_at, :google_event_id, :meet_link, :created_by)'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'group_id' => $groupId,
            'title' => $title,
            'description' => $description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'google_event_id' => $googleEventId,
            'meet_link' => $meetLink,
            'created_by' => $createdBy,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(
        int $id,
        ?int $moduleId,
        ?int $groupId,
        string $title,
        ?string $description,
        string $startsAt,
        string $endsAt
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE live_sessions
             SET module_id = :module_id, group_id = :group_id, title = :title, description = :description,
                 starts_at = :starts_at, ends_at = :ends_at
             WHERE id = :id'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'group_id' => $groupId,
            'title' => $title,
            'description' => $description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'id' => $id,
        ]);
    }

    /**
     * Aggiorna i riferimenti Google (dopo creazione o ri-sincronizzazione).
     */
    public static function updateGoogleReferences(int $id, ?string $googleEventId, ?string $meetLink): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE live_sessions SET google_event_id = :google_event_id, meet_link = :meet_link WHERE id = :id'
        );
        $stmt->execute(['google_event_id' => $googleEventId, 'meet_link' => $meetLink, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Le presenze seguono via FK ON DELETE CASCADE.
        $stmt = Database::connection()->prepare('DELETE FROM live_sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Partecipanti attesi: iscritti al corso del modulo e membri del gruppo.
     */
    public static function participants(int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT u.id, u.full_name, u.email
             FROM live_sessions ls
             LEFT JOIN modules m ON m.id = ls.module_id
             LEFT JOIN enrollments e ON e.course_id = m.course_id
             LEFT JOIN group_members gm ON gm.group_id = ls.group_id
             INNER JOIN users u ON u.id = e.user_id OR u.id = gm.user_id
             WHERE ls.id = :session_id AND u.is_active = 1
             ORDER BY u.full_name'
        );
        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->fetchAll();
    }

    /**
     * Partecipanti attesi con la loro presenza, per il report di un incontro.
     *
     * Rispetto a participants() la riga c'e' anche per chi non si e' presentato
     * (`joined_at` a null): un report delle presenze che elenca solo i presenti
     * non direbbe chi mancava, che e' meta' dell'informazione.
     *
     * Il ritardo si calcola in SQL e non in PHP: server e database possono
     * stare su fusi diversi, e un confronto fra i due darebbe scarti finti.
     *
     * @return array<int, array{id: int, full_name: string, email: string, joined_at: string|null, source: string|null, delay_minutes: int|null}>
     */
    public static function participantsWithAttendance(int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT u.id, u.full_name, u.email,
                    a.joined_at, a.source,
                    TIMESTAMPDIFF(MINUTE, ls.starts_at, a.joined_at) AS delay_minutes
             FROM live_sessions ls
             LEFT JOIN modules m ON m.id = ls.module_id
             LEFT JOIN enrollments e ON e.course_id = m.course_id
             LEFT JOIN group_members gm ON gm.group_id = ls.group_id
             INNER JOIN users u ON u.id = e.user_id OR u.id = gm.user_id
             LEFT JOIN live_session_attendance a ON a.session_id = ls.id AND a.user_id = u.id
             WHERE ls.id = :session_id AND u.is_active = 1
             ORDER BY u.full_name'
        );
        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->fetchAll();
    }

    /**
     * Elenco degli incontri con presenti e attesi, per la pagina dei report.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function overview(): array
    {
        return Database::connection()->query(
            'SELECT ls.id, ls.title, ls.starts_at, ls.ends_at,
                    m.title AS module_title,
                    c.title AS course_title,
                    g.name AS group_name,
                    (SELECT COUNT(*) FROM live_session_attendance a
                      WHERE a.session_id = ls.id AND a.joined_at IS NOT NULL) AS attended,
                    (SELECT COUNT(DISTINCT u.id)
                       FROM users u
                       LEFT JOIN enrollments e ON e.user_id = u.id AND e.course_id = m.course_id
                       LEFT JOIN group_members gm ON gm.user_id = u.id AND gm.group_id = ls.group_id
                      WHERE u.is_active = 1
                        AND (e.user_id IS NOT NULL OR gm.user_id IS NOT NULL)) AS expected
             FROM live_sessions ls
             LEFT JOIN modules m ON m.id = ls.module_id
             LEFT JOIN courses c ON c.id = m.course_id
             LEFT JOIN `groups` g ON g.id = ls.group_id
             ORDER BY ls.starts_at DESC'
        )->fetchAll();
    }

    /**
     * Gli incontri a cui uno studente era atteso, con la sua presenza.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUserWithAttendance(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ls.id, ls.title, ls.starts_at, ls.ends_at,
                    c.title AS course_title,
                    g.name AS group_name,
                    a.joined_at, a.source,
                    TIMESTAMPDIFF(MINUTE, ls.starts_at, a.joined_at) AS delay_minutes
             FROM live_sessions ls
             LEFT JOIN modules m ON m.id = ls.module_id
             LEFT JOIN courses c ON c.id = m.course_id
             LEFT JOIN `groups` g ON g.id = ls.group_id
             LEFT JOIN live_session_attendance a ON a.session_id = ls.id AND a.user_id = :user_id_attendance
             WHERE EXISTS (SELECT 1 FROM enrollments e
                           WHERE e.course_id = m.course_id AND e.user_id = :user_id_course)
                OR EXISTS (SELECT 1 FROM group_members gm
                           WHERE gm.group_id = ls.group_id AND gm.user_id = :user_id_group)
             ORDER BY ls.starts_at DESC'
        );
        $stmt->execute([
            'user_id_attendance' => $userId,
            'user_id_course' => $userId,
            'user_id_group' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Vero se l'utente e' fra i partecipanti attesi della sessione.
     */
    public static function isParticipant(int $sessionId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1
             FROM live_sessions ls
             LEFT JOIN modules m ON m.id = ls.module_id
             WHERE ls.id = :session_id
               AND (EXISTS (SELECT 1 FROM enrollments e
                            WHERE e.course_id = m.course_id AND e.user_id = :user_id_course)
                 OR EXISTS (SELECT 1 FROM group_members gm
                            WHERE gm.group_id = ls.group_id AND gm.user_id = :user_id_group))
             LIMIT 1'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'user_id_course' => $userId,
            'user_id_group' => $userId,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}
