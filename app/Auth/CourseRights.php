<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * Chi puo' modificare quale corso.
 *
 * DAL 26/10 (Alessandro, conflitto 1 della verifica su tutor e assistente):
 * **i contenuti del corso li modifica solo l'admin.** Un corso e' di tutte le
 * classi che lo seguono: con due classi nello stesso corso, il tutor
 * dell'una cambiava moduli, lezioni, questionari e date anche per l'altra.
 * Il tutor gestisce quello che e' della sua classe (date del gruppo,
 * incontri del gruppo, benvenuto, Bacheca) e legge il corso.
 *
 * Fino al 26/10 valeva la regola del 28/09 (pistacchio-lms.md §8.0): il
 * tutor modificava i corsi assegnati ai suoi gruppi.
 *
 * `courseIdsFor()` resta: non dice piu' che cosa il tutor modifica, ma qual
 * e' il suo perimetro, cioe' i corsi dei suoi gruppi (report, Bacheca,
 * incontri, il suo benvenuto).
 *
 * Sta in un posto solo di proposito. Prima ogni azione controllava soltanto il
 * ruolo ("sei admin o tutor?"), in una quarantina di punti diversi, e da ognuno
 * di quelli un tutor poteva modificare qualunque corso.
 */
class CourseRights
{
    /** @var array<int, int[]> corsi del tutor, per richiesta */
    private static array $cache = [];

    /**
     * L'utente in sessione puo' modificare questo corso?
     */
    public static function canEdit(int $courseId): bool
    {
        if (!Auth::check()) {
            return false;
        }

        return Auth::hasRole('admin');
    }

    /**
     * Come canEdit(), ma ferma la richiesta con un 403 se la risposta e' no.
     *
     * Un corso che non esiste non viene fermato qui: ci pensa il controller con
     * il suo 404, che per chi ha sbagliato indirizzo dice la cosa giusta.
     */
    public static function requireEdit(?int $courseId): void
    {
        Auth::requireLogin();

        if ($courseId === null) {
            return;
        }

        if (!self::canEdit($courseId)) {
            http_response_code(403);
            exit('Accesso negato: i contenuti del corso li modifica l\'amministrazione, puoi solo consultarlo.');
        }
    }

    public static function requireEditModule(int $moduleId): void
    {
        self::requireEdit(self::courseOf('SELECT course_id FROM modules WHERE id = :id', $moduleId));
    }

    public static function requireEditLesson(int $lessonId): void
    {
        self::requireEdit(self::courseOf(
            'SELECT m.course_id FROM lessons l JOIN modules m ON m.id = l.module_id WHERE l.id = :id',
            $lessonId
        ));
    }

    public static function requireEditQuiz(int $quizId): void
    {
        self::requireEdit(self::courseOf(
            'SELECT m.course_id FROM quizzes q JOIN modules m ON m.id = q.module_id WHERE q.id = :id',
            $quizId
        ));
    }

    public static function requireEditQuestion(int $questionId): void
    {
        self::requireEdit(self::courseOf(
            'SELECT m.course_id FROM quiz_questions qq
             JOIN quizzes q ON q.id = qq.quiz_id
             JOIN modules m ON m.id = q.module_id
             WHERE qq.id = :id',
            $questionId
        ));
    }

    /**
     * Corsi che l'utente in sessione modifica: null significa "tutti"
     * (admin), nessuno per gli altri (dal 26/10).
     *
     * @return int[]|null
     */
    public static function editableCourseIds(): ?array
    {
        return Auth::hasRole('admin') ? null : [];
    }

    /**
     * Svuota la cache: dopo aver assegnato un corso a un gruppo, nella stessa
     * richiesta la regola deve vedere l'assegnazione nuova.
     */
    public static function forget(): void
    {
        self::$cache = [];
    }

    // ---------------------------------------------------------------

    /**
     * I corsi dei gruppi di un tutor — il suo perimetro, non piu' quello che
     * modifica (26/10) — **senza passare dalla sessione**.
     *
     * Serve al calendario sottoscritto, che risponde a un programma e non
     * a una persona: li' `Auth` non c'e', ma la regola di chi vede cosa
     * dev'essere la stessa di sempre: per questo si chiama con un
     * identificativo esplicito invece che con quello in sessione.
     *
     * @return int[]
     */
    public static function courseIdsFor(int $tutorId): array
    {
        return self::tutorCourseIds($tutorId);
    }

    /**
     * @return int[]
     */
    private static function tutorCourseIds(int $tutorId): array
    {
        if (!isset(self::$cache[$tutorId])) {
            $stmt = Database::connection()->prepare(
                'SELECT DISTINCT gca.course_id
                 FROM group_course_access gca
                 INNER JOIN `groups` g ON g.id = gca.group_id
                 WHERE g.tutor_id = :tutor'
            );
            $stmt->execute(['tutor' => $tutorId]);

            self::$cache[$tutorId] = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        return self::$cache[$tutorId];
    }

    private static function courseOf(string $sql, int $id): ?int
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }
}
