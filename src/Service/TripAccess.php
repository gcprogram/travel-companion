<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ShareTokenRepository;
use App\Support\ShareAccessCookie;
use Psr\Http\Message\ServerRequestInterface;

/**
 * View/edit permissions for a trip (and anything hanging off it, e.g. diary
 * entries). Kept central so TripController and DayEntryController can't
 * drift apart.
 *
 * $request is optional and only used to check for a share-token grant (see
 * ShareAccessCookie/ShareTokenRepository) - a call site that omits it simply
 * never grants token-based access, which is the safe default. It's not
 * threaded into trip-metadata edit/delete (TripController::edit/update/
 * delete) on purpose: an "edit" share token is meant for collaborating on
 * content (map, POIs, diary entries, media), not for renaming, deleting, or
 * changing the visibility of someone else's trip.
 */
final class TripAccess
{
    public function __construct(private readonly ShareTokenRepository $shareTokens)
    {
    }

    /**
     * @param array<string, mixed> $trip
     * @param array<string, mixed>|null $user
     */
    public function canView(array $trip, ?array $user, ?ServerRequestInterface $request = null): bool
    {
        if ($trip['visibility'] === 'public') {
            return true;
        }
        if ($this->canEdit($trip, $user, $request)) {
            return true;
        }
        return $this->hasShareAccess($trip, $request, 'view');
    }

    /**
     * @param array<string, mixed> $trip
     * @param array<string, mixed>|null $user
     */
    public function canEdit(array $trip, ?array $user, ?ServerRequestInterface $request = null): bool
    {
        if ($user !== null && ($user['role'] === 'admin' || (int) $user['id'] === (int) $trip['user_id'])) {
            return true;
        }
        return $this->hasShareAccess($trip, $request, 'edit');
    }

    /**
     * Gate for RequireLogin: is there at least one usable share token in
     * this request at all (any trip, any permission)? RequireLogin can't
     * know which trip a route is about (different routes key it under
     * different param names/types - id, slug, entryId, photo's own id...),
     * so it only needs to know whether to let the request through at all;
     * the specific trip and view-vs-edit permission are still enforced
     * exactly as before by canView()/canEdit() further downstream, once the
     * controller has actually loaded the right trip row.
     */
    public function hasAnyShareToken(ServerRequestInterface $request): bool
    {
        foreach (ShareAccessCookie::read($request) as $token) {
            if ($this->shareTokens->findByToken($token) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $trip
     */
    private function hasShareAccess(array $trip, ?ServerRequestInterface $request, string $minPermission): bool
    {
        if ($request === null) {
            return false;
        }
        $token = ShareAccessCookie::tokenFor($request, (int) $trip['id']);
        if ($token === null) {
            return false;
        }
        $row = $this->shareTokens->findByToken($token);
        if ($row === null || (int) $row['trip_id'] !== (int) $trip['id']) {
            return false;
        }
        // 'edit' satisfies a 'view' requirement too; 'view' never satisfies 'edit'.
        return $minPermission === 'view' || $row['permission'] === 'edit';
    }
}
