<?php

declare(strict_types=1);

namespace App\Middleware;

final class SecurityHeadersMiddleware
{
    public function handle(array $params = []): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $isLabPage = str_contains($requestPath, '/laboratorio/');

        $connectSrc = $isLabPage
            ? "connect-src 'self' https://cdn.jsdelivr.net"
            : "connect-src 'self'";

        $scriptSrc = $isLabPage
            ? "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://cdn.jsdelivr.net"
            : "script-src 'self' 'unsafe-inline'";

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "base-uri 'self'; "
            . "object-src 'none'; "
            . "frame-ancestors 'self'; "
            . "form-action 'self'; "
            . "img-src 'self' data:; "
            . "media-src 'self'; "
            . "font-src 'self' data:; "
            . $connectSrc . "; "
            . $scriptSrc . "; "
            . "style-src 'self' 'unsafe-inline'"
        );

        $appUrl = strtolower((string) config('app.url', ''));
        if (str_starts_with($appUrl, 'https://')) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
