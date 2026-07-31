<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Client IP resolution, shared by login and registration rate limiting.
 * Bitpalast sits behind an nginx reverse proxy, so REMOTE_ADDR is the
 * proxy's own address; the real client IP arrives via X-Forwarded-For.
 */
final class ClientIp
{
    public static function from(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return $first;
            }
        }
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
