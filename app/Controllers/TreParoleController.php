<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\GrigliaCorso;
use App\Core\TreParole;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\GroupModel;
use App\Models\ModuleModel;
use App\Models\TreParoleModel;

/**
 * «Tre parole» (21/10, Alessandro): l'invio dello studente alla fine di un
 * modulo, e la pagina in cui le leggono il tutor del gruppo e
 * l'amministrazione. Le regole in `App\Core\TreParole`.
 */
class TreParoleController
{
    /**
     * POST /courses/{id}/moduli/{modulo}/tre-parole — lo studente iscritto,
     * su un modulo del corso che ha completato da quando il corso le chiede,
     * e a cui non ha ancora risposto.
     */
    public function invia(array $params): void
    {
        Auth::requireLogin();

        $course = CourseModel::find((int) $params['id']);
        $userId = (int) Auth::id();

        if ($course === null || !Auth::hasRole('studente') || EnrollmentModel::find($userId, (int) $course['id']) === null) {
            $this->vietato('Le tre parole le scrive lo studente, nei corsi a cui è iscritto.');
        }

        $courseId = (int) $course['id'];
        $modulo = ModuleModel::find((int) $params['modulo']);
        $chiesteDal = $course['three_words_since'] ?? null;

        if ($modulo === null || (int) $modulo['course_id'] !== $courseId || $chiesteDal === null) {
            $this->vietato('Questo corso non chiede le tre parole per questo modulo.');
        }

        $moduleId = (int) $modulo['id'];

        // Le regole della pagina del corso, rifatte qui: un modulo non
        // completato, o completato prima che il corso le chiedesse, non le
        // riceve nemmeno con un invio costruito a mano.
        if (TreParoleModel::aCuiChiedere($moduleId, $courseId, (string) $chiesteDal, null, $userId) === []) {
            $this->vietato('Le tre parole si scrivono dopo aver completato il modulo.');
        }

        $parole = TreParole::daModulo($_POST['parole'] ?? null);
        $qui = '/courses/' . $courseId . '#tre-parole';

        if ($parole === null) {
            $inviate = is_array($_POST['parole'] ?? null) ? $_POST['parole'] : [];
            $_SESSION['tre_parole'] = [
                'tipo' => 'errore',
                'module_id' => $moduleId,
                'parole' => array_map(static fn ($p): string => is_string($p) ? mb_substr($p, 0, TreParole::MASSIMO) : '', array_slice(array_values($inviate), 0, 3)),
            ];
            $this->vai($qui);
        }

        // Gia' scritte: non si cambiano, e il secondo invio (un doppio clic,
        // la pagina tornata indietro) finisce come il primo, con il grazie.
        TreParoleModel::salva($userId, $moduleId, $courseId, GroupModel::delloStudenteNelCorso($userId, $courseId), $parole);
        $_SESSION['tre_parole'] = ['tipo' => 'grazie', 'modulo' => (string) $modulo['title']];
        $this->vai($qui);
    }

    /**
     * GET /tre-parole?corso=… — le parole di un modulo, per il tutor dei
     * gruppi del corso e per l'admin. Anonime di partenza (`?vista=nomi` per
     * i nomi); un modulo per volta (`?modulo=N`, da 1); un gruppo per volta
     * se ce n'e' piu' d'uno (`?gruppo=`).
     */
    public function pagina(array $params = []): void
    {
        Auth::requireLogin();

        $admin = Auth::hasRole('admin');

        if (!$admin && !Auth::hasRole('tutor')) {
            $this->vietato('Le tre parole le leggono il tutor del gruppo e l\'amministrazione.');
        }

        $course = CourseModel::find((int) ($_GET['corso'] ?? 0));

        if ($course === null) {
            http_response_code(404);
            echo 'Corso non trovato.';

            return;
        }

        $courseId = (int) $course['id'];

        // I gruppi fra cui scegliere: per il tutor i suoi, fra quelli a cui
        // il corso e' assegnato; per l'admin tutti quelli del corso.
        $delCorso = GroupModel::courseGroupIds($courseId);
        $gruppi = [];

        foreach ($admin ? array_map(static fn (int $id): ?array => GroupModel::find($id), $delCorso) : GroupModel::forTutor((int) Auth::id()) as $g) {
            if ($g !== null && in_array((int) $g['id'], $delCorso, true)) {
                $gruppi[(int) $g['id']] = (string) $g['name'];
            }
        }

        asort($gruppi, SORT_FLAG_CASE | SORT_STRING);

        if (!$admin && $gruppi === []) {
            $this->vietato('Questo corso non è assegnato a un tuo gruppo.');
        }

        $chiesto = filter_var($_GET['gruppo'] ?? null, FILTER_VALIDATE_INT);
        $gruppo = $chiesto !== false && isset($gruppi[$chiesto]) ? (int) $chiesto : null;

        // Il tutor con un gruppo solo guarda quello, e la pagina lo nomina.
        if (!$admin && count($gruppi) === 1) {
            $gruppo = (int) array_key_first($gruppi);
        }
        // «Tutti»: per l'admin anche chi non ha un gruppo (null), per il
        // tutor tutti i suoi.
        $groupIds = $gruppo !== null ? [$gruppo] : ($admin ? null : array_keys($gruppi));

        $moduli = ModuleModel::forCourse($courseId);
        $n = GrigliaCorso::modulo($_GET['modulo'] ?? null, count($moduli)) ?? self::moduloDiPartenza($moduli, $groupIds);
        $modulo = $moduli[$n - 1] ?? null;
        $righe = $modulo === null ? [] : TreParoleModel::delModulo((int) $modulo['id'], $groupIds);
        $chiesteDal = $course['three_words_since'] ?? null;
        $aCuiChiedere = $modulo === null || $chiesteDal === null
            ? []
            : TreParoleModel::aCuiChiedere((int) $modulo['id'], $courseId, (string) $chiesteDal, $groupIds);

        View::render('tre_parole/index', [
            'pageTitle' => 'Tre parole · ' . $course['title'],
            'corso' => $course,
            'moduli' => $moduli,
            'numero' => $n,
            'modulo' => $modulo,
            'gruppi' => $gruppi,
            'gruppo' => $gruppo,
            'vista' => TreParole::vista($_GET['vista'] ?? null),
            'righe' => $righe,
            'aCuiChiedere' => $aCuiChiedere,
        ]);
    }

    /**
     * Senza un modulo scelto: l'ultimo che ha delle parole, il primo se
     * nessuno ne ha ancora. Chi apre la pagina prima di un incontro cerca
     * le parole appena arrivate.
     *
     * @param list<array<string, mixed>> $moduli
     * @param int[]|null                  $groupIds
     */
    private static function moduloDiPartenza(array $moduli, ?array $groupIds): int
    {
        for ($i = count($moduli) - 1; $i >= 0; $i--) {
            if (TreParoleModel::delModulo((int) $moduli[$i]['id'], $groupIds) !== []) {
                return $i + 1;
            }
        }

        return 1;
    }

    private function vai(string $dove): never
    {
        header('Location: ' . $dove);
        exit;
    }

    private function vietato(string $perche): never
    {
        http_response_code(403);
        echo $perche;
        exit;
    }
}
