<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Baseline hardening headers. The CSP has no 'unsafe-inline' because the
 * app has no inline <script>/<style>/on*= handlers left — see
 * confirm-submit.js for how the delete-confirmation prompts avoid inline JS.
 *
 * img-src and frame-src carry narrow youtube-nocookie.com/i.ytimg.com
 * allowances for the YouTube video option (embed player + its thumbnail).
 * media-src allows blob: because video-compress.js loads the file the user
 * picked into an off-screen <video> via URL.createObjectURL() to read
 * frames for compression — without it every video upload fails immediately
 * on selection, before compression even starts.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "img-src 'self' https://i.ytimg.com",
                "media-src 'self' blob:",
                "frame-src https://www.youtube-nocookie.com",
                "style-src 'self'",
                "script-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'none'",
                "object-src 'none'",
            ]))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin');
    }
}
