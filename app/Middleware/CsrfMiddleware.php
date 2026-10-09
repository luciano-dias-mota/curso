<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use JsonException;

final class CsrfMiddleware
{
    public function handle(array $params = []): void
    {
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (Csrf::verify(is_string($token) ? $token : null)) {
            return;
        }

        http_response_code(419);

        $request = new Request();
        if ($request->expectsJson()) {
            header('Content-Type: application/json; charset=UTF-8');

            try {
                echo json_encode(
                    [
                        'ok' => false,
                        'message' => 'Sessão expirada ou token CSRF inválido. Atualize a página e tente novamente.',
                    ],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                echo '{"ok":false,"message":"Sessão expirada."}';
            }

            exit;
        }

        exit('Sessão expirada ou token CSRF inválido. Atualize a página e tente novamente.');
    }
}
