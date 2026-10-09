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
            . "connect-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'"
        );

        $appUrl = strtolower((string) config('app.url', ''));
        if (str_starts_with($appUrl, 'https://')) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
