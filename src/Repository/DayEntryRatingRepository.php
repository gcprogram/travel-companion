<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * One star rating (1-5) per logged-in user per diary entry (migration 0025)
 * - replaces the old single day_entries.rating value set by whoever could
 * edit the entry. Averaged for display; see DayEntryController::rate() for
 * who's allowed to submit one.
 */
final class DayEntryRatingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{average: ?float, count: int}
     */
    public function summaryForEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT AVG(rating) AS average, COUNT(*) AS count FROM day_entry_ratings WHERE day_entry_id = ?'
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch();
        $count = (int) $row['count'];
        return ['average' => $count > 0 ? (float) $row['average'] : null, 'count' => $count];
    }

    /**
     * @param list<int> $entryIds
     * @return array<int, array{average: ?float, count: int}> keyed by day_entry_id, only for entries with at least one rating
     */
    public function summaryForEntries(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT day_entry_id, AVG(rating) AS average, COUNT(*) AS count
             FROM day_entry_ratings WHERE day_entry_id IN ($placeholders)
             GROUP BY day_entry_id"
        );
        $stmt->execute($entryIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['day_entry_id']] = ['average' => (float) $row['average'], 'count' => (int) $row['count']];
        }
        return $result;
    }

    public function findForUser(int $entryId, int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT rating FROM day_entry_ratings WHERE day_entry_id = ? AND user_id = ?'
        );
        $stmt->execute([$entryId, $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function upsert(int $entryId, int $userId, int $rating): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO day_entry_ratings (day_entry_id, user_id, rating, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$entryId, $userId, $rating, $now, $now]);
    }

    public function delete(int $entryId, int $userId): void
    {
        $this->pdo->prepare('DELETE FROM day_entry_ratings WHERE day_entry_id = ? AND user_id = ?')
            ->execute([$entryId, $userId]);
    }
}
