<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\AuthService;

use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;


/**
 * Provides the current user as the "user" request attribute (or null),
 * and makes them globally available to templates.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly View $view,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->auth->currentUser();
        $this->view->share('currentUser', $user);

        return $handler->handle($request->withAttribute('user', $user));
    }
}

