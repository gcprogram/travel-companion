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
 * photos so the create form no longer has to ask for them. Dispatched after
 * every track upload and every geotagged photo (TrackController,
 * PhotoProcessHandler); cheap enough (a couple of small queries, at most one
 * Nominatim call which is itself cached in geocode_cache) to fire on every
 * one of those rather than trying to detect "did this actually change
 * anything" up front.
 *
 * date_start/date_end EXPAND to cover newly observed points rather than
 * only filling from NULL - a multi-day trip whose photos/track get uploaded
 * incrementally would otherwise freeze at whatever the first upload alone
 * covered (e.g. day one only) and never widen once days two and three
 * arrive, since every later run of this same job used to see the fields
 * already non-null and stop touching them entirely. There's no risk of
 * clobbering a user's own correction here - checked: date_start/date_end
 * aren't editable anywhere in the UI, this job is their only writer, and
 * expanding never narrows a range that's already correct. country still
 * only fills once (a single value, not a range - once resolved there's
 * nothing to "expand").
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

        $points = $this->collectPoints($tripId);
        if ($points === []) {
            return;
        }
        usort($points, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $observedStart = substr($points[0]['at'], 0, 10);
        $observedEnd = substr($points[count($points) - 1]['at'], 0, 10);

        $dateStart = $trip['date_start'] !== null ? min($trip['date_start'], $observedStart) : $observedStart;
        $dateEnd = $trip['date_end'] !== null ? max($trip['date_end'], $observedEnd) : $observedEnd;

        $country = $trip['country'] ?? $this->resolveCountry($tripId, $points[0]['lat'], $points[0]['lng']);

        if ($country === $trip['country'] && $dateStart === $trip['date_start'] && $dateEnd === $trip['date_end']) {
            return; // Nothing actually changed - skip the write.
        }

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

    private function resolveCountry(int $tripId, float $lat, float $lng): ?string
    {
        $cached = $this->geocodeCache->find($tripId, $lat, $lng);
        if ($cached['found']) {
            return $cached['country'];
        }

        try {
            $result = $this->geocoding->reverseGeocode($lat, $lng);
        } catch (\Throwable) {
            return null; // Leave uncached - worth a retry on the next photo/track update.
        }

        $this->geocodeCache->store($tripId, $lat, $lng, $result['name'], $result['country']);
        return $result['country'];
    }
}
