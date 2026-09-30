<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;

final class CsrfMiddleware
{
    public function handle(array $params = []): void
    {
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!Csrf::verify(is_string($token) ? $token : null)) {
            http_response_code(419);
            exit('Sessão expirada ou token CSRF inválido. Atualize a página e tente novamente.');
        }
    }
}
