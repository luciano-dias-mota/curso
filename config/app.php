<?php

declare(strict_types=1);

$environment = strtolower((string) env('APP_ENV', 'production'));
$appUrl = trim((string) env('APP_URL', ''));

if ($appUrl === '') {
    if ($environment === 'production') {
        throw new RuntimeException(
            'APP_URL é obrigatório em produção. Defina o endereço público da aplicação no arquivo .env.'
        );
    }

    $appUrl = 'http://localhost/curso/public';
}

return [
    'name' => env('APP_NAME', 'PMMT Academy'),
    'env' => $environment,
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim($appUrl, '/'),
    'timezone' => env('APP_TIMEZONE', 'America/Cuiaba'),
    'session_name' => env('SESSION_NAME', 'curso_session'),
    // Em produção HTTPS, defina SESSION_SECURE=true explicitamente.
    'session_secure' => env('SESSION_SECURE', null),
];
