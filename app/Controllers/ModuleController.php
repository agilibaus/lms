<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\ModuleModel;

class ModuleController
{
    public function createForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $course = CourseModel::find((int) $params['courseId']);

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        View::render('modules/form', [
            'pageTitle' => 'Nuovo modulo',
            'course' => $course,
            'module' => null,
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $courseId = (int) $params['courseId'];
        $course = CourseModel::find($courseId);

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            $_SESSION['flash_error'] = 'Il titolo del modulo è obbligatorio.';
            header('Location: /courses/' . $courseId . '/modules/create');
            exit;
        }

        ModuleModel::create($courseId, $title, isset($_POST['quiz_required']));

        header('Location: /courses/' . $courseId);
        exit;
    }

    public function editForm(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $module = ModuleModel::find((int) $params['id']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        $course = CourseModel::find((int) $module['course_id']);

        View::render('modules/form', [
            'pageTitle' => 'Modifica modulo',
            'course' => $course,
            'module' => $module,
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $module = ModuleModel::find((int) $params['id']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        $title = trim($_POST['title'] ?? '');

        if ($title !== '') {
            ModuleModel::update((int) $module['id'], $title, isset($_POST['quiz_required']));
        }

        header('Location: /courses/' . $module['course_id']);
        exit;
    }

    public function destroy(array $params): void
    {
        Auth::requireRole('admin', 'tutor');

        $module = ModuleModel::find((int) $params['id']);

        if (!$module) {
            http_response_code(404);
            echo 'Modulo non trovato.';
            return;
        }

        ModuleModel::delete((int) $module['id']);

        header('Location: /courses/' . $module['course_id']);
        exit;
    }
}
