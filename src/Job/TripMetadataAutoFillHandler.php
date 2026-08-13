<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\GeocodeCacheRepository;
use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Service\ReverseGeocodingService;

/**
 * Job type "trip.metadata_refresh". Payload: {"trip_id": int}.
 * Fills trip.country/date_start/date_end from track points and geotagged
 * photos once either becomes available, so the create form no longer has to
 * ask for them - never overwrites a value that's already set (manually
 * typed, or filled by an earlier run of this same job), same "never resets
 * anything manual" rule as EntryLocateHandler. Dispatched after every track
 * upload and every geotagged photo (TrackController, PhotoProcessHandler);
 * cheap enough (a couple of small queries, at most one Nominatim call which
 * is itself cached in geocode_cache) to fire on every one of those rather
 * than trying to detect "did this actually change anything" up front.
 */
final class TripMetadataAutoFillHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly TrackRepository $tracks,
        private readonly PhotoRepository $photos,
        private readonly GeocodeCacheRepository $geocodeCache,
        private readonly ReverseGeocodingService $geocoding,
    ) {
    }

    public function handle(array $payload): void
    {
        $tripId = (int) ($payload['trip_id'] ?? 0);
        $trip = $this->trips->findById($tripId);
        if ($trip === null) {
            return;
        }
        if ($trip['country'] !== null && $trip['date_start'] !== null && $trip['date_end'] !== null) {
            return; // Nothing left for this job to fill in.
        }

        $points = $this->collectPoints($tripId);
        if ($points === []) {
            return;
        }
        usort($points, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $dateStart = $trip['date_start'] ?? substr($points[0]['at'], 0, 10);
        $dateEnd = $trip['date_end'] ?? substr($points[count($points) - 1]['at'], 0, 10);

        $country = $trip['country'] ?? $this->resolveCountry($points[0]['lat'], $points[0]['lng']);

        $this->trips->updateAutoMetadata($tripId, $country, $dateStart, $dateEnd);
    }

    /**
     * @return list<array{lat: float, lng: float, at: string}>
     */
    private function collectPoints(int $tripId): array
    {
        $points = [];

        $track = $this->tracks->findByTrip($tripId);
        if ($track !== null) {
            foreach ($this->tracks->findPoints((int) $track['id']) as $p) {
                if ($p['recorded_at'] === null) {
                    continue;
                }
                $points[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'at' => (string) $p['recorded_at']];
            }
        }

        foreach ($this->photos->findGeotaggedByTrip($tripId) as $p) {
            $points[] = ['lat' => $p['lat'], 'lng' => $p['lng'], 'at' => $p['takenAt']];
        }

        return $points;
    }

    private function resolveCountry(float $lat, float $lng): ?string
    {
        $cached = $this->geocodeCache->find($lat, $lng);
        if ($cached['found']) {
            return $cached['country'];
        }

        try {
            $result = $this->geocoding->reverseGeocode($lat, $lng);
        } catch (\Throwable) {
            return null; // Leave uncached - worth a retry on the next photo/track update.
        }

        $this->geocodeCache->store($lat, $lng, $result['name'], $result['country']);
        return $result['country'];
    }
}
