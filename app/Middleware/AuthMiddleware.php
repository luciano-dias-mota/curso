<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Session;

final class AuthMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::check()) {
            Session::flash('error', 'Faça login para continuar.');
            header('Location: ' . url('/login'));
            exit;
        }
    }
}
