<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CourseController;

/** @var App\Core\Router $router */

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/', [CourseController::class, 'index']);
$router->get('/courses/{id}', [CourseController::class, 'show']);
