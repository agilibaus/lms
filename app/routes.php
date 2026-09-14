<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\LessonController;
use App\Controllers\ModuleController;

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
