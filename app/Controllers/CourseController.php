<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\View;
use App\Models\CourseModel;

class CourseController
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            // Staff: vede tutti i corsi, pubblicati e in bozza.
            $courses = CourseModel::allForStaff();
        } else {
            // Studente: solo i corsi a cui è iscritto, con progresso.
            $courses = CourseModel::enrolledForUser((int) Auth::id());
        }

        View::render('courses/index', [
            'pageTitle' => 'Corsi',
            'courses' => $courses,
        ]);
    }

    public function show(array $params): void
    {
        Auth::requireLogin();

        $course = CourseModel::find((int) $params['id']);

        if (!$course) {
            http_response_code(404);
            echo 'Corso non trovato.';
            return;
        }

        View::render('courses/show', [
            'pageTitle' => $course['title'],
            'course' => $course,
        ]);
    }
}
