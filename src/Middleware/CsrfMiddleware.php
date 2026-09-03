<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csrf;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Checks the _csrf form field on every state-changing request
 * (POST/PUT/PATCH/DELETE). A stale token (session/tab left open, form
 * timed out) used to dead-end on a raw 419 text response with no way back
 * in - now a proper page that auto-redirects home after a few seconds
 * (see templates/errors/bounce.php), same treatment as a normal logout.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const BOUNCE_DELAY_SECONDS = 4;

    public function __construct(
        private readonly Csrf $csrf,
        private readonly View $view,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // The MCP endpoint is a bearer-token-authenticated JSON API (see
        // McpAuthMiddleware), not a browser form - it never carries the
        // session cookie CSRF actually protects, and its clients can't
        // submit a _csrf field anyway.
        if ($request->getUri()->getPath() === '/mcp') {
            return $handler->handle($request);
        }

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $submitted = is_array($body) ? ($body['_csrf'] ?? null) : null;

            if (!$this->csrf->isValid($submitted)) {
                return $this->view->render(new Response(), 'errors/bounce', [
                    'message' => t('errors.csrf_invalid'),
                    'redirectTo' => '/',
                    'headExtra' => '<meta http-equiv="refresh" content="' . self::BOUNCE_DELAY_SECONDS . ';url=/">',
                ], status: 419);
            }
        }

        return $handler->handle($request);
    }
}
