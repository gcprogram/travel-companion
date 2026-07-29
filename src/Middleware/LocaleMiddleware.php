<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the active UI locale for this request: an explicit "locale"
 * cookie (set via LocaleController) wins, otherwise the browser's
 * Accept-Language header is used, falling back to Translator's default.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Translator::setLocale($this->resolveLocale($request));

        return $handler->handle($request->withAttribute('locale', Translator::locale()));
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $cookie = $request->getCookieParams()['locale'] ?? null;
        if (is_string($cookie) && Translator::isSupported($cookie)) {
            return $cookie;
        }

        foreach ($this->parseAcceptLanguage($request->getHeaderLine('Accept-Language')) as $lang) {
            if (Translator::isSupported($lang)) {
                return $lang;
            }
        }

        return Translator::locale();
    }

    /**
     * Parses "de-DE,de;q=0.9,en;q=0.8" into ['de', 'en'], best quality first.
     *
     * @return list<string>
     */
    private function parseAcceptLanguage(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $entries = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            $quality = 1.0;
            if (preg_match('/;q=([0-9.]+)/', $part, $match)) {
                $quality = (float) $match[1];
                $part = trim(substr($part, 0, (int) strpos($part, ';')));
            }
            $lang = strtolower(substr($part, 0, 2));
            if ($lang !== '') {
                $entries[] = [$lang, $quality];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return array_values(array_unique(array_column($entries, 0)));
    }
}
