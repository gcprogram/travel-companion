<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Tracks which detected-stay locations (see StayDetectionService) the user
 * explicitly rejected in the "Besuchte Orte prüfen" reviewer, so they don't
 * keep reappearing as candidates on every subsequent track update/page
 * load - stays themselves are never persisted, only this dismissal record
 * is. ~11m grid (4 decimals), tighter than geocode_cache's ~111m: a stay's
 * centre is already an averaged, fairly stable point (see
 * StayDetectionService), so a coarser grid risked accidentally also
 * hiding a genuinely different nearby stay.
 */
final class StayDismissalRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isDismissed(int $tripId, float $lat, float $lng): bool
    {
        [$latRounded, $lngRounded] = $this->round($lat, $lng);
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM trip_stay_dismissals WHERE trip_id = ? AND lat_rounded = ? AND lng_rounded = ? LIMIT 1'
        );
        $stmt->execute([$tripId, $latRounded, $lngRounded]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array<string, true> keyed by self::key() for O(1) lookups against many stays at once
     */
    public function dismissedSet(int $tripId): array
    {
        $stmt = $this->pdo->prepare('SELECT lat_rounded, lng_rounded FROM trip_stay_dismissals WHERE trip_id = ?');
        $stmt->execute([$tripId]);
        $set = [];
        foreach ($stmt->fetchAll() as $row) {
            // DECIMAL(9,4) columns come back from PDO as fixed-4-decimal
            // strings ("50.8600") - cast through float first so the key
            // matches self::key()'s own round()+sprintf output exactly
            // (a naive string concat of the raw DB value never matched a
            // freshly-rounded PHP float, e.g. "50.8600" vs "50.86").
            $set[self::key((float) $row['lat_rounded'], (float) $row['lng_rounded'])] = true;
        }
        return $set;
    }

    /**
     * Shared key format for dismissedSet()'s map and
     * TripRouteSummaryService's lookup against it - must stay in sync.
     */
    public static function key(float $lat, float $lng): string
    {
        return sprintf('%.4f:%.4f', $lat, $lng);
    }

    public function dismiss(int $tripId, float $lat, float $lng): void
    {
        [$latRounded, $lngRounded] = $this->round($lat, $lng);
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO trip_stay_dismissals (trip_id, lat_rounded, lng_rounded, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$tripId, $latRounded, $lngRounded, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function round(float $lat, float $lng): array
    {
        return [round($lat, 4), round($lng, 4)];
    }
}
