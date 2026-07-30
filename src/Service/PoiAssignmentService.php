<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
use App\Repository\PoiMediaRepository;
use App\Repository\PoiRepository;
use App\Repository\VideoRepository;

/**
 * Assigns geotagged photos/videos to the nearest confirmed POI (within
 * ~150m), so the sightseeing list can show what was actually photographed
 * there. Only touches media that isn't assigned to *any* POI yet —
 * re-running this (e.g. after new POIs are discovered, or a new photo is
 * uploaded) never reshuffles an existing assignment, auto or manual.
 */
final class PoiAssignmentService
{
    private const MAX_DISTANCE_METERS = 150.0;

    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly PoiRepository $pois,
        private readonly PoiMediaRepository $poiMedia,
    ) {
    }

    /**
     * @return int number of newly created assignments
     */
    public function assignForTrip(int $tripId): int
    {
        $pois = $this->pois->findByTrip($tripId);
        if ($pois === []) {
            return 0;
        }

        $count = 0;
        foreach ($this->entries->findByTrip($tripId) as $entry) {
            foreach ($this->photos->findByEntry((int) $entry['id']) as $photo) {
                if ($photo['status'] !== 'ready' || $photo['lat'] === null || $photo['lng'] === null) {
                    continue;
                }
                if ($this->poiMedia->isPhotoAssigned((int) $photo['id'])) {
                    continue;
                }
                $poiId = $this->findNearestPoi($pois, (float) $photo['lat'], (float) $photo['lng']);
                if ($poiId !== null) {
                    $this->poiMedia->assignPhoto($poiId, (int) $photo['id']);
                    $count++;
                }
            }

            foreach ($this->videos->findByEntry((int) $entry['id']) as $video) {
                if ($video['status'] !== 'ready' || $video['lat'] === null || $video['lng'] === null) {
                    continue;
                }
                if ($this->poiMedia->isVideoAssigned((int) $video['id'])) {
                    continue;
                }
                $poiId = $this->findNearestPoi($pois, (float) $video['lat'], (float) $video['lng']);
                if ($poiId !== null) {
                    $this->poiMedia->assignVideo($poiId, (int) $video['id']);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $pois
     */
    private function findNearestPoi(array $pois, float $lat, float $lng): ?int
    {
        $bestId = null;
        $bestDistance = self::MAX_DISTANCE_METERS;

        foreach ($pois as $poi) {
            $distance = $this->haversineMeters($lat, $lng, (float) $poi['lat'], (float) $poi['lng']);
            if ($distance <= $bestDistance) {
                $bestDistance = $distance;
                $bestId = (int) $poi['id'];
            }
        }

        return $bestId;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
