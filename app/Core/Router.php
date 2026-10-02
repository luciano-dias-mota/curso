<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\LessonUnlockedMiddleware;
use App\Middleware\ModuleUnlockedMiddleware;
use App\Middleware\PhaseUnlockedMiddleware;
use App\Middleware\StudentMiddleware;
use ReflectionMethod;
use RuntimeException;

final class Router
{
    /** @var Route[] */
    private array $routes = [];

    private array $aliases = [
        'auth' => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'admin' => AdminMiddleware::class,
        'student' => StudentMiddleware::class,
        'csrf' => CsrfMiddleware::class,
        'phase.unlocked' => PhaseUnlockedMiddleware::class,
        'lesson.unlocked' => LessonUnlockedMiddleware::class,
        'module.unlocked' => ModuleUnlockedMiddleware::class,
    ];

    public function get(string $uri, array|callable $handler): Route
    {
        return $this->add('GET', $uri, $handler);
    }

    public function post(string $uri, array|callable $handler): Route
    {
        return $this->add('POST', $uri, $handler);
    }

    public function put(string $uri, array|callable $handler): Route
    {
        return $this->add('PUT', $uri, $handler);
    }

    public function delete(string $uri, array|callable $handler): Route
    {
        return $this->add('DELETE', $uri, $handler);
    }

    private function add(string $method, string $uri, array|callable $handler): Route
    {
        $route = new Route($method, $this->normalizeRoute($uri), $handler);
        $this->routes[] = $route;
        return $route;
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $uri = $this->requestPath();

        foreach ($this->routes as $route) {
            if ($route->method !== $method && !($method === 'PATCH' && $route->method === 'PUT')) {
                continue;
            }

            $params = $this->match($route->uri, $uri);

            if ($params === null) {
                continue;
            }

            foreach ($route->middlewares as $middleware) {
                $this->runMiddleware($middleware, $params);
            }

            $this->invoke($route->handler, $params);
            return;
        }

        http_response_code(404);
        View::render('errors/404', [], 'layouts/app');
    }

    private function runMiddleware(string $alias, array $params): void
    {
        $class = $this->aliases[$alias] ?? $alias;

        if (!class_exists($class)) {
            throw new RuntimeException("Middleware não encontrado: {$alias}");
        }

        $middleware = new $class();

        if (!method_exists($middleware, 'handle')) {
            throw new RuntimeException("Middleware sem método handle(): {$class}");
        }

        $middleware->handle($params);
    }

    private function invoke(array|callable $handler, array $params): void
    {
        if (is_callable($handler) && !is_array($handler)) {
            $handler(...array_values($params));
            return;
        }

        [$controllerClass, $method] = $handler;

        if (!class_exists($controllerClass)) {
            throw new RuntimeException("Controller não encontrado: {$controllerClass}");
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $method)) {
            throw new RuntimeException("Método {$method} não encontrado em {$controllerClass}");
        }

        $reflection = new ReflectionMethod($controller, $method);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();

            if ($parameter->getType()?->getName() === Request::class) {
                $arguments[] = new Request();
                continue;
            }

            if (array_key_exists($name, $params)) {
                $arguments[] = $params[$name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $arguments[] = null;
        }

        $reflection->invokeArgs($controller, $arguments);
    }

    private function match(string $routeUri, string $requestUri): ?array
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m) => '(?P<' . $m[1] . '>[^/]+)',
            $routeUri
        );

        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $requestUri, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = ctype_digit($value) ? (int) $value : $value;
            }
        }

        return $params;
    }

    private function normalizeRoute(string $uri): string
    {
        if ($uri === '' || $uri === '/') {
            return '/';
        }

        return '/' . trim($uri, '/');
    }

    private function requestPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        return $this->normalizeRoute(rawurldecode($path));
    }
}
