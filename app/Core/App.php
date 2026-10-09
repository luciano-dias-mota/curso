<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\SecurityHeadersMiddleware;
use Dotenv\Dotenv;
use Throwable;

final class App
{
    public static function boot(string $basePath): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', rtrim($basePath, '/\\'));
        }

        $dotenv = Dotenv::createImmutable(BASE_PATH);
        $dotenv->safeLoad();

        date_default_timezone_set((string) config('app.timezone', 'America/Cuiaba'));

        (new SecurityHeadersMiddleware())->handle();
        Session::start();
    }

    public static function run(): void
    {
        try {
            $router = new Router();

            require base_path('routes/web.php');
            require base_path('routes/admin.php');
            require base_path('routes/api.php');

            $router->dispatch();
        } catch (Throwable $e) {
            self::logThrowable($e);
            self::clearOutputBuffers();

            http_response_code(500);

            if ((bool) config('app.debug', false)) {
                echo '<pre style="white-space:pre-wrap">';
                echo e($e::class . ': ' . $e->getMessage());
                echo "\n\n" . e($e->getTraceAsString());
                echo '</pre>';
                return;
            }

            try {
                View::render('errors/500', [], 'layouts/app');
            } catch (Throwable $fallbackError) {
                self::logThrowable($fallbackError);
                self::clearOutputBuffers();

                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Erro interno do servidor.';
            }
        }
    }

    private static function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    private static function logThrowable(Throwable $e): void
    {
        error_log(sprintf(
            '[%s] %s: %s in %s:%d',
            date('c'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
