<?php

declare(strict_types=1);

use App\Controllers\LabController;
use App\Controllers\ProfileController;

/** @var \App\Core\Router $router */

$router->post('/api/perfil/tema', [ProfileController::class, 'updateTheme'])
    ->middleware(['auth', 'csrf']);

$router->post('/api/curso/{courseId}/laboratorio/atividade/{id}/executar', [LabController::class, 'submit'])
    ->middleware(['auth', 'student', 'csrf']);

$router->post('/api/curso/{courseId}/laboratorio/atividade/{id}/dica', [LabController::class, 'hint'])
    ->middleware(['auth', 'student', 'csrf']);

// Compatibilidade temporária com URLs antigas.
$router->post('/api/laboratorio/atividade/{id}/executar', [LabController::class, 'submit'])
    ->middleware(['auth', 'student', 'csrf']);

$router->post('/api/laboratorio/atividade/{id}/dica', [LabController::class, 'hint'])
    ->middleware(['auth', 'student', 'csrf']);

// Próxima etapa:
// POST /api/progresso/bloco
// POST /api/quiz/iniciar
// POST /api/quiz/responder
// POST /api/quiz/finalizar
