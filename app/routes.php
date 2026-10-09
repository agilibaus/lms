<?php

declare(strict_types=1);

use App\Controllers\Admin\CourseController as AdminCourseController;
use App\Controllers\Admin\GroupController as AdminGroupController;
use App\Controllers\Admin\PermissionController as AdminPermissionController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController as AdminUserController;
use App\Controllers\Admin\UserImportController;
use App\Controllers\Admin\TutorWelcomeController;
use App\Controllers\AgendaController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CertificateController;
use App\Controllers\CourseController;
use App\Controllers\FontController;
use App\Controllers\GroupPageController;
use App\Controllers\LessonController;
use App\Controllers\LiveSessionController;
use App\Controllers\ModuleController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\FirstAccessController;
use App\Controllers\QuestionController;
use App\Controllers\WelcomeController;
use App\Controllers\QuizController;
use App\Controllers\RegistrationController;
use App\Controllers\ReportController;
use App\Controllers\VideoProgressController;

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

// --- Video di benvenuto ------------------------------------------------
// La pagina risponde a chiunque abbia fatto accesso: ci si arriva dirottati
// al primo accesso, oppure dal collegamento nel profilo per rivederlo.
$router->get('/benvenuto', [WelcomeController::class, 'show']);
$router->post('/benvenuto/visto', [WelcomeController::class, 'seen']);

// --- Profilo dell'utente ----------------------------------------------
$router->get('/profilo', [ProfileController::class, 'show']);
$router->post('/profilo', [ProfileController::class, 'update']);
$router->post('/profilo/come-ti-vedono', [ProfileController::class, 'updateNameDisplay']);

// Il primo accesso dello studente (08/10): la scelta di come lo vedono gli
// altri e, se serve, la nuova password, in una pagina sola.
$router->get('/primo-accesso', [FirstAccessController::class, 'show']);
$router->post('/primo-accesso', [FirstAccessController::class, 'save']);
$router->get('/profilo/password', [ProfileController::class, 'passwordForm']);
$router->post('/profilo/password', [ProfileController::class, 'changePassword']);
$router->post('/profilo/immagine', [ProfileController::class, 'updateAvatar']);
$router->post('/profilo/immagine/elimina', [ProfileController::class, 'deleteAvatar']);
$router->get('/utenti/{id}/immagine', [ProfileController::class, 'avatar']);

// La pagina di un gruppo per chi ne fa parte: tutor al centro, compagni
// attorno. `/gruppi/{id}/immagine` (il logo) ha un segmento in piu', quindi
// le due rotte non si catturano a vicenda.
$router->get('/gruppi/{id}', [GroupPageController::class, 'show']);

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
$router->post('/modules/{id}/move', [ModuleController::class, 'move']);
$router->post('/lessons/{id}/move', [LessonController::class, 'move']);
$router->post('/lessons/{id}/materials/{materialId}/move', [LessonController::class, 'moveMaterial']);
$router->post('/lessons/{id}/images', [LessonController::class, 'uploadImage']);
$router->get('/lessons/{id}/images/{file}', [LessonController::class, 'showImage']);
$router->get('/lessons/{id}/video', [LessonController::class, 'streamVideo']);
$router->post('/lessons/{id}/video/detach', [LessonController::class, 'detachVideo']);
$router->post('/lessons/{id}/video/delete', [LessonController::class, 'deleteVideo']);
$router->post('/lessons/{id}/complete', [LessonController::class, 'complete']);

// Fruizione dei video: lo script del player scrive sulla POST, staff e
// tutor leggono il rendiconto sulle GET.
$router->post('/lessons/{id}/fruizione', [VideoProgressController::class, 'store']);
$router->get('/lessons/{id}/fruizione', [VideoProgressController::class, 'report']);
$router->get('/lessons/{id}/fruizione/{formato}', [VideoProgressController::class, 'download']);
$router->get('/materials/{id}/download', [LessonController::class, 'downloadMaterial']);

// Pubblica di proposito: il carattere serve anche alla pagina di accesso,
// cioe' a chi l'accesso non l'ha ancora fatto. Un file di carattere non
// contiene dati di nessuno.
$router->get('/assets/fonts/catalogo/{file}', [FontController::class, 'serve']);

// --- Quiz -------------------------------------------------------------
$router->get('/modules/{moduleId}/quiz/create', [QuizController::class, 'createForm']);
$router->post('/modules/{moduleId}/quiz', [QuizController::class, 'store']);
$router->get('/quizzes/{id}/edit', [QuizController::class, 'editForm']);
$router->post('/quizzes/{id}', [QuizController::class, 'update']);
$router->post('/quizzes/{id}/delete', [QuizController::class, 'destroy']);
$router->post('/quizzes/{id}/questions', [QuizController::class, 'storeQuestion']);
$router->get('/questions/{id}/edit', [QuizController::class, 'editQuestionForm']);
$router->post('/questions/{id}', [QuizController::class, 'updateQuestion']);
$router->post('/questions/{id}/move', [QuizController::class, 'moveQuestion']);
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

