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
     * volta che si raggiunge il 100%, senza sovrascriverlo se c'e' gia'.
     *
     * IL «COMPLETATO» RESTA (17/11, Alessandro, come nella maggior parte
     * degli LMS): chi ha finito il corso resta completato anche se dopo si
     * aggiunge una lezione e la percentuale scende. Fino al 17/11 completed_at
     * si azzerava appena la percentuale tornava sotto il 100%.
     */
    public static function updateProgress(int $userId, int $courseId, float $progressPct): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE enrollments
             SET progress_pct = :progress_pct,
                 completed_at = CASE WHEN :is_complete THEN COALESCE(completed_at, NOW()) ELSE completed_at END
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
     * Ricalcola l'avanzamento di tutti gli iscritti di un corso (17/11, la
     * seconda lista della revisione, voce 5). Lo chiamano i modelli quando
     * il numero delle lezioni del corso cambia: una lezione creata o
     * eliminata, un modulo eliminato. Prima l'avanzamento si aggiornava solo
     * quando lo studente completava una lezione, e una lezione aggiunta dopo
     * lasciava al 100% chi aveva finito.
     *
     * Lo stesso conto di `LessonController::complete`: lezioni fatte su
     * lezioni del corso, due decimali, 0 se il corso non ha lezioni. Il
     * «completato» resta a chi l'aveva (vedi `updateProgress`); chi arriva al
     * 100% perche' si e' tolta la lezione che gli mancava lo diventa adesso.
     * I certificati non si toccano: quelli emessi restano, e quello di chi
     * diventa idoneo lo emette la pagina del corso alla prossima visita.
     */
    public static function ricalcolaAvanzamentoCorso(int $courseId): void
    {
        $pdo = Database::connection();

        $totale = $pdo->prepare(
            'SELECT COUNT(*) FROM lessons l INNER JOIN modules m ON m.id = l.module_id WHERE m.course_id = :course_id'
        );
        $totale->execute(['course_id' => $courseId]);
        $lezioni = (int) $totale->fetchColumn();

        $stmt = $pdo->prepare(
            'UPDATE enrollments e
             LEFT JOIN (SELECT lp.user_id, COUNT(*) AS fatte
                          FROM lesson_progress lp
                          INNER JOIN lessons l ON l.id = lp.lesson_id
                          INNER JOIN modules m ON m.id = l.module_id
                         WHERE m.course_id = :corso_fatte
                         GROUP BY lp.user_id) f ON f.user_id = e.user_id
             SET e.progress_pct = IF(:lezioni > 0, ROUND(100 * COALESCE(f.fatte, 0) / :lezioni_div, 2), 0),
                 e.completed_at = CASE WHEN :lezioni_fine > 0 AND COALESCE(f.fatte, 0) >= :lezioni_tutte
                                       THEN COALESCE(e.completed_at, NOW()) ELSE e.completed_at END
             WHERE e.course_id = :course_id'
        );
        $stmt->execute([
            'corso_fatte' => $courseId,
            'lezioni' => $lezioni,
            'lezioni_div' => $lezioni,
            'lezioni_fine' => $lezioni,
            'lezioni_tutte' => $lezioni,
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
     * Solo gli studenti (13/11, Alessandro): lo staff non e' mai iscritto a
     * un corso, qualunque sia la strada (catalogo, a mano, gruppo,
     * pubblicazione, Stripe). Le iscrizioni di uno studente diventato staff
     * restano: sono la sua storia di studente.
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
             WHERE u.id = :user_id AND u.role = \'studente\''
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
