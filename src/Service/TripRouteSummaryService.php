<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\GeocodeCacheRepository;
use App\Repository\JobRepository;
use App\Repository\StayDismissalRepository;
use App\Repository\TrackRepository;

/**
 * Track summary + detected-stays logic shared between TripMapController
 * (the standalone Route wizard step) and TripManageController (the
 * "Route" section on the unified edit page, Stage 3) - extracted out of
 * TripMapController so both render identical results from one
 * implementation instead of two copies drifting apart.
 */
final class TripRouteSummaryService
{
    /** A stay this close to an existing POI is treated as already recorded. */
    private const STAY_POI_MATCH_METERS = 150.0;

    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly StayDetectionService $stayDetection,
        private readonly GeocodeCacheRepository $geocodeCache,
        private readonly JobRepository $jobs,
        private readonly StayDismissalRepository $dismissals,
    ) {
    }

    /**
     * Just enough for the upload-tools/trim-slider form to render with the
     * right bounds — the full point list is only needed by the map JS,
     * fetched separately via /map/data.
     *
     * @return array{totalPoints: int, trimStart: int, trimEnd: int}|null
     */
    public function trackSummary(int $tripId): ?array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return null;
        }

        $totalPoints = $this->tracks->countPoints((int) $track['id']);
        return [
            'totalPoints' => $totalPoints,
            'trimStart' => $track['trim_start_seq'] !== null ? (int) $track['trim_start_seq'] : 0,
            'trimEnd' => $track['trim_end_seq'] !== null ? (int) $track['trim_end_seq'] : max(0, $totalPoints - 1),
        ];
    }

    /**
     * Places the traveller stopped at long enough to count as a visit,
     * derived from the raw track (see StayDetectionService). Stays that
     * already have a POI nearby are dropped, so a stay disappears from the
     * suggestion list once it's been added - and discovered sights the user
     * genuinely stopped at don't get offered a second time.
     *
     * Each remaining stay gets a best-effort 'locationName' straight from
     * the geocode_cache grid - never a live Nominatim call from within this
     * request (see GeocodeCacheRepository/GeocodeResolveHandler): a cache
     * miss just dispatches a job and the name shows up on the next page
     * load. Recomputed on every request since stays themselves aren't
     * persisted, so this must never turn into a per-stay external call.
     *
     * @param list<array<string, mixed>> $pois
     * @return list<array<string, mixed>>
     */
    public function detectStays(int $tripId, array $pois): array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return [];
        }

        $points = array_map(static fn (array $p): array => [
            'seq' => (int) $p['seq'],
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'recordedAt' => $p['recorded_at'],
        ], $this->tracks->findPoints((int) $track['id']));

        $stays = $this->stayDetection->detect($points);
        $dismissed = $this->dismissals->dismissedSet($tripId);

        $unmatched = array_values(array_filter(
            $stays,
            fn (array $stay): bool => !$this->hasPoiNear($pois, $stay['lat'], $stay['lng'])
                && !$this->isDismissed($dismissed, $stay['lat'], $stay['lng']),
        ));

        return array_map(function (array $stay) use ($tripId): array {
            $cached = $this->geocodeCache->find($tripId, $stay['lat'], $stay['lng']);
            $stay['locationName'] = $cached['name'];
            $stay['locationResolved'] = $cached['found'];
            if (!$cached['found']) {
                $this->jobs->dispatch('geocode.resolve', ['trip_id' => $tripId, 'lat' => $stay['lat'], 'lng' => $stay['lng']]);
            }
            return $stay;
        }, $unmatched);
    }

    /**
     * @param list<array<string, mixed>> $pois
     */
    private function hasPoiNear(array $pois, float $lat, float $lng): bool
    {
        foreach ($pois as $poi) {
            $dLat = deg2rad((float) $poi['lat'] - $lat);
            $dLng = deg2rad((float) $poi['lng'] - $lng);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float) $poi['lat'])) * sin($dLng / 2) ** 2;
            if (6371000.0 * 2 * atan2(sqrt($a), sqrt(1 - $a)) <= self::STAY_POI_MATCH_METERS) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, true> $dismissed
     */
    private function isDismissed(array $dismissed, float $lat, float $lng): bool
    {
        return isset($dismissed[StayDismissalRepository::key($lat, $lng)]);
    }
}
