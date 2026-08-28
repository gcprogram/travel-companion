<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\GeocodeCacheRepository;
use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Service\ReverseGeocodingService;

/**
 * Job type "trip.metadata_refresh". Payload: {"trip_id": int}.
 * Fills trip.country/date_start/date_end from track points and geotagged
 * photos so the create form no longer has to ask for them. Dispatched after
 * every track upload, every geotagged photo, and every day-entry delete
 * (TrackController, PhotoProcessHandler, DayEntryController); cheap enough
 * (a couple of small queries, at most one Nominatim call which is itself
 * cached in geocode_cache) to fire on every one of those rather than trying
 * to detect "did this actually change anything" up front.
 *
 * date_start/date_end are fully recomputed from whatever's currently
 * observable (not clamped to the previous value) every run - a plain "only
 * fill from NULL" used to freeze a multi-day trip at whatever the first
 * incremental upload alone covered; a later "always expand, never shrink"
 * fix solved that but broke the opposite direction (Stefan's report: the
 * trip's displayed dates stayed stale after deleting the day-entry whose
 * photos were the ones providing that end of the range - cascade-deleted
 * photos mean collectPoints() genuinely has less to work with, and the
 * range should follow). A full recompute handles both correctly:
 * collectPoints() always re-reads the trip's *current* track+photos from
 * scratch, so growth (a later upload) and shrinkage (a deletion) both just
 * fall out of comparing today's full point set to nothing carried over from
 * before. No risk of clobbering a user's own correction - checked:
 * date_start/date_end aren't editable anywhere in the UI, this job is their
 * only writer. An empty point set (e.g. the last dated content just got
 * deleted) clears the range rather than leaving a stale one behind.
 * country still only fills once (a single value, not a range - nothing to
 * "recompute" there, and re-resolving it would cost another paid API call
 * for no benefit).
 */
final class TripMetadataAutoFillHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly TrackRepository $tracks,
        private readonly PhotoRepository $photos,
        private readonly DayEntryRepository $entries,
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
            if ($trip['date_start'] !== null || $trip['date_end'] !== null) {
                $this->trips->updateAutoMetadata($tripId, $trip['country'], null, null);
            }
            return;
        }
        usort($points, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $dateStart = substr($points[0]['at'], 0, 10);
        $dateEnd = substr($points[count($points) - 1]['at'], 0, 10);

        // The GPS track is trip-wide and keeps no notion of "which days
        // actually belong to the trip" - it can genuinely start recording
        // at home the evening before departure, or keep running on the
        // drive home, stretching date_start/date_end past what's actually
        // documented (Stefan's report: trip showed the 19th as day one
        // while every photo/diary entry started on the 20th). Diary
        // entries are the one thing a person deliberately created for a
        // specific day, so once any exist they're the trustworthier
        // signal - clip the track-derived range to theirs rather than
        // the other way round. A geotagged photo can't derive a date
        // outside this range anyway (it belongs to one of these entries),
        // so only the track's own contribution is ever actually clipped.
        $entryRange = $this->entries->dateRange($tripId);
        if ($entryRange !== null) {
            $clippedStart = max($dateStart, $entryRange['start']);
            $clippedEnd = min($dateEnd, $entryRange['end']);
            // Only apply the clip if it still leaves a valid (non-inverted)
            // range - the track/photos should always overlap the diary's
            // own days in practice, but a broken/inverted result here is
            // worse than not clipping at all.
            if ($clippedStart <= $clippedEnd) {
                $dateStart = $clippedStart;
                $dateEnd = $clippedEnd;
            }
        }

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
