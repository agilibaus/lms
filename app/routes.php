<?php

declare(strict_types=1);

use App\Controllers\Admin\CourseController as AdminCourseController;
use App\Controllers\Admin\GroupController as AdminGroupController;
use App\Controllers\Admin\PermissionController as AdminPermissionController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController as AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CertificateController;
use App\Controllers\CourseController;
use App\Controllers\LessonController;
use App\Controllers\LiveSessionController;
use App\Controllers\ModuleController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\QuizController;
use App\Controllers\RegistrationController;
use App\Controllers\ReportController;

/** @var App\Core\Router $router */

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

// --- Registrazione e verifica dell'indirizzo --------------------------
$router->get('/register', [RegistrationController::class, 'showForm']);
$router->post('/register', [RegistrationController::class, 'register']);
$router->get('/register/verifica-inviata', [RegistrationController::class, 'pending']);
$router->get('/register/rinvia', [RegistrationController::class, 'resendForm']);
$router->post('/register/rinvia', [RegistrationController::class, 'resend']);
$router->get('/verifica-email/{token}', [RegistrationController::class, 'verify']);

// --- Recupero password ------------------------------------------------
$router->get('/password/dimenticata', [PasswordResetController::class, 'requestForm']);
$router->post('/password/dimenticata', [PasswordResetController::class, 'sendLink']);
$router->get('/password/reimposta/{token}', [PasswordResetController::class, 'resetForm']);
$router->post('/password/reimposta/{token}', [PasswordResetController::class, 'reset']);

// --- Catalogo e auto-iscrizione ---------------------------------------
$router->get('/catalogo', [CatalogController::class, 'index']);
$router->post('/catalogo/{id}/iscrizione', [CatalogController::class, 'enroll']);

// --- Profilo dell'utente ----------------------------------------------
$router->get('/profilo', [ProfileController::class, 'show']);
$router->post('/profilo', [ProfileController::class, 'update']);
$router->post('/profilo/immagine', [ProfileController::class, 'updateAvatar']);
$router->post('/profilo/immagine/elimina', [ProfileController::class, 'deleteAvatar']);
$router->get('/utenti/{id}/immagine', [ProfileController::class, 'avatar']);

// --- Copertina del corso (file in /storage, servito dall'applicazione) --
$router->get('/corsi/{id}/copertina', [CourseController::class, 'cover']);
$router->get('/corsi/{id}/copertina/{size}', [CourseController::class, 'cover']);

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
$router->post('/lessons/{id}/materials/{materialId}/move', [LessonController::class, 'moveMaterial']);
$router->post('/lessons/{id}/images', [LessonController::class, 'uploadImage']);
$router->get('/lessons/{id}/images/{file}', [LessonController::class, 'showImage']);
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

// --- Pannello di amministrazione --------------------------------------
$router->get('/admin/users', [AdminUserController::class, 'index']);
$router->get('/admin/users/create', [AdminUserController::class, 'createForm']);
$router->post('/admin/users', [AdminUserController::class, 'store']);
$router->get('/admin/users/{id}/edit', [AdminUserController::class, 'editForm']);
$router->post('/admin/users/{id}', [AdminUserController::class, 'update']);
$router->post('/admin/users/{id}/password', [AdminUserController::class, 'resetPassword']);
$router->post('/admin/users/{id}/delete', [AdminUserController::class, 'destroy']);
$router->post('/admin/users/{id}/groups', [AdminUserController::class, 'addGroup']);
$router->post('/admin/users/{id}/groups/{groupId}/delete', [AdminUserController::class, 'removeGroup']);

$router->get('/admin/groups', [AdminGroupController::class, 'index']);
$router->get('/admin/groups/create', [AdminGroupController::class, 'createForm']);
$router->post('/admin/groups', [AdminGroupController::class, 'store']);
$router->get('/admin/groups/{id}/edit', [AdminGroupController::class, 'editForm']);
$router->post('/admin/groups/{id}', [AdminGroupController::class, 'update']);
$router->post('/admin/groups/{id}/delete', [AdminGroupController::class, 'destroy']);
$router->post('/admin/groups/{id}/members', [AdminGroupController::class, 'addMember']);
$router->post('/admin/groups/{id}/members/{userId}/delete', [AdminGroupController::class, 'removeMember']);
$router->post('/admin/groups/{id}/courses', [AdminGroupController::class, 'addCourse']);
$router->post('/admin/groups/{id}/courses/{courseId}/delete', [AdminGroupController::class, 'removeCourse']);

$router->get('/admin/courses', [AdminCourseController::class, 'index']);
$router->get('/admin/courses/create', [AdminCourseController::class, 'createForm']);
$router->post('/admin/courses', [AdminCourseController::class, 'store']);
$router->get('/admin/courses/{id}/edit', [AdminCourseController::class, 'editForm']);
$router->post('/admin/courses/{id}', [AdminCourseController::class, 'update']);
$router->post('/admin/courses/{id}/delete', [AdminCourseController::class, 'destroy']);
$router->post('/admin/courses/{id}/copertina', [AdminCourseController::class, 'updateCover']);
$router->post('/admin/courses/{id}/copertina/elimina', [AdminCourseController::class, 'deleteCover']);

// --- Configurazione: posta elettronica e Google Meet ---
$router->get('/admin/settings/posta', [SettingsController::class, 'mail']);
$router->post('/admin/settings/posta', [SettingsController::class, 'updateMail']);
$router->post('/admin/settings/posta/prova', [SettingsController::class, 'sendTestMail']);
$router->get('/admin/settings/meet', [SettingsController::class, 'meet']);
$router->post('/admin/settings/meet', [SettingsController::class, 'updateMeet']);
$router->post('/admin/settings/meet/chiave/elimina', [SettingsController::class, 'deleteMeetKey']);
$router->post('/admin/settings/meet/prova', [SettingsController::class, 'testMeet']);
$router->post('/admin/courses/{id}/enrollments', [AdminCourseController::class, 'enroll']);
$router->post('/admin/courses/{id}/enrollments/{userId}/delete', [AdminCourseController::class, 'unenroll']);
$router->post('/admin/requests/{requestId}', [AdminCourseController::class, 'decideRequest']);

$router->get('/admin/permissions', [AdminPermissionController::class, 'index']);
$router->post('/admin/permissions', [AdminPermissionController::class, 'update']);

// --- Sessioni live (Google Meet) --------------------------------------
$router->get('/live', [LiveSessionController::class, 'index']);
$router->get('/live/create', [LiveSessionController::class, 'createForm']);
$router->post('/live', [LiveSessionController::class, 'store']);
$router->get('/live/{id}/edit', [LiveSessionController::class, 'editForm']);
$router->get('/live/{id}/join', [LiveSessionController::class, 'join']);
$router->get('/live/{id}', [LiveSessionController::class, 'show']);
$router->post('/live/{id}', [LiveSessionController::class, 'update']);
$router->post('/live/{id}/delete', [LiveSessionController::class, 'destroy']);
$router->post('/live/{id}/sync', [LiveSessionController::class, 'syncGoogle']);
$router->post('/live/{id}/attendance/{userId}', [LiveSessionController::class, 'setAttendance']);
