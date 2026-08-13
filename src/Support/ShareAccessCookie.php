<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The "share_access" cookie: a JSON map of trip_id => share token, so one
 * browser can hold access to several shared trips at once without one
 * redemption clobbering another. Read side (TripAccess) and write side
 * (ShareController) both go through here, so the format only lives in one
 * place. Values are only ever token strings looked up against
 * trip_share_tokens - the cookie itself grants nothing by itself, it just
 * says which token to check for which trip; a revoked token stops working
 * immediately even if it's still sitting in someone's cookie.
 */
final class ShareAccessCookie
{
    private const COOKIE_NAME = 'share_access';
    private const MAX_AGE = 60 * 60 * 24 * 180;

    /**
     * @return array<int, string> trip_id => token
     */
    public static function read(ServerRequestInterface $request): array
    {
        $raw = $request->getCookieParams()[self::COOKIE_NAME] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $tripId => $token) {
            if (is_numeric($tripId) && is_string($token) && $token !== '') {
                $map[(int) $tripId] = $token;
            }
        }
        return $map;
    }

    public static function tokenFor(ServerRequestInterface $request, int $tripId): ?string
    {
        return self::read($request)[$tripId] ?? null;
    }

    /**
     * @param array<int, string> $map trip_id => token, already merged with
     *        whatever the visitor's browser previously held
     */
    public static function header(array $map): string
    {
        $value = urlencode((string) json_encode($map, JSON_THROW_ON_ERROR));
        return sprintf('%s=%s; Path=/; Max-Age=%d; SameSite=Lax; HttpOnly', self::COOKIE_NAME, $value, self::MAX_AGE);
    }
}
