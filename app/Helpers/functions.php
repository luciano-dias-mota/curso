<?php

declare(strict_types=1);

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => trim($value, "\"'")
        };
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        static $cache = [];

        [$file, $item] = array_pad(explode('.', $key, 2), 2, null);

        if (!isset($cache[$file])) {
            $path = base_path("config/{$file}.php");
            if (!is_file($path)) {
                return $default;
            }
            $cache[$file] = require $path;
        }

        if ($item === null) {
            return $cache[$file];
        }

        $value = $cache[$file];
        foreach (explode('.', $item) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
