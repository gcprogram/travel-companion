<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\McpApiTokenRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Bearer-token auth for the /mcp endpoint only - route-specific (attached
 * via ->add() on the route itself), which in Slim's middleware stack means
 * it runs closer to the handler than the global AuthMiddleware, so it can
 * safely overwrite the "user" attribute AuthMiddleware already set from the
 * (nonexistent, for an API client) session cookie. Everything downstream
 * (McpToolService, TripAccess) then sees a normal user array and never
 * needs to know it came from a token instead of a login.
 */
final class McpAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly McpApiTokenRepository $tokens,
        private readonly UserRepository $users,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized();
        }
        $token = trim(substr($header, 7));
        if ($token === '') {
            return $this->unauthorized();
        }

        $row = $this->tokens->findActiveByTokenHash(hash('sha256', $token));
        if ($row === null) {
            return $this->unauthorized();
        }
        $user = $this->users->findById((int) $row['user_id']);
        if ($user === null || !(bool) $user['is_active']) {
            return $this->unauthorized();
        }

        $this->tokens->touchLastUsed((int) $row['id']);

        return $handler->handle($request->withAttribute('user', $user));
    }

    private function unauthorized(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write((string) json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => 'Invalid or missing bearer token.'],
            'id' => null,
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
