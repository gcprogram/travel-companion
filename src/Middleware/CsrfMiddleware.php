<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Checks the _csrf form field on every state-changing request
 * (POST/PUT/PATCH/DELETE).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $submitted = is_array($body) ? ($body['_csrf'] ?? null) : null;

            if (!$this->csrf->isValid($submitted)) {
                $response = new Response();
                $response->getBody()->write(t('errors.csrf_invalid'));
                return $response->withStatus(419)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
        }

        return $handler->handle($request);
    }
}
