<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    public array $middlewares = [];

    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly mixed $handler
    ) {
    }

    public function middleware(string|array $middlewares): self
    {
        $middlewares = is_array($middlewares) ? $middlewares : [$middlewares];
        $this->middlewares = array_values(array_unique(array_merge($this->middlewares, $middlewares)));

        return $this;
    }
}
