<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PhotoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM photos WHERE day_entry_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM photos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(int $entryId, string $originalFilename, string $extension, ?string $contentHash = null): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO photos (day_entry_id, position, original_filename, extension, status, content_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'pending\', ?, ?, ?)'
        );
        $stmt->execute([
            $entryId,
            $this->nextPosition($entryId),
            mb_substr($originalFilename, 0, 255),
            $extension,
            $contentHash,
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A photo whose bytes are already stored under $sourcePhotoId (dedup
     * match against another entry in the same trip) - no upload/processing
     * needed, ready immediately, and costs no additional storage quota
     * (bytes = 0; the source row already accounts for the real files).
     *
     * @param array<string, mixed> $canonical the matched photo's row
     */
    public function createReference(int $entryId, int $sourcePhotoId, array $canonical): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO photos (
                day_entry_id, position, original_filename, extension, status,
                width, height, lat, lng, taken_at, bytes, content_hash, source_photo_id,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, 'ready', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $entryId,
            $this->nextPosition($entryId),
            $canonical['original_filename'],
            $canonical['extension'],
            $canonical['width'],
            $canonical['height'],
            $canonical['lat'],
            $canonical['lng'],
            $canonical['taken_at'],
            $canonical['content_hash'],
            $sourcePhotoId,
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Dedup lookup, scoped to a single trip (see migration 0019 - never
     * across trips or users). Only matches fully processed photos; a
     * pending/failed match is ignored rather than raced against.
     *
     * @return array<string, mixed>|null
     */
    public function findReadyByTripAndHash(int $tripId, string $contentHash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.* FROM photos p JOIN day_entries e ON e.id = p.day_entry_id
             WHERE e.trip_id = ? AND p.content_hash = ? AND p.status = 'ready'
             LIMIT 1"
        );
        $stmt->execute([$tripId, $contentHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * How many photo rows still point at the photos/{$storageId}/ directory
     * (either $storageId's own row, or a reference to it), excluding
     * $excludeId - used to decide whether deleting a row may also delete its
     * files, without breaking sibling references. Safe to call whether
     * $excludeId's row has already been deleted or not.
     */
    public function countReferencingStorage(int $storageId, int $excludeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM photos WHERE (id = ? OR source_photo_id = ?) AND id != ?'
        );
        $stmt->execute([$storageId, $storageId, $excludeId]);
        return (int) $stmt->fetchColumn();
    }

    public function markReady(
        int $id,
        int $width,
        int $height,
        ?float $lat = null,
        ?float $lng = null,
        ?string $takenAt = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE photos SET status = 'ready', width = ?, height = ?, lat = ?, lng = ?, taken_at = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$width, $height, $lat, $lng, $takenAt, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function markFailed(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE photos SET status = 'failed', updated_at = ? WHERE id = ?");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * AI MediaAnalyzer's own analysis results (AiMediaXmpReader), imported
     * once at process time before the original is discarded. Only sets
     * caption_source when a caption was actually found, so an empty import
     * doesn't claim a source for a caption that isn't there.
     */
    public function updateAiMediaFields(int $id, ?string $address, ?string $persons, ?string $caption): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE photos SET ai_address = ?, ai_persons = ?, caption = ?, caption_source = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $address,
            $persons,
            $caption,
            $caption !== null ? 'exif_import' : null,
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function updateBytes(int $id, int $bytes): void
    {
        $stmt = $this->pdo->prepare('UPDATE photos SET bytes = ? WHERE id = ?');
        $stmt->execute([$bytes, $id]);
    }

    /**
     * @return list<int> ids of photos with no recorded byte size yet
     */
    public function findIdsWithoutBytes(): array
    {
        $rows = $this->pdo->query('SELECT id FROM photos WHERE bytes IS NULL')->fetchAll(PDO::FETCH_COLUMN);
        return array_map(intval(...), $rows);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
    }

    /**
     * @return list<int>
     */
    public function findIdsByEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM photos WHERE day_entry_id = ?');
        $stmt->execute([$entryId]);
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<int>
     */
    public function findIdsByTrip(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id FROM photos p JOIN day_entries e ON e.id = p.day_entry_id WHERE e.trip_id = ?'
        );
        $stmt->execute([$tripId]);
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Photos with both a position and a capture time - the two things
     * PhotoTrackGapFillService needs to place one on the track. A reference
     * (migration 0019) already has lat/lng/taken_at copied from its
     * canonical at creation, so it doesn't need special-casing here.
     *
     * taken_at (EXIF DateTimeOriginal) is frequently NULL - GPS and capture
     * time are extracted independently in PhotoProcessHandler, so a photo
     * can have a position without it. Falls back to created_at (upload
     * time), same as TripMapController::data()'s pin list - otherwise these
     * photos show as pins on the map but silently never count as filling a
     * gap.
     *
     * @return list<array{lat: float, lng: float, takenAt: string}>
     */
    public function findGeotaggedByTrip(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.lat, p.lng, COALESCE(p.taken_at, p.created_at) AS taken_at
             FROM photos p JOIN day_entries e ON e.id = p.day_entry_id
             WHERE e.trip_id = ? AND p.status = \'ready\' AND p.lat IS NOT NULL AND p.lng IS NOT NULL'
        );
        $stmt->execute([$tripId]);
        return array_map(static fn (array $r): array => [
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'takenAt' => (string) $r['taken_at'],
        ], $stmt->fetchAll());
    }

    /**
     * @return list<int>
     */
    public function findIdsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id FROM photos p
             JOIN day_entries e ON e.id = p.day_entry_id
             JOIN trips t ON t.id = e.trip_id
             WHERE t.user_id = ?'
        );
        $stmt->execute([$userId]);
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM photos p
             JOIN day_entries e ON e.id = p.day_entry_id
             JOIN trips t ON t.id = e.trip_id
             WHERE t.user_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function nextPosition(int $entryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM photos WHERE day_entry_id = ?');
        $stmt->execute([$entryId]);
        return (int) $stmt->fetchColumn();
    }
}
