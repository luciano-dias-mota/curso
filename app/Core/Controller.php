<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(
        string $view,
        array $data = [],
        string $layout = 'layouts/app'
    ): void {
        View::render($view, $data, $layout);
    }

    protected function redirect(string $path): never
    {
        header('Location: ' . url($path));
        exit;
    }

    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
