<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class VideoRepository
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
            'SELECT * FROM videos WHERE day_entry_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM videos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function createUpload(int $entryId, string $originalFilename, string $extension, ?string $contentHash = null): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO videos (day_entry_id, position, type, original_filename, extension, status, content_hash, created_at, updated_at)
             VALUES (?, ?, \'upload\', ?, ?, \'pending\', ?, ?, ?)'
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
     * A video whose bytes are already stored under $sourceVideoId (dedup
     * match against another entry in the same trip) - no upload/processing
     * needed, ready immediately, costs no additional storage quota.
     *
     * @param array<string, mixed> $canonical the matched video's row
     */
    public function createReference(int $entryId, int $sourceVideoId, array $canonical): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO videos (
                day_entry_id, position, type, original_filename, extension, status,
                width, height, duration_seconds, lat, lng, bytes, content_hash, source_video_id,
                created_at, updated_at
            ) VALUES (?, ?, 'upload', ?, ?, 'ready', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $entryId,
            $this->nextPosition($entryId),
            $canonical['original_filename'],
            $canonical['extension'],
            $canonical['width'],
            $canonical['height'],
            $canonical['duration_seconds'],
            $canonical['lat'],
            $canonical['lng'],
            $canonical['content_hash'],
            $sourceVideoId,
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Dedup lookup, scoped to a single trip (see migration 0019 - never
     * across trips or users). Only matches fully processed uploads (never
     * YouTube links, which have no file/hash at all).
     *
     * @return array<string, mixed>|null
     */
    public function findReadyByTripAndHash(int $tripId, string $contentHash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT v.* FROM videos v JOIN day_entries e ON e.id = v.day_entry_id
             WHERE e.trip_id = ? AND v.content_hash = ? AND v.status = 'ready' AND v.type = 'upload'
             LIMIT 1"
        );
        $stmt->execute([$tripId, $contentHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * See PhotoRepository::countReferencingStorage() - same reasoning.
     */
    public function countReferencingStorage(int $storageId, int $excludeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM videos WHERE (id = ? OR source_video_id = ?) AND id != ?'
        );
        $stmt->execute([$storageId, $storageId, $excludeId]);
        return (int) $stmt->fetchColumn();
    }

    public function createYoutube(int $entryId, string $youtubeId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO videos (day_entry_id, position, type, youtube_id, status, created_at, updated_at)
             VALUES (?, ?, \'youtube\', ?, \'ready\', ?, ?)'
        );
        $stmt->execute([$entryId, $this->nextPosition($entryId), $youtubeId, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markReady(
        int $id,
        ?int $width,
        ?int $height,
        ?int $durationSeconds,
        ?float $lat = null,
        ?float $lng = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE videos SET status = 'ready', width = ?, height = ?, duration_seconds = ?, lat = ?, lng = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$width, $height, $durationSeconds, $lat, $lng, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function markFailed(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE videos SET status = 'failed', updated_at = ? WHERE id = ?");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * See PhotoRepository::updateAiMediaFields() - same source/reasoning,
     * plus transcript (speech-to-text, video-only).
     */
    public function updateAiMediaFields(int $id, ?string $address, ?string $persons, ?string $caption, ?string $transcript): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE videos SET ai_address = ?, ai_persons = ?, caption = ?, caption_source = ?, transcript = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $address,
            $persons,
            $caption,
            $caption !== null ? 'exif_import' : null,
            $transcript,
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * See PhotoRepository::updateVisionCaption() - same reasoning.
     */
    public function updateVisionCaption(int $id, string $caption): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE videos SET caption = ?, caption_source = 'vision_ai', updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$caption, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function updateBytes(int $id, int $bytes): void
    {
        $stmt = $this->pdo->prepare('UPDATE videos SET bytes = ? WHERE id = ?');
        $stmt->execute([$bytes, $id]);
    }

    /**
     * @return list<int> ids of uploaded videos with no recorded byte size yet
     */
    public function findIdsWithoutBytes(): array
    {
        $rows = $this->pdo->query("SELECT id FROM videos WHERE bytes IS NULL AND type = 'upload'")->fetchAll(PDO::FETCH_COLUMN);
        return array_map(intval(...), $rows);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    }

    /**
     * Only 'upload' videos have a file on disk (YouTube links don't), so
     * cleanup callers only need these ids.
     *
     * @return list<int>
     */
    public function findIdsByEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM videos WHERE day_entry_id = ? AND type = 'upload'");
        $stmt->execute([$entryId]);
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<int>
     */
    public function findIdsByTrip(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT v.id FROM videos v JOIN day_entries e ON e.id = v.day_entry_id
             WHERE e.trip_id = ? AND v.type = 'upload'"
        );
        $stmt->execute([$tripId]);
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<int>
     */
    public function findIdsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT v.id FROM videos v
             JOIN day_entries e ON e.id = v.day_entry_id
             JOIN trips t ON t.id = e.trip_id
             WHERE t.user_id = ? AND v.type = 'upload'"
        );
        $stmt->execute([$userId]);
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Counts every video regardless of type (upload or YouTube) - unlike
     * findIdsByUser, which only cares about files that exist on disk.
     */
    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM videos v
             JOIN day_entries e ON e.id = v.day_entry_id
             JOIN trips t ON t.id = e.trip_id
             WHERE t.user_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function nextPosition(int $entryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM videos WHERE day_entry_id = ?');
        $stmt->execute([$entryId]);
        return (int) $stmt->fetchColumn();
    }
}
