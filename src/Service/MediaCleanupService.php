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

    /**
     * A single entry may still leave a dedup'd photo/video (migration 0019)
     * referenced from elsewhere in the same still-alive trip - unlike
     * deleteForTrip/deleteForUser below, where everything sharing a
     * reference always disappears together (dedup never crosses trips), so
     * reference counting there would be a no-op.
     */
    public function deleteForEntry(int $entryId): void
    {
        foreach ($this->photos->findIdsByEntry($entryId) as $id) {
            $photo = $this->photos->findById($id);
            if ($photo === null) {
                continue;
            }
            $storageId = $photo['source_photo_id'] !== null ? (int) $photo['source_photo_id'] : $id;
            if ($this->photos->countReferencingStorage($storageId, $id) === 0) {
                $this->photoStorage->deleteAll($storageId);
            }
        }
        foreach ($this->videos->findIdsByEntry($entryId) as $id) {
            $video = $this->videos->findById($id);
            if ($video === null) {
                continue;
            }
            $storageId = $video['source_video_id'] !== null ? (int) $video['source_video_id'] : $id;
            if ($this->videos->countReferencingStorage($storageId, $id) === 0) {
                $this->videoStorage->deleteAll($storageId);
            }
        }
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
