<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Gates admin-only routes. Non-admins (including anonymous visitors) get a
 * 404 rather than a 403/redirect - the admin area shouldn't reveal its own
 * existence, matching how private trips are handled.
 * Add per route/group: ->add(RequireAdmin::class)
 */
final class RequireAdmin implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null || $user['role'] !== 'admin') {
            throw new HttpNotFoundException($request);
        }
        return $handler->handle($request);
    }
}
