<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\BoardModel;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\UserModel;

/**
 * Chi vede, chi pubblica e chi modera nella Bacheca (17/10). Una
 * definizione sola, chiamata da tutte le pagine e le azioni.
 *
 *   - LEGGE la bacheca di un corso: lo studente iscritto, di qualunque
 *     gruppo; il tutor dei gruppi del corso; l'assistente come i tutor che
 *     affianca (in sola lettura); chi ha `board.manage`, cioe' l'admin, tutte.
 *   - PUBBLICA (anche con un file): chi ha `board.manage` in ogni corso, chi
 *     ha `board.manage_own` nei corsi dei propri gruppi.
 *   - MODERA proposte e racconti di uno studente: chi ha `board.manage`, e il
 *     tutor di un gruppo dello studente che ha accesso al corso, con
 *     `board.manage_own`.
 *   - PROPONE e racconta come l'ha utilizzata: lo studente iscritto.
 */
final class BachecaScope
{
    /**
     * I corsi di cui si legge la bacheca, per titolo.
     *
     * @return list<array<string, mixed>>
     */
    public static function corsi(): array
    {
        $userId = (int) Auth::id();

        if (Auth::can('board.manage')) {
            $corsi = CourseModel::allForStaff();
        } elseif (Auth::hasRole('studente')) {
            $corsi = CourseModel::enrolledForUser($userId);
        } else {
            $tutor = Auth::hasRole('tutor') ? [$userId] : (Auth::hasRole('assistente') ? UserModel::tutorIdsForAssistant($userId) : []);
            $ids = [];

            foreach ($tutor as $t) {
                $ids = array_merge($ids, CourseRights::courseIdsFor($t));
            }

            $corsi = array_values(array_filter(array_map(
                static fn (int $id): ?array => CourseModel::find($id),
                array_values(array_unique($ids))
            )));
        }

        usort($corsi, static fn (array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title']));

        return $corsi;
    }

    public static function puoLeggere(int $courseId): bool
    {
        foreach (self::corsi() as $c) {
            if ((int) $c['id'] === $courseId) {
                return true;
            }
        }

        return false;
    }

    public static function puoPubblicare(int $courseId): bool
    {
        if (Auth::can('board.manage')) {
            return true;
        }

        return Auth::can('board.manage_own') && Auth::hasRole('tutor')
            && in_array($courseId, CourseRights::courseIdsFor((int) Auth::id()), true);
    }

    /**
     * Gli studenti di cui si moderano i contributi in questo corso: null
     * vuol dire tutti.
     *
     * @return list<int>|null
     */
    public static function moderati(int $courseId): ?array
    {
        if (Auth::can('board.manage')) {
            return null;
        }

        if (!Auth::can('board.manage_own') || !Auth::hasRole('tutor')) {
            return [];
        }

        return BoardModel::studentiDelTutor((int) Auth::id(), $courseId);
    }

    public static function puoModerare(?int $studentId, int $courseId): bool
    {
        $moderati = self::moderati($courseId);

        return $moderati === null || ($studentId !== null && in_array($studentId, $moderati, true));
    }

    /**
     * Correggere, mettere in evidenza o togliere una risorsa pubblicata:
     * chi ha `board.manage` tutte; il tutor quelle sue e quelle dei suoi
     * studenti, nei corsi dei suoi gruppi.
     *
     * @param array<string, mixed> $risorsa
     */
    public static function gestisce(array $risorsa): bool
    {
        if (Auth::can('board.manage')) {
            return true;
        }

        $courseId = (int) $risorsa['course_id'];
        $autore = $risorsa['author_id'] === null ? null : (int) $risorsa['author_id'];

        return self::puoPubblicare($courseId) && ($autore === (int) Auth::id() || self::puoModerare($autore, $courseId));
    }

    /** Propone e racconta: lo studente iscritto al corso. */
    public static function partecipa(int $courseId): bool
    {
        return Auth::hasRole('studente') && EnrollmentModel::find((int) Auth::id(), $courseId) !== null;
    }
}
