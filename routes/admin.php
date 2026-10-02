<?php

declare(strict_types=1);

use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\LessonContentController;
use App\Controllers\Admin\MediaController;

/** @var \App\Core\Router $router */

$router->get('/admin', [DashboardController::class, 'index'])
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

// Editor de aulas e posicionamento dos vídeos.
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
