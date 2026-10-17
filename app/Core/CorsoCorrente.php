<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;
use App\Auth\BachecaScope;
use App\Auth\CourseRights;
use App\Models\BoardModel;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;

/**
 * Il corso in cui ci si trova, e il suo ramo nella sidebar (17/10,
 * Alessandro, variante C).
 *
 * LA DECISIONE. Le voci che agiscono su un corso, la Bacheca e «L'esperto
 * risponde», non stanno piu' nel menu di piattaforma con un menu a tendina
 * del corso dentro la pagina. Compaiono solo quando si e' dentro un corso,
 * in un ramo sotto «I miei corsi» (per lo staff, «Corsi»): il nome del
 * corso e, rientrate, le sue voci. Il ramo e' sempre uno solo, quello del
 * corso aperto, con qualunque numero di iscrizioni. Si cambia corso da
 * «I miei corsi».
 *
 * DENTRO UN CORSO. Una pagina e' di un corso se i suoi dati lo dicono:
 * `course` (la pagina del corso, lezioni, quiz, Modifica corso, il report e
 * la fruizione del corso), `module` (le pagine di un modulo, che sa di
 * quale corso e'), `corso` (la Bacheca e «L'esperto risponde»). Lo legge
 * `View::render()` per tutte le pagine, cosi' una pagina nuova di un corso
 * ha il ramo senza ricordarsene. Gli elenchi (Corsi, Gestione corsi,
 * Report) non sono di un corso.
 */
final class CorsoCorrente
{
    /** Il corso di una pagina, dai dati che le arrivano; null se non e' di un corso. */
    public static function da(array $dati): ?int
    {
        foreach (['corso' => 'id', 'course' => 'id', 'module' => 'course_id'] as $chiave => $campo) {
            $id = is_array($dati[$chiave] ?? null) ? (int) ($dati[$chiave][$campo] ?? 0) : 0;

            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Il ramo del corso per chi guarda: nome, voci che puo' aprire, quante
     * proposte della bacheca aspetta che valuti. Null se non c'e' niente da
     * mostrare.
     *
     * - Bacheca: per chi la legge (`BachecaScope::puoLeggere`).
     * - «L'esperto risponde»: per lo studente iscritto e per il tutor del
     *   corso, che la legge come uno studente. L'admin, che e' l'esperto,
     *   ha «Domande».
     *
     * @return array{id: int, title: string, bacheca: bool, esperto: bool, daValutare: int}|null
     */
    public static function ramo(int $courseId): ?array
    {
        $corso = CourseModel::find($courseId);

        if ($corso === null || !Auth::check()) {
            return null;
        }

        $userId = (int) Auth::id();
        $bacheca = BachecaScope::puoLeggere($courseId);
        $esperto = (Auth::hasRole('studente') && EnrollmentModel::find($userId, $courseId) !== null)
            || (Auth::hasRole('tutor') && in_array($courseId, CourseRights::courseIdsFor($userId), true));

        if (!$bacheca && !$esperto) {
            return null;
        }

        $moderati = BachecaScope::moderati($courseId);

        return [
            'id' => $courseId,
            'title' => (string) $corso['title'],
            'bacheca' => $bacheca,
            'esperto' => $esperto,
            'daValutare' => $bacheca && $moderati !== [] ? count(BoardModel::inAttesa($courseId, $moderati)) : 0,
        ];
    }
}
