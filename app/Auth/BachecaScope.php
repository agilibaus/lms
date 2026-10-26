<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\CourseAccess;
use App\Models\BoardModel;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\ModuleModel;
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
 *
 * Dal 25/10 (Alessandro), due regole in piu':
 *   - una risorsa appartiene a un modulo, e lo studente la vede solo
 *     quando quel modulo e' aperto per lui (`CourseAccess`, la regola di
 *     lezioni, materiali e incontri); lo staff le vede tutte;
 *   - la risorsa e' di tutti, le persone sono della classe: i racconti
 *     «Come l'ho utilizzata» e il nome di chi ha proposto una risorsa li
 *     vedono la persona stessa, i compagni dei suoi gruppi del corso, chi la
 *     modera (il tutor del suo gruppo, l'admin). L'assistente no, come per
 *     foto e presentazioni.
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

        $chiave = (int) Auth::id() . ':' . $courseId;

        return self::$moderati[$chiave] ??= BoardModel::studentiDelTutor((int) Auth::id(), $courseId);
    }

    /** @var array<string, list<int>> letti una volta per richiesta */
    private static array $moderati = [];

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

    /**
     * I moduli del corso chiusi per chi guarda: le loro risorse non le vede.
     * Per lo staff nessuno (`CourseAccess::locks`).
     *
     * @return list<int>
     */
    public static function moduliChiusi(int $courseId): array
    {
        return array_map('intval', CourseAccess::lockedModuleIds((int) Auth::id(), $courseId));
    }

    /**
     * I moduli fra cui chi scrive sceglie: per lo studente quelli aperti per
     * lui (una risorsa di un modulo chiuso non la vedrebbe nemmeno chi l'ha
     * proposta), per lo staff tutti.
     *
     * @return list<array<string, mixed>>
     */
    public static function moduliDaScegliere(int $courseId): array
    {
        $chiusi = self::moduliChiusi($courseId);

        return array_values(array_filter(
            ModuleModel::forCourse($courseId),
            static fn (array $m): bool => !in_array((int) $m['id'], $chiusi, true)
        ));
    }

    /** @param array<string, mixed> $risorsa */
    public static function vedeRisorsa(array $risorsa): bool
    {
        return !in_array((int) $risorsa['module_id'], self::moduliChiusi((int) $risorsa['course_id']), true);
    }

    /**
     * Solo le risorse dei moduli aperti per chi guarda.
     *
     * @param list<array<string, mixed>> $risorse
     * @return list<array<string, mixed>>
     */
    public static function visibili(array $risorse, int $courseId): array
    {
        $chiusi = self::moduliChiusi($courseId);

        return array_values(array_filter(
            $risorse,
            static fn (array $r): bool => !in_array((int) $r['module_id'], $chiusi, true)
        ));
    }

    /**
     * Se chi guarda vede la persona dietro un contributo di uno studente
     * (un racconto, il nome di chi ha proposto): la persona stessa, chi la
     * modera, i compagni dei suoi gruppi del corso.
     */
    public static function vedeLaPersona(?int $studente, int $courseId): bool
    {
        if ($studente === null) {
            return false;
        }

        $chi = (int) Auth::id();
        // Una pagina della bacheca chiede di molti racconti: i compagni si
        // leggono una volta per persona e corso.
        self::$compagni[$chi . ':' . $courseId] ??= BoardModel::compagni($chi, $courseId);

        return $studente === $chi
            || self::puoModerare($studente, $courseId)
            || in_array($studente, self::$compagni[$chi . ':' . $courseId], true);
    }

    /** @var array<string, list<int>> */
    private static array $compagni = [];

    /**
     * Lo studente iscritto al corso: si tiene le risorse («Le mie
     * risorse»).
     */
    public static function partecipa(int $courseId): bool
    {
        return Auth::hasRole('studente') && EnrollmentModel::find((int) Auth::id(), $courseId) !== null;
    }

    /**
     * Propone risorse e racconta come le ha usate: lo studente iscritto, con
     * l'interruttore «Proposte in Bacheca» acceso (26/10, `board.propose`).
     */
    public static function propone(int $courseId): bool
    {
        return self::partecipa($courseId) && Auth::can('board.propose');
    }
}
