<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;

final class GuestMiddleware
{
    public function handle(array $params = []): void
    {
        if (Auth::check()) {
            header('Location: ' . url('/dashboard'));
            exit;
        }
    }
}
