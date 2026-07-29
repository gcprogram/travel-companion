<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Switches the UI language: sets a long-lived "locale" cookie and redirects
 * back to where the visitor came from (same origin only).
 */
final class LocaleController
{
    private const COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

    public function set(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $locale = (string) ($args['locale'] ?? '');
        if (!Translator::isSupported($locale)) {
            $locale = Translator::locale();
        }

        return $response
            ->withHeader('Location', $this->redirectTarget($request))
            ->withHeader('Set-Cookie', sprintf(
                'locale=%s; Path=/; Max-Age=%d; SameSite=Lax',
                $locale,
                self::COOKIE_MAX_AGE,
            ))
            ->withStatus(302);
    }

    private function redirectTarget(ServerRequestInterface $request): string
    {
        $referer = $request->getHeaderLine('Referer');
        $ownOrigin = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();

        if ($referer !== '' && str_starts_with($referer, $ownOrigin . '/')) {
            $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
            $query = parse_url($referer, PHP_URL_QUERY);
            return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
        }

        return '/';
    }
}
