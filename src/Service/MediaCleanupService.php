<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PhotoRepository;
use App\Repository\VideoRepository;

/**
 * Deletes on-disk photo/video files ahead of a DB-level delete (trip, day
 * entry, or whole user). The DB delete itself cascades via foreign keys
 * (see the migrations' ON DELETE CASCADE chain), but nothing in the schema
 * touches var/uploads - without this, every deletion above the single
 * photo/video level orphans files on disk. Call these BEFORE the
 * corresponding repository delete, since the id lookups need the rows to
 * still exist.
 */
final class MediaCleanupService
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly PhotoStorage $photoStorage,
        private readonly VideoStorage $videoStorage,
    ) {
    }

    public function deleteForEntry(int $entryId): void
    {
        $this->deleteIds($this->photos->findIdsByEntry($entryId), $this->videos->findIdsByEntry($entryId));
    }

    public function deleteForTrip(int $tripId): void
    {
        $this->deleteIds($this->photos->findIdsByTrip($tripId), $this->videos->findIdsByTrip($tripId));
    }

    public function deleteForUser(int $userId): void
    {
        $this->deleteIds($this->photos->findIdsByUser($userId), $this->videos->findIdsByUser($userId));
    }

    /**
     * @param list<int> $photoIds
     * @param list<int> $videoIds
     */
    private function deleteIds(array $photoIds, array $videoIds): void
    {
        foreach ($photoIds as $id) {
            $this->photoStorage->deleteAll($id);
        }
        foreach ($videoIds as $id) {
            $this->videoStorage->deleteAll($id);
        }
    }
}
