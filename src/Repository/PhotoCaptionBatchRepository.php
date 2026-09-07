<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * One row per "generate captions for all photos" run (see migration
 * 0042) - lets the progress panel find "this trip's current batch" after a
 * page reload purely from the trip id, no client-side state needed.
 */
final class PhotoCaptionBatchRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $id, int $tripId, string $mode, int $total): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO photo_caption_batches (id, trip_id, mode, total, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $tripId, $mode, $total, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return array{id: string, tripId: int, mode: string, total: int}|null
     */
    public function findLatestByTrip(int $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM photo_caption_batches WHERE trip_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$tripId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'tripId' => (int) $row['trip_id'],
            'mode' => (string) $row['mode'],
            'total' => (int) $row['total'],
        ];
    }
}
