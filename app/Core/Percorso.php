<?php

declare(strict_types=1);

namespace App\Core;

/**
 * La gerarchia dei moduli nella pagina del corso, per lo studente (11/10,
 * scelta da Alessandro su un mockup): il modulo che sta seguendo conta piu'
 * degli altri, e quando lo finisce conta il successivo.
 *
 * UNA REGOLA SOLA, che si adatta da se' al numero dei moduli:
 *
 *   - **in corso**: il primo modulo in cui resta una lezione da completare o
 *     un questionario da superare (`Obiettivo::moduloInCorso()`, anche se
 *     chiuso). Aperto, in evidenza, con «In corso» e «Riprendi da…»;
 *   - **prossimo**: il primo modulo non completato dopo quello in corso.
 *     Aperto, con «Prossimo», anche se chiuso per data: si vede quando si
 *     apre;
 *   - **completato**: tutte le lezioni completate e il questionario, se c'e',
 *     superato. Una riga, «✓ … completato · 3 lezioni», che si apre;
 *   - **da fare**: tutti gli altri. Una riga, con il numero delle lezioni o
 *     la data di apertura, che si apre.
 *
 * Con due o tre moduli la pagina resta quasi com'era; con dieci si vedono il
 * presente e il passo dopo. «Mostra tutti i moduli» apre tutto per quella
 * visita (`?moduli=tutti`), e alla visita dopo si riparte dalla regola. Lo
 * staff vede sempre tutto aperto, senza etichette. A corso finito non c'e'
 * un modulo in corso: sono tutti righe «completato».
 *
 * Qui stanno le regole, senza database.
 */
final class Percorso
{
    public const IN_CORSO = 'in_corso';
    public const PROSSIMO = 'prossimo';
    public const COMPLETATO = 'completato';
    public const DA_FARE = 'da_fare';

    /** Il parametro dell'indirizzo che apre tutti i moduli per una visita. */
    public const TUTTI = 'tutti';

    /**
     * Lo stato di ogni modulo, per id.
     *
     * @param list<array<string, mixed>> $modules
     * @param array<int, list<array<string, mixed>>> $lessonsByModule
     * @param list<int> $completedLessonIds
     * @param array<int, array<string, mixed>|null> $quizByModule
     * @param array<int, bool> $quizPassedByModule
     * @return array<int, string>
     */
    public static function stati(
        array $modules,
        array $lessonsByModule,
        array $completedLessonIds,
        array $quizByModule,
        array $quizPassedByModule
    ): array {
        $inCorso = Obiettivo::moduloInCorso($modules, $lessonsByModule, $completedLessonIds, $quizByModule, $quizPassedByModule);
        $stati = [];
        $dopoInCorso = false;
        $prossimoAssegnato = false;

        foreach ($modules as $module) {
            $id = (int) $module['id'];

            if ($id === $inCorso) {
                $stati[$id] = self::IN_CORSO;
                $dopoInCorso = true;

                continue;
            }

            if (self::completato($id, $lessonsByModule, $completedLessonIds, $quizByModule, $quizPassedByModule)) {
                $stati[$id] = self::COMPLETATO;

                continue;
            }

            if ($dopoInCorso && !$prossimoAssegnato) {
                $stati[$id] = self::PROSSIMO;
                $prossimoAssegnato = true;

                continue;
            }

            $stati[$id] = self::DA_FARE;
        }

        return $stati;
    }

    /**
     * Da dove riprendere nel modulo in corso: la prima lezione non
     * completata, con quante lezioni del modulo sono fatte. Il questionario
     * non entra nel conto (Alessandro, 11/10). Null se le lezioni sono tutte
     * fatte: allora resta solo il questionario.
     *
     * @param list<array<string, mixed>> $lessons
     * @param list<int> $completedLessonIds
     * @return array{lezione: array<string, mixed>, fatte: int, totale: int}|null
     */
    public static function riprendi(array $lessons, array $completedLessonIds): ?array
    {
        $fatte = 0;
        $prossima = null;

        foreach ($lessons as $lesson) {
            if (in_array((int) $lesson['id'], $completedLessonIds, true)) {
                $fatte++;
            } elseif ($prossima === null) {
                $prossima = $lesson;
            }
        }

        return $prossima === null ? null : ['lezione' => $prossima, 'fatte' => $fatte, 'totale' => count($lessons)];
    }

    /** «3 lezioni», «1 lezione», «nessuna lezione». */
    public static function lezioni(int $n): string
    {
        return match ($n) {
            0 => 'nessuna lezione',
            1 => '1 lezione',
            default => $n . ' lezioni',
        };
    }

    /**
     * @param array<int, list<array<string, mixed>>> $lessonsByModule
     * @param list<int> $completedLessonIds
     * @param array<int, array<string, mixed>|null> $quizByModule
     * @param array<int, bool> $quizPassedByModule
     */
    private static function completato(
        int $id,
        array $lessonsByModule,
        array $completedLessonIds,
        array $quizByModule,
        array $quizPassedByModule
    ): bool {
        $lessons = $lessonsByModule[$id] ?? [];
        $quiz = $quizByModule[$id] ?? null;

        // Un modulo vuoto non e' completato: non c'e' niente da aver fatto.
        if ($lessons === [] && $quiz === null) {
            return false;
        }

        foreach ($lessons as $lesson) {
            if (!in_array((int) $lesson['id'], $completedLessonIds, true)) {
                return false;
            }
        }

        return $quiz === null || !empty($quizPassedByModule[$id]);
    }
}
