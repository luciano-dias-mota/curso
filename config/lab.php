<?php

declare(strict_types=1);

return [
    /*
     * Motores concretos são registrados por classe.
     * A fundação multi-curso não conhece PostgreSQL, JavaScript, PHP etc.
     * Cada provider deve implementar App\Lab\LabEngineInterface.
     */
    'engines' => [],

    // Limites defensivos para payloads enviados pelos laboratórios.
    'max_submission_bytes' => 262144,
    'max_result_bytes' => 262144,
];
