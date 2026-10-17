<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Bacheca;
use App\Core\CampoContato;
use App\Models\BoardModel;
use App\Models\CourseModel;

/**
 * Le categorie della Bacheca di un corso (17/10), dal riquadro «Bacheca»
 * di «Modifica corso». Le gestisce chi ha `board.manage`, cioe' l'admin:
 * nome, colore (uno dei 64 della tavolozza di `Bacheca`), descrizione di 200
 * caratteri al piu', ordine. Una categoria con delle risorse non si elimina.
 */
class BoardCategoryController extends AdminController
{
    public function store(array $params): void
    {
        Auth::requirePermission('board.manage');

        $course = CourseModel::find((int) $params['id']);

        if ($course === null) {
            $this->notFound('Corso non trovato.');
            return;
        }

        $qui = self::qui((int) $course['id']);
        [$nome, $colore, $descrizione] = $this->campi($qui);
        BoardModel::creaCategoria((int) $course['id'], $nome, $colore, $descrizione);
        $this->success('Categoria «' . $nome . '» aggiunta alla bacheca.', $qui);
    }

    public function update(array $params): void
    {
        $categoria = $this->categoria((int) $params['id']);
        $qui = self::qui((int) $categoria['course_id']);
        [$nome, $colore, $descrizione] = $this->campi($qui);
        BoardModel::aggiornaCategoria((int) $categoria['id'], $nome, $colore, $descrizione);
        $this->success('Categoria «' . $nome . '» salvata.', $qui);
    }

    public function destroy(array $params): void
    {
        $categoria = $this->categoria((int) $params['id']);
        $qui = self::qui((int) $categoria['course_id']);

        if (!BoardModel::eliminaCategoria((int) $categoria['id'])) {
            $this->fail('La categoria «' . $categoria['name'] . '» ha delle risorse: spostale in un\'altra categoria prima di eliminarla.', $qui);
        }

        $this->success('Categoria «' . $categoria['name'] . '» eliminata.', $qui);
    }

    public function move(array $params): void
    {
        $categoria = $this->categoria((int) $params['id']);
        BoardModel::spostaCategoria((int) $categoria['id'], ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down');
        $this->redirect(self::qui((int) $categoria['course_id']));
    }

    /** @return array<string, mixed> */
    private function categoria(int $id): array
    {
        Auth::requirePermission('board.manage');

        $categoria = BoardModel::categoria($id);

        if ($categoria === null) {
            $this->notFound('Categoria non trovata.');
            exit;
        }

        return $categoria;
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function campi(string $qui): array
    {
        $nome = trim((string) ($_POST['name'] ?? ''));
        $colore = Bacheca::nellaTavolozza((string) ($_POST['color'] ?? ''));
        $descrizione = CampoContato::taglia((string) ($_POST['description'] ?? ''), Bacheca::MAX_DESCRIZIONE_CATEGORIA + 1);

        if ($nome === '' || mb_strlen($nome) > Bacheca::MAX_NOME_CATEGORIA) {
            $this->fail('Il nome della categoria serve, e al più di ' . Bacheca::MAX_NOME_CATEGORIA . ' caratteri.', $qui);
        }

        if ($colore === null) {
            $this->fail('Scegli uno dei colori della tavolozza.', $qui);
        }

        if (mb_strlen($descrizione) > Bacheca::MAX_DESCRIZIONE_CATEGORIA) {
            $this->fail('La descrizione supera i ' . Bacheca::MAX_DESCRIZIONE_CATEGORIA . ' caratteri.', $qui);
        }

        return [$nome, $colore, $descrizione === '' ? null : $descrizione];
    }

    private static function qui(int $courseId): string
    {
        return '/admin/courses/' . $courseId . '/edit#bacheca';
    }
}
