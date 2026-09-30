<?php

declare(strict_types=1);

use App\Controllers\Admin\DashboardController;

/** @var \App\Core\Router $router */

$router->get('/admin', [DashboardController::class, 'index'])
    ->middleware(['auth', 'admin']);

// Próxima etapa:
// /admin/usuarios
// /admin/cursos
// /admin/modulos
// /admin/fases
// /admin/aulas
// /admin/questoes
// /admin/simulados
// /admin/relatorios
