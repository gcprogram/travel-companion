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

    public function createUpload(int $entryId, string $originalFilename, string $extension): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO videos (day_entry_id, position, type, original_filename, extension, status, created_at, updated_at)
             VALUES (?, ?, \'upload\', ?, ?, \'pending\', ?, ?)'
        );
        $stmt->execute([
            $entryId,
            $this->nextPosition($entryId),
            mb_substr($originalFilename, 0, 255),
            $extension,
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
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

    private function nextPosition(int $entryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM videos WHERE day_entry_id = ?');
        $stmt->execute([$entryId]);
        return (int) $stmt->fetchColumn();
    }
}
