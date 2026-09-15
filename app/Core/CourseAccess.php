<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;
use App\Models\ModuleModel;
use App\Models\QuizAttemptModel;
use App\Models\QuizModel;

/**
 * Sblocco progressivo dei moduli.
 *
 * Se un modulo ha `quiz_required = 1`, tutti i moduli successivi (per posizione)
 * restano bloccati finche' lo studente non ha superato il quiz di quel modulo.
 * Lo staff (admin/tutor/assistente) non e' mai soggetto al blocco.
 */
class CourseAccess
{
    /**
     * @return int[] id dei moduli non ancora accessibili all'utente
     */
    public static function lockedModuleIds(int $userId, int $courseId): array
    {
        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            return [];
        }

        $locked = [];
        $blocked = false;

        foreach (ModuleModel::forCourse($courseId) as $module) {
            if ($blocked) {
                $locked[] = (int) $module['id'];
                continue;
            }

            if (!(bool) ($module['quiz_required'] ?? false)) {
                continue;
            }

            $quiz = QuizModel::forModule((int) $module['id']);

            // Un modulo marcato come obbligatorio ma senza quiz (o con quiz vuoto)
            // non blocca nulla: sarebbe un vicolo cieco per lo studente.
            if ($quiz === null || QuizModel::countQuestions((int) $quiz['id']) === 0) {
                continue;
            }

            if (!QuizAttemptModel::hasPassed($userId, (int) $quiz['id'])) {
                $blocked = true;
            }
        }

        return $locked;
    }

    public static function isModuleLocked(int $userId, int $moduleId): bool
    {
        $module = ModuleModel::find($moduleId);

        if ($module === null) {
            return false;
        }

        return in_array($moduleId, self::lockedModuleIds($userId, (int) $module['course_id']), true);
    }
}