// L'elenco completo di un taglio. Sta PRIMA delle rotte con {id} perche' il
// router prende la prima che combacia, e `/reports/courses` non deve finire
// in `/reports/courses/{id}` con un id vuoto.
$router->get('/reports/elenco/{sezione}', [ReportController::class, 'lista']);

/*
 * Ogni report si scarica in CSV e in XLSX: il formato e' l'ultimo segmento
 * dell'indirizzo. Un segmento diverso da «xlsx» vale CSV, cosi' i vecchi
 * collegamenti a «/csv» continuano a funzionare e un indirizzo inventato
 * non produce un file a sorpresa.
 */
$router->get('/reports/courses/{id}', [ReportController::class, 'course']);
$router->get('/reports/courses/{id}/{formato}', [ReportController::class, 'courseDownload']);
$router->get('/reports/students/{id}', [ReportController::class, 'student']);
$router->get('/reports/students/{id}/{formato}', [ReportController::class, 'studentDownload']);
$router->get('/reports/live/{id}', [ReportController::class, 'liveSession']);
$router->get('/reports/live/{id}/{formato}', [ReportController::class, 'liveSessionDownload']);
// Fruizione dei video, per corso. Le rotte specifiche prima di quelle con
// {id} non serve qui perche' il segmento fisso e' il primo, ma la coppia
// pagina/scarico segue lo stesso ordine delle altre.
$router->get('/reports/fruizione/{id}', [ReportController::class, 'videoCourse']);
$router->get('/reports/fruizione/{id}/{formato}', [ReportController::class, 'videoCourseDownload']);
$router->get('/reports/groups/{id}', [ReportController::class, 'group']);
$router->get('/reports/groups/{id}/{formato}', [ReportController::class, 'groupDownload']);

// --- Pannello di amministrazione --------------------------------------
$router->get('/admin/users', [AdminUserController::class, 'index']);
// Prima delle rotte con {id}: una rotta con segnaposto cattura qualunque
// segmento, e 'csv' verrebbe preso per un identificativo.
$router->get('/admin/users/csv', [AdminUserController::class, 'exportCsv']);
$router->get('/admin/users/xlsx', [AdminUserController::class, 'exportXlsx']);
// Anche queste prima di «{id}», per lo stesso motivo del CSV qui sopra.
$router->get('/admin/users/importa', [UserImportController::class, 'form']);
$router->post('/admin/users/importa/anteprima', [UserImportController::class, 'preview']);
$router->post('/admin/users/importa', [UserImportController::class, 'run']);
$router->post('/admin/users/inviti/manda', [AdminUserController::class, 'sendInvites']);
$router->get('/admin/users/create', [AdminUserController::class, 'createForm']);
$router->post('/admin/users', [AdminUserController::class, 'store']);
$router->get('/admin/users/{id}/edit', [AdminUserController::class, 'editForm']);
$router->post('/admin/users/{id}', [AdminUserController::class, 'update']);
$router->post('/admin/users/{id}/password', [AdminUserController::class, 'generateTemporaryPassword']);
$router->post('/admin/users/{id}/delete', [AdminUserController::class, 'destroy']);
$router->post('/admin/users/{id}/groups', [AdminUserController::class, 'addGroup']);
$router->post('/admin/users/{id}/groups/{groupId}/delete', [AdminUserController::class, 'removeGroup']);

$router->get('/admin/groups', [AdminGroupController::class, 'index']);
$router->get('/admin/groups/create', [AdminGroupController::class, 'createForm']);
$router->post('/admin/groups', [AdminGroupController::class, 'store']);
$router->get('/admin/groups/{id}/edit', [AdminGroupController::class, 'editForm']);
$router->post('/admin/groups/{id}', [AdminGroupController::class, 'update']);
$router->get('/gruppi/{id}/immagine', [AdminGroupController::class, 'logo']);
$router->post('/admin/groups/{id}/logo/elimina', [AdminGroupController::class, 'deleteLogo']);
$router->post('/admin/groups/{id}/delete', [AdminGroupController::class, 'destroy']);
$router->post('/admin/groups/{id}/members', [AdminGroupController::class, 'addMember']);
$router->post('/admin/groups/{id}/members/{userId}/delete', [AdminGroupController::class, 'removeMember']);
$router->post('/admin/groups/{id}/courses', [AdminGroupController::class, 'addCourse']);
$router->post('/admin/groups/{id}/courses/{courseId}/delete', [AdminGroupController::class, 'removeCourse']);

