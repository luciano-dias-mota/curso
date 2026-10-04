<?php

declare(strict_types=1);

use App\Controllers\Admin\AdminOverviewController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\LessonContentController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Admin\QuestionController;
use App\Controllers\Admin\StudentController;

/** @var \App\Core\Router $router */

$router->get('/admin', [DashboardController::class, 'index'])
    ->middleware(['auth', 'admin']);

// Gestão de alunos.
$router->get('/admin/usuarios', [StudentController::class, 'index'])
    ->middleware(['auth', 'admin']);
$router->get('/admin/usuarios/novo', [StudentController::class, 'create'])
    ->middleware(['auth', 'admin']);
$router->post('/admin/usuarios', [StudentController::class, 'store'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->get('/admin/usuarios/{id}', [StudentController::class, 'show'])
    ->middleware(['auth', 'admin']);
$router->post('/admin/usuarios/{id}/atualizar', [StudentController::class, 'update'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->post('/admin/usuarios/{id}/status', [StudentController::class, 'setStatus'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->post('/admin/usuarios/{id}/senha', [StudentController::class, 'resetPassword'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->post('/admin/usuarios/{id}/matricula', [StudentController::class, 'updateEnrollment'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->delete('/admin/usuarios/{id}', [StudentController::class, 'destroy'])
    ->middleware(['auth', 'admin', 'csrf']);

// Conteúdo geral.
$router->get('/admin/conteudo', [AdminOverviewController::class, 'content'])
    ->middleware(['auth', 'admin']);

// Banco de questões — consulta, criação e edição.
$router->get('/admin/questoes', [QuestionController::class, 'index'])
    ->middleware(['auth', 'admin']);
$router->get('/admin/questoes/nova', [QuestionController::class, 'create'])
    ->middleware(['auth', 'admin']);
$router->post('/admin/questoes', [QuestionController::class, 'store'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->get('/admin/questoes/{id}/editar', [QuestionController::class, 'edit'])
    ->middleware(['auth', 'admin']);
$router->put('/admin/questoes/{id}', [QuestionController::class, 'update'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->post('/admin/questoes/{id}/status', [QuestionController::class, 'setStatus'])
    ->middleware(['auth', 'admin', 'csrf']);

// Visões operacionais.
$router->get('/admin/simulados', [AdminOverviewController::class, 'simulations'])
    ->middleware(['auth', 'admin']);
$router->get('/admin/relatorios', [AdminOverviewController::class, 'reports'])
    ->middleware(['auth', 'admin']);
$router->get('/admin/relatorios/exportar', [AdminOverviewController::class, 'exportReports'])
    ->middleware(['auth', 'admin']);

// Biblioteca de vídeos.
$router->get('/admin/videos', [MediaController::class, 'index'])
    ->middleware(['auth', 'admin']);
$router->post('/admin/videos', [MediaController::class, 'store'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->put('/admin/videos/{id}', [MediaController::class, 'update'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->delete('/admin/videos/{id}', [MediaController::class, 'destroy'])
    ->middleware(['auth', 'admin', 'csrf']);

// Editor de aulas / blocos de vídeo.
$router->get('/admin/aulas', [LessonContentController::class, 'index'])
    ->middleware(['auth', 'admin']);
$router->get('/admin/aulas/{id}', [LessonContentController::class, 'edit'])
    ->middleware(['auth', 'admin']);
$router->post('/admin/aulas/{id}/videos', [LessonContentController::class, 'addVideo'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->put('/admin/aulas/{id}/blocos/{blockId}/video', [LessonContentController::class, 'updateVideo'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->delete('/admin/aulas/{id}/blocos/{blockId}/video', [LessonContentController::class, 'destroyVideo'])
    ->middleware(['auth', 'admin', 'csrf']);
$router->post('/admin/aulas/{id}/reordenar', [LessonContentController::class, 'reorder'])
    ->middleware(['auth', 'admin', 'csrf']);
