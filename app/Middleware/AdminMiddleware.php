<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\View;

final class AdminMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isAdmin()) {
            http_response_code(403);
            View::render('errors/403', [], 'layouts/app');
            exit;
        }
    }
}
