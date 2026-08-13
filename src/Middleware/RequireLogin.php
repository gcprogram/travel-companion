<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\TripAccess;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Blocks unauthenticated access and redirects to login - unless the request
 * carries a share token (see TripAccess::hasAnyShareToken), in which case it
 * passes through here and the actual trip/permission check happens further
 * downstream once the controller knows which trip the route is about (a
 * view-only token holder reaching, say, a track upload route still gets
 * rejected there by canEdit(), just later than here).
 * Add per route/group: ->add(RequireLogin::class)
 */
final class RequireLogin implements MiddlewareInterface
{
    public function __construct(
        private readonly Flash $flash,
        private readonly TripAccess $access,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('user') === null && !$this->access->hasAnyShareToken($request)) {
            $this->flash->add('info', t('flash.please_login'));
            return (new Response())
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }
        return $handler->handle($request);
    }
}
