<?php

declare(strict_types=1);

use App\Controllers\ProfileController;

/** @var \App\Core\Router $router */

$router->post('/api/perfil/tema', [ProfileController::class, 'updateTheme'])
    ->middleware(['auth', 'csrf']);

// Próxima etapa:
// POST /api/progresso/bloco
// POST /api/quiz/iniciar
// POST /api/quiz/responder
// POST /api/quiz/finalizar
