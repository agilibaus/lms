<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

class CourseController
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $db = Database::connection();

        if (Auth::hasRole('admin', 'tutor', 'assistente')) {
            // Staff: vede tutti i corsi, pubblicati e in bozza.
            $courses = $db->query(
                'SELECT id, title, slug, description, cover_image, is_published
                 FROM courses ORDER BY created_at DESC'
            )->fetchAll();
        } else {
            // Studente: solo i corsi a cui è iscritto, con progresso.
            $stmt = $db->prepare(
                'SELECT c.id, c.title, c.slug, c.description, c.cover_image, e.progress_pct
                 FROM courses c
                 INNER JOIN enrollments e ON e.course_id = c.id
                 WHERE e.user_id = :user_id
                 ORDER BY e.enrolled_at DESC'
            );
            $stmt->execute(['user_id' => Auth::id()]);
            $courses = $stmt->fetchAll();
        }

        View::render('courses/index', [
            'pageTitle' => 'Corsi',
            'courses' => $courses,
        ]);
    }

    public function show(array $params): void
    {
        Auth::requireLogin();

        $stmt = Database::connection()->prepare('SELECT * FROM courses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $params['id']]);
        $course = $stmt->fetch();

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
