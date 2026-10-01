<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `lesson_progress` (tracciamento completamento
 * singola lezione da parte di uno studente).
 */
class LessonProgressModel
{
    public static function isCompleted(int $userId, int $lessonId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM lesson_progress WHERE user_id = :user_id AND lesson_id = :lesson_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);

        return (bool) $stmt->fetchColumn();
    }

    public static function markCompleted(int $userId, int $lessonId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (:user_id, :lesson_id)'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);
    }

    /**
     * @return int[] id delle lezioni completate dall'utente, per un dato corso
     */
    public static function completedLessonIdsForCourse(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT lp.lesson_id
             FROM lesson_progress lp
             INNER JOIN lessons l ON l.id = lp.lesson_id
             INNER JOIN modules m ON m.id = l.module_id
             WHERE lp.user_id = :user_id AND m.course_id = :course_id'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'lesson_id'));
    }

    public static function countCompletedForCourse(int $userId, int $courseId): int
    {
        return count(self::completedLessonIdsForCourse($userId, $courseId));
    }

    /**
     * Per ogni corso a cui lo studente e' iscritto: quante lezioni sono
     * **gia' aperte**, quante di quelle ha fatto, e quando si apre il
     * prossimo modulo chiuso.
     *
     * Serve alla frase che affianca la percentuale di avanzamento. La
     * percentuale resta sul corso intero (§8.7: non si tocca il
     * denominatore, o il certificato partirebbe a corso appena iniziato);
     * questa dice un'altra cosa, cioe' se lo studente e' in pari con quello
     * che puo' fare oggi.
     *
     * **Due query per tutti i corsi, non due per corso.** Lo stesso conto
     * fatto corso per corso sarebbe una manciata di query per ogni scheda
     * nell'elenco: la pagina dei corsi di uno studente ne stampa quante ne
     * ha. La disponibilita' si valuta qui solo per data, perche' la data e'
     * una condizione che sta in SQL; il blocco a catena dei quiz dipende dai
     * tentativi dello studente e lo risolve `CourseAccess`.
     *
     * Come ovunque, `NOW()` e `available_from` si confrontano in SQL.
     *
     * @return array<int, array{disponibili: int, fatte: int, prossima: ?string}>
     *         chiave: id del corso
     */
    public static function availabilitySummary(int $userId): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT m.course_id,
                    COUNT(l.id) AS disponibili,
                    COUNT(lp.id) AS fatte
             FROM enrollments e
             INNER JOIN modules m ON m.course_id = e.course_id
             INNER JOIN lessons l ON l.module_id = m.id
             LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id
             WHERE e.user_id = :user_id
               AND (m.available_from IS NULL OR m.available_from <= NOW())
             GROUP BY m.course_id'
        );
        $stmt->execute(['user_id' => $userId]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['course_id']] = [
                'disponibili' => (int) $row['disponibili'],
                'fatte' => (int) $row['fatte'],
                'prossima' => null,
            ];
        }

        $stmt = $db->prepare(
            'SELECT m.course_id, MIN(m.available_from) AS prossima
             FROM enrollments e
             INNER JOIN modules m ON m.course_id = e.course_id
             WHERE e.user_id = :user_id AND m.available_from > NOW()
             GROUP BY m.course_id'
        );
        $stmt->execute(['user_id' => $userId]);

        foreach ($stmt->fetchAll() as $row) {
            $courseId = (int) $row['course_id'];
            // Un corso in cui TUTTI i moduli sono ancora chiusi non compare
            // nella prima query: la riga va creata, non solo completata.
            $out[$courseId] ??= ['disponibili' => 0, 'fatte' => 0, 'prossima' => null];
            $out[$courseId]['prossima'] = (string) $row['prossima'];
        }

        return $out;
    }

    /**
     * Quante lezioni stanno in questi moduli, e quante ne ha fatte l'utente.
     *
     * Serve a togliere dal conto di `availabilitySummary()` i moduli che la
     * data lascia aperti ma che la catena dei quiz chiude: quel conto si fa
     * in SQL e la catena dipende dai tentativi dello studente, quindi la
     * correzione arriva da qui. Una query, non una per modulo.
     *
     * @param int[] $moduleIds
     * @return array{lezioni: int, fatte: int}
     */
    public static function countsForModules(int $userId, array $moduleIds): array
    {
        if ($moduleIds === []) {
            return ['lezioni' => 0, 'fatte' => 0];
        }

        // Gli id arrivano dal database, non dall'utente, ma passano
        // comunque da `intval`: un elenco infilato dentro una query e'
        // esattamente il punto in cui un giorno ci finisce altro.
        $in = implode(',', array_map('intval', $moduleIds));

        $stmt = Database::connection()->prepare(
            'SELECT COUNT(l.id) AS lezioni, COUNT(lp.id) AS fatte
             FROM lessons l
             LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = :user_id
             WHERE l.module_id IN (' . $in . ')'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return [
            'lezioni' => (int) ($row['lezioni'] ?? 0),
            'fatte' => (int) ($row['fatte'] ?? 0),
        ];
    }
}
