<?php

declare(strict_types=1);

namespace App\Service;

/**
 * View/edit permissions for a trip (and anything hanging off it, e.g. diary
 * entries). Kept central so TripController and DayEntryController can't
 * drift apart.
 */
final class TripAccess
{
    /**
     * @param array<string, mixed> $trip
     * @param array<string, mixed>|null $user
     */
    public function canView(array $trip, ?array $user): bool
    {
        if ($trip['visibility'] === 'public') {
            return true;
        }
        return $this->canEdit($trip, $user);
    }

    /**
     * @param array<string, mixed> $trip
     * @param array<string, mixed>|null $user
     */
    public function canEdit(array $trip, ?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        return $user['role'] === 'admin' || (int) $user['id'] === (int) $trip['user_id'];
    }
}
