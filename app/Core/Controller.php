<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

abstract class Controller
{
    protected function view(
        string $view,
        array $data = [],
        string $layout = 'layouts/app'
    ): void {
        View::render($view, $data, $layout);
    }

    protected function redirect(string $path, ?int $status = null): never
    {
        if ($status === null) {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $status = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                ? 303
                : 302;
        }

        header('Location: ' . url($path), true, $status);
        exit;
    }

    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');

        try {
            echo json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            error_log('Falha ao serializar resposta JSON: ' . $e->getMessage());
            http_response_code(500);
            echo '{"ok":false,"message":"Não foi possível gerar a resposta do servidor."}';
        }

        exit;
    }
}
