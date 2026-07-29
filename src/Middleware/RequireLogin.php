<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Blockt nicht angemeldete Zugriffe und leitet zum Login um.
 * Pro Route/Gruppe hinzufügen: ->add(RequireLogin::class)
 */
final class RequireLogin implements MiddlewareInterface
{
    public function __construct(private readonly Flash $flash)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('user') === null) {
            $this->flash->add('info', 'Bitte melde dich an.');
            return (new Response())
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }
        return $handler->handle($request);
    }
}
