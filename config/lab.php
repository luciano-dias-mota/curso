<?php

declare(strict_types=1);

return [
    /*
     * Motores concretos são registrados por classe.
     * A fundação multi-curso não conhece PostgreSQL, JavaScript, PHP etc.
     * Cada provider deve implementar App\Lab\LabEngineInterface.
     */
    'engines' => [
        App\Lab\Engines\PgliteEngine::class,
    ],

    'pglite' => [
        // Pinned para evitar mudanças silenciosas no runtime.
        // Pode apontar para um arquivo self-hosted no futuro.
        'module_url' => env(
            'PGLITE_MODULE_URL',
            'https://cdn.jsdelivr.net/npm/@electric-sql/pglite@0.5.10/dist/index.js'
        ),
    ],

    // Limites defensivos para payloads enviados pelos laboratórios.
    'max_submission_bytes' => 262144,
    'max_result_bytes' => 262144,
];
