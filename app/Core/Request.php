<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function all(): array
    {
        if ($this->isJson()) {
            $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);
            return is_array($decoded) ? $decoded : [];
        }

        return array_merge($_GET, $_POST);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->all();
        return $data[$key] ?? $default;
    }

    public function only(array $keys): array
    {
        $data = $this->all();
        return array_intersect_key($data, array_flip($keys));
    }

    public function isJson(): bool
    {
        return str_contains(
            strtolower($_SERVER['CONTENT_TYPE'] ?? ''),
            'application/json'
        );
    }

    public function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }
}
