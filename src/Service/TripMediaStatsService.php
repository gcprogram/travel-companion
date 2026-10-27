<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PhotoRepository;
use App\Repository\VideoRepository;

/**
 * Per-date upload statistics for the trip-level upload box
 * (_photos_fields.php): how many photos/videos landed on each diary date and
 * how much server storage they cost. Bytes are what actually sits on disk
 * (photos: thumbnail + web variant, the original is discarded after
 * processing), so the total matches the per-trip storage figure.
 */
final class TripMediaStatsService
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
    ) {
    }

    /**
     * @return array{rows: list<array{date: string, photos: int, videos: int, bytes: int}>, photos: int, videos: int, bytes: int, pending: int}
     */
    public function forTrip(int $tripId): array
    {
        $photoStats = $this->photos->statsByDateForTrip($tripId);
        $videoStats = $this->videos->statsByDateForTrip($tripId);

        $dates = array_unique(array_merge(array_keys($photoStats), array_keys($videoStats)));
        sort($dates);

        $rows = [];
        $total = ['photos' => 0, 'videos' => 0, 'bytes' => 0, 'pending' => 0];
        foreach ($dates as $date) {
            $p = $photoStats[$date] ?? ['count' => 0, 'bytes' => 0, 'pending' => 0];
            $v = $videoStats[$date] ?? ['count' => 0, 'bytes' => 0, 'pending' => 0];
            $rows[] = [
                'date' => (string) $date,
                'photos' => $p['count'],
                'videos' => $v['count'],
                'bytes' => $p['bytes'] + $v['bytes'],
            ];
            $total['photos'] += $p['count'];
            $total['videos'] += $v['count'];
            $total['bytes'] += $p['bytes'] + $v['bytes'];
            $total['pending'] += $p['pending'] + $v['pending'];
        }

        return ['rows' => $rows] + $total;
    }
}
