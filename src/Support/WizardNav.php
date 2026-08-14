<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The trip-creation wizard (map -> photos -> pois -> trip) is plain
 * page-to-page navigation with a `?wizard=1` query flag, not any kind of
 * server-side state - the flag just has to survive every sub-action's own
 * redirect back to the page it came from (GPX upload, POI add, ...) so the
 * Skip/Save buttons stay visible after using one of those. This is the one
 * place that round-trip is implemented, reused by every controller whose
 * redirect can happen mid-wizard (TrackController, PoiController).
 */
final class WizardNav
{
    public static function isActive(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()['wizard'] ?? null) === '1';
    }

    public static function preserve(string $location, ServerRequestInterface $request): string
    {
        if (!self::isActive($request)) {
            return $location;
        }
        return $location . (str_contains($location, '?') ? '&' : '?') . 'wizard=1';
    }
}