$router->get('/admin/courses', [AdminCourseController::class, 'index']);
$router->get('/admin/courses/create', [AdminCourseController::class, 'createForm']);
$router->post('/admin/courses', [AdminCourseController::class, 'store']);
$router->get('/admin/courses/{id}/edit', [AdminCourseController::class, 'editForm']);
// Prima della rotta generica qui sotto: {id} cattura qualunque segmento,
// quindi registrata dopo, "ordine" verrebbe presa per un identificativo.
$router->post('/admin/courses/ordine', [AdminCourseController::class, 'reorder']);
$router->post('/admin/courses/{id}', [AdminCourseController::class, 'update']);
$router->post('/admin/courses/{id}/delete', [AdminCourseController::class, 'destroy']);
$router->post('/admin/courses/{id}/move', [AdminCourseController::class, 'move']);
$router->post('/admin/courses/{id}/copertina', [AdminCourseController::class, 'updateCover']);
$router->post('/admin/courses/{id}/copertina/elimina', [AdminCourseController::class, 'deleteCover']);

// Il benvenuto del tutor all'inizio del corso (07/10): lo carica l'admin per
// ciascun tutor dei gruppi del corso; foto e audio li ricevono solo l'admin,
// il tutor e i suoi studenti in quel corso.
$router->post('/admin/courses/{id}/benvenuti/{tutorId}', [TutorWelcomeController::class, 'save']);
$router->post('/admin/courses/{id}/benvenuti/{tutorId}/elimina', [TutorWelcomeController::class, 'destroy']);
$router->get('/benvenuti/{id}/foto', [TutorWelcomeController::class, 'photo']);
$router->get('/benvenuti/{id}/audio', [TutorWelcomeController::class, 'audio']);
$router->post('/benvenuti/{id}/ascoltato', [TutorWelcomeController::class, 'listened']);

// Le domande degli studenti al tutor e l'archivio delle risposte (07/10).
$router->post('/courses/{id}/domande', [QuestionController::class, 'store']);
$router->get('/domande', [QuestionController::class, 'index']);
// L'archivio delle domande e risposte, per lo studente (09/10).
$router->get('/domande-e-risposte', [QuestionController::class, 'archive']);
$router->post('/domande/{id}/pubblica', [QuestionController::class, 'publish']);
$router->post('/domande/{id}/scarta', [QuestionController::class, 'discard']);
$router->post('/domande/{id}/modifica', [QuestionController::class, 'update']);
$router->post('/domande/{id}/togli', [QuestionController::class, 'withdraw']);

// --- Configurazione: posta elettronica e Google Meet ---
$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->get('/admin/settings/posta', [SettingsController::class, 'mail']);
$router->post('/admin/settings/posta', [SettingsController::class, 'updateMail']);
$router->post('/admin/settings/posta/prova', [SettingsController::class, 'sendTestMail']);
$router->get('/admin/settings/inviti', [SettingsController::class, 'liveMail']);
$router->post('/admin/settings/inviti', [SettingsController::class, 'updateLiveMail']);
$router->get('/admin/settings/aspetto', [SettingsController::class, 'appearance']);
$router->post('/admin/settings/aspetto', [SettingsController::class, 'updateAppearance']);
$router->get('/admin/settings/benvenuto', [SettingsController::class, 'welcome']);
$router->post('/admin/settings/benvenuto', [SettingsController::class, 'updateWelcome']);
$router->get('/admin/settings/bunny', [SettingsController::class, 'bunny']);
$router->post('/admin/settings/bunny', [SettingsController::class, 'updateBunny']);
$router->get('/admin/settings/meet', [SettingsController::class, 'meet']);
$router->post('/admin/settings/meet', [SettingsController::class, 'updateMeet']);
$router->post('/admin/settings/meet/chiave/elimina', [SettingsController::class, 'deleteMeetKey']);
$router->post('/admin/settings/meet/prova', [SettingsController::class, 'testMeet']);
$router->post('/admin/courses/{id}/enrollments', [AdminCourseController::class, 'enroll']);
$router->post('/admin/courses/{id}/enrollments/{userId}/delete', [AdminCourseController::class, 'unenroll']);
$router->post('/admin/requests/{requestId}', [AdminCourseController::class, 'decideRequest']);

$router->get('/admin/permissions', [AdminPermissionController::class, 'index']);
$router->post('/admin/permissions', [AdminPermissionController::class, 'update']);

// --- Agenda -----------------------------------------------------------
$router->get('/agenda', [AgendaController::class, 'index']);
$router->get('/agenda/evento/{id}.ics', [AgendaController::class, 'evento']);
$router->post('/agenda/calendario', [AgendaController::class, 'rigenera']);
$router->post('/agenda/calendario/dimentica', [AgendaController::class, 'dimentica']);

/*
 * Il calendario personale da sottoscrivere: **l'unico indirizzo interno che
 * risponde senza accesso**, perche' a rileggerlo e' un programma e non una
 * persona. Al posto dell'accesso c'e' il token, che e' una credenziale: 24
 * byte casuali, revocabile dal profilo dell'agenda.
 */
$router->get('/calendario/{token}.ics', [AgendaController::class, 'feed']);

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
$router->post('/live/{id}/inviti', [LiveSessionController::class, 'invite']);
$router->post('/live/{id}/attendance/{userId}', [LiveSessionController::class, 'setAttendance']);
