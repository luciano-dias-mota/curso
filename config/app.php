<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'PMMT Academy'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost/curso/public'),
    'timezone' => env('APP_TIMEZONE', 'America/Cuiaba'),
    'session_name' => env('SESSION_NAME', 'curso_session'),
];
