<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\McpApiTokenRepository;
use App\Support\Env;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Personal MCP API token management (/account/mcp-tokens) - lets any
 * logged-in user mint/revoke their own bearer tokens for the /mcp endpoint
 * (McpController), independent of admin settings. Only the token's SHA-256
 * hash is ever stored (McpApiTokenRepository), so create() renders the raw
 * token directly in its own response body rather than via redirect+flash -
 * it exists nowhere else after this one response, not even transiently in
 * $_SESSION.
 */
final class AccountMcpController
{
    public function __construct(
        private readonly View $view,
        private readonly McpApiTokenRepository $tokens,
        private readonly Flash $flash,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        return $this->view->render($response, 'account/mcp_tokens', [
            'tokens' => $this->tokens->findByUser((int) $user['id']),
            'newToken' => null,
            'mcpEndpoint' => rtrim((string) Env::get('APP_URL', ''), '/') . '/mcp',
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            $label = t('account.mcp_token_default_label');
        }

        $rawToken = bin2hex(random_bytes(32));
        $this->tokens->create((int) $user['id'], mb_substr($label, 0, 100), hash('sha256', $rawToken));

        return $this->view->render($response, 'account/mcp_tokens', [
            'tokens' => $this->tokens->findByUser((int) $user['id']),
            'newToken' => $rawToken,
            'mcpEndpoint' => rtrim((string) Env::get('APP_URL', ''), '/') . '/mcp',
        ]);
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $this->tokens->revoke((int) $args['id'], (int) $user['id']);
        $this->flash->add('success', t('account.mcp_token_revoked'));
        return $response->withHeader('Location', '/account/mcp-tokens')->withStatus(302);
    }
}
