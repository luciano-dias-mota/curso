<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\LessonController;
use App\Controllers\MediaController;
use App\Controllers\ModuleController;
use App\Controllers\PhaseController;
use App\Controllers\ProgressController;
use App\Controllers\QuizController;

/** @var \App\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);

$router->get('/login', [AuthController::class, 'showLogin'])
    ->middleware('guest');

$router->post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'csrf']);

$router->post('/logout', [AuthController::class, 'logout'])
    ->middleware(['auth', 'csrf']);

$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'student']);

$router->get('/curso/{id}', [CourseController::class, 'show'])
    ->middleware(['auth', 'student']);

$router->get('/modulo/{id}', [ModuleController::class, 'show'])
    ->middleware(['auth', 'student', 'module.unlocked']);

$router->get('/fase/{id}', [PhaseController::class, 'show'])
    ->middleware(['auth', 'student', 'phase.unlocked']);

$router->get('/aula/{id}', [LessonController::class, 'show'])
    ->middleware(['auth', 'student', 'lesson.unlocked']);

$router->post('/aula/{id}/concluir', [ProgressController::class, 'completeLesson'])
    ->middleware(['auth', 'student', 'csrf', 'lesson.unlocked']);

// Streaming protegido dos vídeos da biblioteca.
// Administradores podem visualizar qualquer vídeo.
// Estudantes precisam ter acesso a uma aula que utilize o vídeo.
$router->get('/media/video/{id}', [MediaController::class, 'video'])
    ->middleware('auth');

// Checkpoint obrigatório de cada fase.
$router->get('/prova/{id}', [QuizController::class, 'show'])
    ->middleware(['auth', 'student']);

$router->post('/prova/{id}/iniciar', [QuizController::class, 'start'])
    ->middleware(['auth', 'student', 'csrf']);

$router->get('/prova/tentativa/{id}', [QuizController::class, 'attempt'])
    ->middleware(['auth', 'student']);

$router->post('/prova/tentativa/{id}/finalizar', [QuizController::class, 'submit'])
    ->middleware(['auth', 'student', 'csrf']);

$router->get('/prova/tentativa/{id}/resultado', [QuizController::class, 'result'])
    ->middleware(['auth', 'student']);
