<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\LessonController;
use App\Controllers\ModuleController;
use App\Controllers\PhaseController;
use App\Controllers\ProgressController;

/** @var \App\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);

$router->get('/login', [AuthController::class, 'showLogin'])
    ->middleware('guest');

$router->post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'csrf']);

$router->post('/logout', [AuthController::class, 'logout'])
    ->middleware(['auth', 'csrf']);

$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth');

$router->get('/curso/{id}', [CourseController::class, 'show'])
    ->middleware(['auth', 'student']);

$router->get('/modulo/{id}', [ModuleController::class, 'show'])
    ->middleware(['auth', 'student']);

$router->get('/fase/{id}', [PhaseController::class, 'show'])
    ->middleware(['auth', 'student', 'phase.unlocked']);

$router->get('/aula/{id}', [LessonController::class, 'show'])
    ->middleware(['auth', 'student', 'lesson.unlocked']);

$router->post('/aula/{id}/concluir', [ProgressController::class, 'completeLesson'])
    ->middleware(['auth', 'student', 'csrf', 'lesson.unlocked']);
