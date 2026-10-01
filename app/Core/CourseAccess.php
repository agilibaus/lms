<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;
use App\Models\ModuleModel;
use App\Models\QuizAttemptModel;
use App\Models\QuizModel;

/**
 * Che cosa, di un corso, uno studente puo' aprire oggi.
 *
 * Due regole indipendenti chiudono un modulo, e una basta:
 *
 *   per data   il modulo ha `available_from` nel futuro. E' il rilascio
 *              progressivo (§8.7): una data di calendario uguale per tutti
 *              gli studenti. Campo vuoto = sempre aperto.
 *   per quiz   un modulo precedente ha `quiz_required` e il suo quiz non e'
 *              ancora stato superato. Questa regola **incatena**: blocca
 *              tutti i moduli che vengono dopo, non solo il primo.
 *
 * Le due si combinano da sole nel verso giusto. Se il modulo 2 e' chiuso per
 * data e ha il quiz obbligatorio, il suo quiz non e' raggiungibile, quindi il
 * modulo 3 resta chiuso per quiz: lo studente non scavalca aspettando.
 *
 * **Lo staff non e' mai bloccato.** Admin, tutor e assistente devono poter
 * aprire un modulo chiuso per prepararlo: la data riguarda chi segue il
 * corso, non chi lo fa.
 */
class CourseAccess
{
    public const MOTIVO_DATA = 'data';
    public const MOTIVO_QUIZ = 'quiz';

    /**
     * I moduli chiusi del corso, con il motivo.
     *
     * @return array<int, array{motivo: string, available_from: ?string}>
     *         chiave: id del modulo
     */
    public static function locks(int $userId, int $courseId): array
    {
        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            return [];
        }

        $locks = [];
        $catena = false;

        foreach (ModuleModel::forCourse($courseId) as $module) {
            $moduleId = (int) $module['id'];

            // La data per prima: e' il motivo piu' preciso da mostrare, e
            // quello a cui si puo' associare un «torna il giorno tale».
            if (!(bool) $module['is_available']) {
                $locks[$moduleId] = [
                    'motivo' => self::MOTIVO_DATA,
                    'available_from' => $module['available_from'],
                ];
            } elseif ($catena) {
                $locks[$moduleId] = ['motivo' => self::MOTIVO_QUIZ, 'available_from' => null];
            }

            if ($catena) {
                continue;
            }

            // Un modulo chiuso per data chiude anche quelli dopo, se ha il
            // quiz obbligatorio: il suo quiz non si puo' fare, quindi la
            // condizione per proseguire non si puo' soddisfare.
            if (!(bool) ($module['quiz_required'] ?? false)) {
                continue;
            }

            $quiz = QuizModel::forModule($moduleId);

            // Un modulo marcato come obbligatorio ma senza quiz (o con quiz vuoto)
            // non blocca nulla: sarebbe un vicolo cieco per lo studente.
            if ($quiz === null || QuizModel::countQuestions((int) $quiz['id']) === 0) {
                continue;
            }

            if (!(bool) $module['is_available'] || !QuizAttemptModel::hasPassed($userId, (int) $quiz['id'])) {
                $catena = true;
            }
        }

        return $locks;
    }

    /**
     * @return int[] id dei moduli non ancora accessibili all'utente
     */
    public static function lockedModuleIds(int $userId, int $courseId): array
    {
        return array_keys(self::locks($userId, $courseId));
    }

    public static function isModuleLocked(int $userId, int $moduleId): bool
    {
        $module = ModuleModel::find($moduleId);

        if ($module === null) {
            return false;
        }

        return isset(self::locks($userId, (int) $module['course_id'])[$moduleId]);
    }

    /**
     * La data di apertura del prossimo modulo chiuso per data, se c'e'.
     *
     * Serve alla frase mostrata allo studente accanto all'avanzamento. Si
     * guarda solo il rilascio per data: «torna il 15 novembre» e' una
     * promessa che la piattaforma puo' mantenere, mentre per un modulo
     * chiuso da un quiz la data dipende da lui.
     */
    public static function nextUnlockAt(int $userId, int $courseId): ?string
    {
        $date = null;

        foreach (self::locks($userId, $courseId) as $lock) {
            if ($lock['motivo'] !== self::MOTIVO_DATA || $lock['available_from'] === null) {
                continue;
            }

            if ($date === null || $lock['available_from'] < $date) {
                $date = $lock['available_from'];
            }
        }

        return $date;
    }
}
