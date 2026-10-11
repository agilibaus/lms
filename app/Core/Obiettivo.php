<?php

declare(strict_types=1);

namespace App\Core;

/**
 * L'obiettivo del corso e quello del modulo nella pagina del corso (11/10,
 * scelte di Alessandro su un mockup):
 *
 *   - **l'obiettivo del corso** ha un riquadro suo, subito dopo la
 *     descrizione: completo nelle prime tre visite dello studente, poi
 *     ridotto a una riga che si riapre con «Mostra», come il benvenuto del
 *     tutor. Le visite si contano solo quando l'obiettivo c'e'
 *     (`course_objective_views`), cosi' un obiettivo scritto dopo si vede
 *     completo anche a chi il corso l'aveva gia' aperto;
 *   - **l'obiettivo del modulo** sta sotto il titolo del modulo: completo
 *     nel modulo in corso, ridotto in tutti gli altri;
 *   - **lo staff li vede sempre completi**;
 *   - lo studente non puo' richiuderli da se' prima del tempo.
 *
 * Qui stanno le regole, senza database.
 */
final class Obiettivo
{
    /** Le visite in cui l'obiettivo del corso e' completo; dalla successiva e' ridotto. */
    public const VISITE_COMPLETE = 3;

    /** Completo o ridotto, per la visita appena registrata (compresa). */
    public static function modoCorso(int $visite): string
    {
        return $visite <= self::VISITE_COMPLETE ? 'completo' : 'ridotto';
    }

    /**
     * Il modulo in corso: il primo, nell'ordine del corso, in cui resta
     * qualcosa da fare — una lezione non completata o un questionario non
     * superato. Anche se e' chiuso: chi ha finito tutto quello che e' aperto
     * e aspetta il modulo dopo legge in anticipo dove andra'. Null quando
     * non resta niente.
     *
     * @param list<array<string, mixed>> $modules
     * @param array<int, list<array<string, mixed>>> $lessonsByModule
     * @param list<int> $completedLessonIds
     * @param array<int, array<string, mixed>|null> $quizByModule
     * @param array<int, bool> $quizPassedByModule
     */
    public static function moduloInCorso(
        array $modules,
        array $lessonsByModule,
        array $completedLessonIds,
        array $quizByModule,
        array $quizPassedByModule
    ): ?int {
        foreach ($modules as $module) {
            $id = (int) $module['id'];

            foreach ($lessonsByModule[$id] ?? [] as $lesson) {
                if (!in_array((int) $lesson['id'], $completedLessonIds, true)) {
                    return $id;
                }
            }

            if (($quizByModule[$id] ?? null) !== null && empty($quizPassedByModule[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * L'HTML dell'obiettivo del modulo con «Obiettivo:» davanti: dentro il
     * primo paragrafo se il testo comincia con uno, altrimenti in un
     * paragrafo suo sopra l'elenco. Stringa vuota per un obiettivo vuoto.
     */
    public static function htmlModulo(?string $objective): string
    {
        $html = TestoBreve::html($objective);

        if ($html === '') {
            return '';
        }

        $etichetta = '<strong class="obiettivo-modulo-etichetta">Obiettivo:</strong>';

        return str_starts_with($html, '<p>')
            ? '<p>' . $etichetta . ' ' . substr($html, 3)
            : '<p>' . $etichetta . '</p>' . $html;
    }
}
