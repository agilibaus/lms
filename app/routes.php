<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CertificateController;
use App\Controllers\CourseController;
use App\Controllers\LessonController;
use App\Controllers\ModuleController;
use App\Controllers\QuizController;
use App\Controllers\ReportController;

/** @var App\Core\Router $router */

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/', [CourseController::class, 'index']);
$router->get('/courses/{id}', [CourseController::class, 'show']);

$router->get('/courses/{courseId}/modules/create', [ModuleController::class, 'createForm']);
$router->post('/courses/{courseId}/modules', [ModuleController::class, 'store']);
$router->get('/modules/{id}/edit', [ModuleController::class, 'editForm']);
$router->post('/modules/{id}', [ModuleController::class, 'update']);
$router->post('/modules/{id}/delete', [ModuleController::class, 'destroy']);

$router->get('/modules/{moduleId}/lessons/create', [LessonController::class, 'createForm']);
$router->post('/modules/{moduleId}/lessons', [LessonController::class, 'store']);
$router->get('/lessons/{id}', [LessonController::class, 'show']);
$router->get('/lessons/{id}/edit', [LessonController::class, 'editForm']);
$router->post('/lessons/{id}', [LessonController::class, 'update']);
$router->post('/lessons/{id}/delete', [LessonController::class, 'destroy']);
$router->post('/lessons/{id}/materials', [LessonController::class, 'uploadMaterial']);
$router->post('/lessons/{id}/materials/{materialId}/delete', [LessonController::class, 'deleteMaterial']);
$router->get('/lessons/{id}/video', [LessonController::class, 'streamVideo']);
$router->post('/lessons/{id}/complete', [LessonController::class, 'complete']);
$router->get('/materials/{id}/download', [LessonController::class, 'downloadMaterial']);

// --- Quiz -------------------------------------------------------------
$router->get('/modules/{moduleId}/quiz/create', [QuizController::class, 'createForm']);
$router->post('/modules/{moduleId}/quiz', [QuizController::class, 'store']);
$router->get('/quizzes/{id}/edit', [QuizController::class, 'editForm']);
$router->post('/quizzes/{id}', [QuizController::class, 'update']);
$router->post('/quizzes/{id}/delete', [QuizController::class, 'destroy']);
$router->post('/quizzes/{id}/questions', [QuizController::class, 'storeQuestion']);
$router->get('/questions/{id}/edit', [QuizController::class, 'editQuestionForm']);
$router->post('/questions/{id}', [QuizController::class, 'updateQuestion']);
$router->post('/questions/{id}/delete', [QuizController::class, 'destroyQuestion']);
$router->get('/quizzes/{id}', [QuizController::class, 'show']);
$router->post('/quizzes/{id}/attempts', [QuizController::class, 'submit']);
$router->get('/attempts/{id}', [QuizController::class, 'result']);

// --- Certificati ------------------------------------------------------
$router->get('/certificates', [CertificateController::class, 'index']);
$router->get('/certificates/{id}/download', [CertificateController::class, 'download']);
$router->post('/certificates/issue', [CertificateController::class, 'issue']);
$router->post('/certificates/{id}/revoke', [CertificateController::class, 'revoke']);
$router->get('/verify/{code}', [CertificateController::class, 'verify']);

// --- Report -----------------------------------------------------------
$router->get('/reports', [ReportController::class, 'index']);
$router->get('/reports/courses/{id}', [ReportController::class, 'course']);
$router->get('/reports/courses/{id}/csv', [ReportController::class, 'courseCsv']);
$router->get('/reports/students/{id}', [ReportController::class, 'student']);
$router->get('/reports/students/{id}/csv', [ReportController::class, 'studentCsv']);
$router->get('/reports/groups/{id}', [ReportController::class, 'group']);
$router->get('/reports/groups/{id}/csv', [ReportController::class, 'groupCsv']);
