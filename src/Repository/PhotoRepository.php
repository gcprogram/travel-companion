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

    public function create(int $entryId, string $originalFilename, string $extension): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO photos (day_entry_id, position, original_filename, extension, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'pending\', ?, ?)'
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
