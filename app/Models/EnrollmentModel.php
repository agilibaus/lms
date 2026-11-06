<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `enrollments`.
 */
class EnrollmentModel
{
    public static function find(int $userId, int $courseId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM enrollments WHERE user_id = :user_id AND course_id = :course_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
        $enrollment = $stmt->fetch();

        return $enrollment ?: null;
    }

    /**
     * Aggiorna la percentuale di completamento; imposta completed_at la prima
     * volta che si raggiunge il 100% (senza sovrascriverlo se già valorizzato,
     * e lo azzera se il progresso torna sotto al 100%).
     */
    public static function updateProgress(int $userId, int $courseId, float $progressPct): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE enrollments
             SET progress_pct = :progress_pct,
                 completed_at = CASE WHEN :is_complete THEN COALESCE(completed_at, NOW()) ELSE NULL END
             WHERE user_id = :user_id AND course_id = :course_id'
        );
        $stmt->execute([
            'progress_pct' => $progressPct,
            'is_complete' => $progressPct >= 100 ? 1 : 0,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
    }

    /**
     * Iscrive un utente a un corso (idempotente: una seconda chiamata non
     * azzera il progresso gia' registrato).
     *
     * `$conferma`: l'iscrizione la fa lo staff, e l'«Iscrizione confermata»
     * resta in coda per `bin/avvisi` (12/10). Non per chi e' ancora nella
     * coda degli inviti: a lui arriva «Il tuo accesso», e il corso lo trova
     * entrando.
     */
    public static function enroll(int $userId, int $courseId, bool $conferma = false): void
    {
        self::enrollMany([$userId], [$courseId], $conferma);
    }

    /**
     * Iscrive piu' utenti a piu' corsi (usata quando un corso viene assegnato
     * a un gruppo o quando un utente entra in un gruppo).
     *
     * Solo ai corsi pubblicati (06/11, Alessandro): una bozza e' un corso non
     * ancora finito, e nessuno vi e' iscritto. I corsi in bozza fra quelli
     * passati si saltano, qualunque sia la strada (a mano, gruppo, ingresso
     * in un gruppo, importazione): i membri dei gruppi a cui la bozza e'
     * assegnata li iscrive la pubblicazione (`iscriviAllaPubblicazione()`).
     *
     * @param int[] $userIds
     * @param int[] $courseIds
     * @param bool $conferma come in `enroll()`
     * @return int numero di iscrizioni effettivamente create
     */
    public static function enrollMany(array $userIds, array $courseIds, bool $conferma = false): int
    {
        if ($userIds === [] || $courseIds === []) {
            return 0;
        }

        // Una riga che c'e' gia' resta com'e' (INSERT IGNORE): chi era
        // iscritto non riceve una seconda conferma.
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO enrollments (user_id, course_id, confirmation_pending)
             SELECT u.id, c.id, IF(:conferma = 1 AND u.invite_pending = 0, 1, 0)
             FROM users u
             INNER JOIN courses c ON c.id = :course_id AND c.is_published = 1
             WHERE u.id = :user_id'
        );

        $created = 0;

        foreach ($userIds as $userId) {
            foreach ($courseIds as $courseId) {
                $stmt->execute(['user_id' => (int) $userId, 'course_id' => (int) $courseId, 'conferma' => $conferma ? 1 : 0]);
                $created += $stmt->rowCount();
            }
        }

        return $created;
    }

    /**
     * Alla pubblicazione di un corso (06/11): iscrive i membri dei gruppi a
     * cui e' assegnato, con l'«Iscrizione confermata» in coda per
     * `bin/avvisi`. Torna quanti sono stati iscritti.
     */
    public static function iscriviAllaPubblicazione(int $courseId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT gm.user_id
             FROM group_course_access gca
             INNER JOIN group_members gm ON gm.group_id = gca.group_id
             WHERE gca.course_id = :c'
        );
        $stmt->execute(['c' => $courseId]);

        return self::enrollMany(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), [$courseId], true);
    }

    /** Quanti iscritti ha un corso (06/11: un corso con iscritti non torna in bozza). */
    public static function quantiNelCorso(int $courseId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = :c');
        $stmt->execute(['c' => $courseId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Le «Iscrizione confermata» da mandare: chi e' attivo, studente, non in
     * coda inviti, in un corso pubblicato. (Dal 06/11 una bozza non ha
     * iscritti: il filtro sul corso pubblicato resta come difesa.)
     *
     * @return list<array{id: int, email: string, full_name: string, first_name: ?string, course_id: int, course_title: string}>
     */
    public static function conferme(int $quante): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT e.id, u.email, u.full_name, u.first_name, c.id AS course_id, c.title AS course_title
             FROM enrollments e
             INNER JOIN users u ON u.id = e.user_id
             INNER JOIN courses c ON c.id = e.course_id
             WHERE e.confirmation_pending = 1
               AND u.is_active = 1 AND u.role = 'studente' AND u.invite_pending = 0
               AND c.is_published = 1
             ORDER BY e.id
             LIMIT " . max(1, $quante)
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Prende in carico una conferma: la toglie dalla coda e dice se e'
     * riuscito. Prima la riga, poi l'email, come per i moduli: due giri
     * sovrapposti non mandano la stessa email due volte.
     */
    public static function prendiConferma(int $enrollmentId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE enrollments SET confirmation_pending = 0 WHERE id = :id AND confirmation_pending = 1'
        );
        $stmt->execute(['id' => $enrollmentId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Rimuove un'iscrizione: cancella anche progresso e certificato del corso
     * (FK ON DELETE CASCADE), quindi va confermata esplicitamente nel pannello.
     */
    public static function remove(int $userId, int $courseId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM enrollments WHERE user_id = :user_id AND course_id = :course_id'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
    }

    /**
     * Iscritti a un corso, con dati utente (pannello di gestione corso).
     */
    public static function forCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id AS user_id, u.full_name, u.email, u.role, u.is_active,
                    e.enrolled_at, e.progress_pct, e.completed_at
             FROM enrollments e
             INNER JOIN users u ON u.id = e.user_id
             WHERE e.course_id = :course_id
             ORDER BY u.full_name, u.email'
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }
}
