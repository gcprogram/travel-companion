<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;

/**
 * For each POI in a trip, finds the single closest recorded point - a track
 * point or a geotagged photo - and reports its distance and timestamp. This
 * is "when/where did we actually pass closest to this place", shown in the
 * sightseeing list so a POI that was only ever seen from 400m away reads
 * differently from one that was walked right past.
 *
 * Unlike PoiAssignmentService there's no radius cutoff - every POI gets
 * whatever its nearest point is, however far, since this is informational
 * display rather than a "was this visited" decision.
 */
final class PoiApproachService
{
    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly PhotoRepository $photos,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $pois
     * @return array<int, array{distanceMeters: float, closestAt: ?string}>
     */
    public function computeForTrip(int $tripId, array $pois): array
    {
        if ($pois === []) {
            return [];
        }

        $points = [];
        $track = $this->tracks->findByTrip($tripId);
        if ($track !== null) {
            foreach ($this->tracks->findPoints((int) $track['id']) as $p) {
                $points[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'at' => $p['recorded_at']];
            }
        }
        foreach ($this->photos->findGeotaggedByTrip($tripId) as $p) {
            $points[] = ['lat' => $p['lat'], 'lng' => $p['lng'], 'at' => $p['takenAt']];
        }
        if ($points === []) {
            return [];
        }

        $result = [];
        foreach ($pois as $poi) {
            $bestDistance = null;
            $bestAt = null;
            foreach ($points as $point) {
                $distance = $this->haversineMeters(
                    (float) $poi['lat'],
                    (float) $poi['lng'],
                    $point['lat'],
                    $point['lng'],
                );
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestAt = $point['at'];
                }
            }
            $result[(int) $poi['id']] = ['distanceMeters' => $bestDistance, 'closestAt' => $bestAt];
        }
        return $result;
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
