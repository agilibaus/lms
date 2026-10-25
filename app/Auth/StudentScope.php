<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Models\GroupModel;
use App\Models\UserModel;

/**
 * Quali studenti segue chi e' in sessione: il perimetro dello staff sulle
 * persone.
 *
 * - l'**admin** tutti;
 * - il **tutor** gli studenti dei gruppi di cui e' il tutor;
 * - l'**assistente** gli studenti dei gruppi dei tutor che affianca, anche
 *   piu' d'uno.
 *
 * Fino al 17/10 la regola stava dentro ReportController, e la pagina del
 * tentativo di un quiz (`/attempts/{id}`), a cui si arriva proprio dal
 * report dello studente, controllava soltanto il ruolo: qualunque tutor o
 * assistente apriva il tentativo di qualunque studente, risposte aperte
 * comprese. Ora la regola sta qui e la usano tutti e due.
 *
 * DAL 26/10 (Alessandro, conflitto 4 della verifica su tutor e assistente)
 * il perimetro e' fatto di **coppie studente-corso**: di uno studente il
 * tutor vede i corsi che lo studente segue in un gruppo di quel tutor, non
 * tutti i suoi corsi. Prima, una volta dentro il perimetro, uno studente
 * portava con se' report, tentativi, certificati e incontri di corsi seguiti
 * con un altro tutor o senza gruppo. `corsiDi()`, `studentiNelCorso()` e
 * `puoVedereNelCorso()` dicono le coppie; `sqlNelPerimetro()` le stesse in
 * SQL, per i conteggi dei report (conflitto 5).
 */
final class StudentScope
{
    /**
     * I tutor di cui l'utente in sessione vede il lavoro: null per l'admin
     * (tutto), il tutor stesso per un tutor, i suoi tutor per un assistente.
     * E' da qui che derivano studenti, gruppi e corsi visibili.
     *
     * @return int[]|null
     */
    public static function tutorIds(): ?array
    {
        if (Auth::hasRole('admin')) {
            return null;
        }

        if (Auth::hasRole('tutor')) {
            return [(int) Auth::id()];
        }

        return UserModel::tutorIdsForAssistant((int) Auth::id());
    }

    /**
     * Gli studenti che l'utente in sessione segue: null = nessuna
     * restrizione (admin), altrimenti gli id dei membri dei gruppi dei tutor
     * del perimetro.
     *
     * @return int[]|null
     */
    public static function studentIds(): ?array
    {
        $tutors = self::tutorIds();

        if ($tutors === null) {
            return null;
        }

        $ids = [];

        foreach ($tutors as $tutorId) {
            $ids = array_merge($ids, GroupModel::memberIdsForTutor($tutorId));
        }

        return array_values(array_unique($ids));
    }

    /**
     * I gruppi del perimetro: null per l'admin (tutti), altrimenti i gruppi
     * dei tutor del perimetro.
     *
     * @return int[]|null
     */
    public static function gruppiIds(): ?array
    {
        $tutors = self::tutorIds();

        if ($tutors === null) {
            return null;
        }

        $ids = [];

        foreach ($tutors as $tutorId) {
            foreach (GroupModel::forTutor($tutorId) as $g) {
                $ids[] = (int) $g['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Le coppie del perimetro: per ogni studente, i corsi che segue in un
     * gruppo dei tutor del perimetro. null per l'admin. Lette una volta per
     * richiesta.
     *
     * @return array<int, list<int>>|null
     */
    public static function coppie(): ?array
    {
        $tutors = self::tutorIds();

        if ($tutors === null) {
            return null;
        }

        $chiave = implode(',', $tutors);

        if (!isset(self::$coppie[$chiave])) {
            $per = [];

            if ($tutors !== []) {
                foreach (Database::connection()->query(
                    'SELECT DISTINCT gm.user_id, gca.course_id
                     FROM `groups` g
                     INNER JOIN group_members gm ON gm.group_id = g.id
                     INNER JOIN group_course_access gca ON gca.group_id = g.id
                     WHERE g.tutor_id IN (' . implode(',', array_map('intval', $tutors)) . ')'
                ) as $r) {
                    $per[(int) $r['user_id']][] = (int) $r['course_id'];
                }
            }

            self::$coppie[$chiave] = $per;
        }

        return self::$coppie[$chiave];
    }

    /** @var array<string, array<int, list<int>>> */
    private static array $coppie = [];

    /**
     * I corsi di uno studente che chi guarda vede: null = tutti (admin).
     *
     * @return int[]|null
     */
    public static function corsiDi(int $studentId): ?array
    {
        $coppie = self::coppie();

        return $coppie === null ? null : ($coppie[$studentId] ?? []);
    }

    /**
     * Gli studenti di un corso che chi guarda vede: null = tutti (admin).
     *
     * @return int[]|null
     */
    public static function studentiNelCorso(int $courseId): ?array
    {
        $coppie = self::coppie();

        if ($coppie === null) {
            return null;
        }

        return array_keys(array_filter($coppie, static fn (array $corsi): bool => in_array($courseId, $corsi, true)));
    }

    /** Lo studente in questo corso e' nel perimetro di chi guarda? */
    public static function puoVedereNelCorso(int $studentId, int $courseId): bool
    {
        if (!self::puoVedere($studentId)) {
            return false;
        }

        $corsi = self::corsiDi($studentId);

        return $corsi === null || in_array($courseId, $corsi, true);
    }

    /**
     * La stessa regola in SQL, sullo studente `$utente` e sul corso `$corso`
     * (colonne o numeri): null per l'admin, cioe' nessun filtro.
     */
    public static function sqlNelPerimetro(string $utente, string $corso): ?string
    {
        $tutors = self::tutorIds();

        if ($tutors === null) {
            return null;
        }

        if ($tutors === []) {
            return '0';
        }

        return "EXISTS (SELECT 1 FROM `groups` g_p
                        INNER JOIN group_members gm_p ON gm_p.group_id = g_p.id
                        INNER JOIN group_course_access gca_p ON gca_p.group_id = g_p.id
                        WHERE g_p.tutor_id IN (" . implode(',', array_map('intval', $tutors)) . ")
                          AND gm_p.user_id = {$utente} AND gca_p.course_id = {$corso})";
    }

    /**
     * L'utente in sessione puo' vedere i risultati di questo studente? Come
     * il suo report: ci vuole uno dei due permessi dei report, e lo studente
     * deve stare nel perimetro.
     */
    public static function puoVedere(int $studentId): bool
    {
        if (!Auth::can('report.view') && !Auth::can('report.view_assigned')) {
            return false;
        }

        $ids = self::studentIds();

        return $ids === null || in_array($studentId, $ids, true);
    }
}
