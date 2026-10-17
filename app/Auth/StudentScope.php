<?php

declare(strict_types=1);

namespace App\Auth;

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
