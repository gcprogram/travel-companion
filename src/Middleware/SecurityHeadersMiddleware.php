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
 * allowances for the YouTube video option (embed player + its thumbnail),
 * plus api.maptiler.com for the trip map's raster tiles (not
 * tile.openstreetmap.org directly — its usage policy forbids the traffic
 * pattern a real app produces and got this project IP-blocked during
 * testing; MapTiler serves the same OSM cartography under a plan meant
 * for production use).
 * media-src allows blob: because video-compress.js loads the file the user
 * picked into an off-screen <video> via URL.createObjectURL() to read
 * frames for compression — without it every video upload fails immediately
 * on selection, before compression even starts.
 * worker-src/manifest-src are listed explicitly (even though they'd fall
 * back to default-src/script-src 'self' anyway) rather than trusting that
 * fallback chain blind — the media-src gap above already burned us once
 * this project from assuming a directive wasn't needed without checking.
 * Referrer-Policy is strict-origin-when-cross-origin rather than
 * same-origin: some third-party services (potentially including tile
 * providers) authorize by checking the Referer header, and same-origin
 * suppresses it entirely for cross-origin requests. This still only leaks
 * the origin (not the full path/query) cross-origin, so it's not a
 * meaningful privacy regression.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "img-src 'self' https://i.ytimg.com https://api.maptiler.com",
                "media-src 'self' blob:",
                "frame-src https://www.youtube-nocookie.com",
                "style-src 'self'",
                "script-src 'self'",
                "worker-src 'self'",
                "manifest-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'none'",
                "object-src 'none'",
            ]))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
