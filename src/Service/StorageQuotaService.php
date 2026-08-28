<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use PDO;

/**
 * Storage quota accounting. Usage is the SUM of photos.bytes/videos.bytes
 * across all trips OWNED by a user - the trip owner pays, regardless of who
 * uploaded (an admin adding a photo to someone's trip charges that owner).
 * Pure SQL over the bytes columns; never touches the filesystem.
 */
final class StorageQuotaService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly UserRole $roles,
    ) {
    }

    public function usedBytes(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                COALESCE((SELECT SUM(p.bytes) FROM photos p
                    JOIN day_entries e ON e.id = p.day_entry_id
                    JOIN trips t ON t.id = e.trip_id
                    WHERE t.user_id = ?), 0)
              + COALESCE((SELECT SUM(v.bytes) FROM videos v
                    JOIN day_entries e ON e.id = v.day_entry_id
                    JOIN trips t ON t.id = e.trip_id
                    WHERE t.user_id = ?), 0)'
        );
        $stmt->execute([$userId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Storage footprint of a single trip (Stefan's ask: a per-trip display
     * for the owner, next to the account-wide total this class already
     * tracks) - same photos.bytes/videos.bytes sum as usedBytes(), just
     * scoped to one trip's day_entries instead of all of an owner's trips.
     */
    public function tripBytes(int $tripId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                COALESCE((SELECT SUM(p.bytes) FROM photos p
                    JOIN day_entries e ON e.id = p.day_entry_id
                    WHERE e.trip_id = ?), 0)
              + COALESCE((SELECT SUM(v.bytes) FROM videos v
                    JOIN day_entries e ON e.id = v.day_entry_id
                    WHERE e.trip_id = ?), 0)'
        );
        $stmt->execute([$tripId, $tripId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether adding $addedBytes to the owner's usage would break their
     * quota. Unlimited (admin / no quota) never exceeds.
     */
    public function wouldExceed(int $ownerId, int $addedBytes): bool
    {
        $owner = $this->users->findById($ownerId);
        if ($owner === null) {
            return false;
        }
        $quota = $this->roles->storageQuotaBytes($owner);
        if ($quota === null) {
            return false;
        }
        return $this->usedBytes($ownerId) + $addedBytes > $quota;
    }
}
