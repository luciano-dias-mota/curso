<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\DashboardController;
use App\Controllers\ExerciseController;
use App\Controllers\HomeController;
use App\Controllers\LessonController;
use App\Controllers\MediaController;
use App\Controllers\ModuleController;
use App\Controllers\PhaseController;
use App\Controllers\ProgressController;
use App\Controllers\QuizController;
use App\Controllers\SimulationController;
use App\Controllers\StudyNoteController;

/** @var \App\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);

$router->get('/login', [AuthController::class, 'showLogin'])
    ->middleware('guest');

$router->post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'csrf']);

$router->post('/logout', [AuthController::class, 'logout'])
    ->middleware(['auth', 'csrf']);

$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth']);


// Exercícios de fixação: questões médias, feedback imediato e seleção anti-repetição.
$router->get('/exercicios', [ExerciseController::class, 'index'])
    ->middleware(['auth', 'student']);

$router->post('/exercicios/gerar', [ExerciseController::class, 'generate'])
    ->middleware(['auth', 'student', 'csrf']);

$router->get('/exercicios/sessao/{id}', [ExerciseController::class, 'session'])
    ->middleware(['auth', 'student']);

$router->post('/exercicios/sessao/{id}/resposta', [ExerciseController::class, 'answer'])
    ->middleware(['auth', 'student', 'csrf']);

$router->get('/exercicios/sessao/{id}/resultado', [ExerciseController::class, 'result'])
    ->middleware(['auth', 'student']);

// Caderno de anotações do estudante.
$router->get('/anotacoes', [StudyNoteController::class, 'index'])
    ->middleware(['auth', 'student']);

$router->post('/anotacoes', [StudyNoteController::class, 'store'])
    ->middleware(['auth', 'student', 'csrf']);

$router->get('/anotacoes/{id}/editar', [StudyNoteController::class, 'edit'])
    ->middleware(['auth', 'student']);

$router->put('/anotacoes/{id}', [StudyNoteController::class, 'update'])
    ->middleware(['auth', 'student', 'csrf']);

$router->delete('/anotacoes/{id}', [StudyNoteController::class, 'destroy'])
    ->middleware(['auth', 'student', 'csrf']);

$router->post('/anotacoes/{id}/fixar', [StudyNoteController::class, 'togglePin'])
    ->middleware(['auth', 'student', 'csrf']);

// Simulados livres: independentes da progressão pedagógica.
$router->get('/simulados', [SimulationController::class, 'index'])
    ->middleware(['auth', 'student']);

$router->get('/simulados/novo', [SimulationController::class, 'create'])
    ->middleware(['auth', 'student']);

$router->post('/simulados/gerar', [SimulationController::class, 'generate'])
    ->middleware(['auth', 'student', 'csrf']);

$router->get('/simulados/tentativa/{id}', [SimulationController::class, 'attempt'])
    ->middleware(['auth', 'student']);

$router->post('/simulados/tentativa/{id}/resposta', [SimulationController::class, 'saveAnswer'])
    ->middleware(['auth', 'student', 'csrf']);

$router->post('/simulados/tentativa/{id}/finalizar', [SimulationController::class, 'submit'])
    ->middleware(['auth', 'student', 'csrf']);

$router->get('/simulados/tentativa/{id}/resultado', [SimulationController::class, 'result'])
    ->middleware(['auth', 'student']);

$router->get('/simulados/tentativa/{id}/revisao', [SimulationController::class, 'review'])
    ->middleware(['auth', 'student']);

$router->post('/simulados/tentativa/{id}/abandonar', [SimulationController::class, 'abandon'])
    ->middleware(['auth', 'student', 'csrf']);

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
