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

    public function markReady(int $id, int $width, int $height, ?float $lat = null, ?float $lng = null): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE photos SET status = 'ready', width = ?, height = ?, lat = ?, lng = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$width, $height, $lat, $lng, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function markFailed(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE photos SET status = 'failed', updated_at = ? WHERE id = ?");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
    }

    private function nextPosition(int $entryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM photos WHERE day_entry_id = ?');
        $stmt->execute([$entryId]);
        return (int) $stmt->fetchColumn();
    }
}
