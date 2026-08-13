<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\JobRepository;
use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;
use App\Repository\VideoRepository;
use App\Service\ReverseGeocodingService;
use Psr\Log\LoggerInterface;

/**
 * Job type "entry.locate". Payload: {"day_entry_id": int}.
 * Auto-fills a diary entry's free-text location_name (see
 * templates/day_entries/form.php) AND its numeric lat/lng - neither ever
 * overwrites a value the user already set (typed a name, or used "Standort
 * erfassen" for coordinates). Dispatched whenever new location data becomes
 * available for the entry's trip: a geotagged photo/video finishes
 * processing, or a GPS track gets uploaded. Coordinates are picked in this
 * priority, matching how confidently they represent "where this entry
 * actually happened":
 * 1. A geotagged photo attached to the entry
 * 2. A geotagged video attached to the entry
 * 3. The trip's GPS track, at the point closest in time to the entry's date
 * A missing/failed geocode is a cosmetic gap, not a job failure - swallowed
 * and logged rather than retried.
 *
 * Setting lat/lng here (not just at handler entry, but as a real,
 * independently-useful side effect) is also what lets weather.fetch run
 * without the user ever touching "Standort erfassen" - previously that
 * button was the *only* way an entry got coordinates at all, so a trip
 * built entirely from photos/track never got weather.
 */
final class EntryLocateHandler implements JobHandlerInterface
{
    private const MAX_TRACK_DATE_DRIFT_SECONDS = 2 * 86400;

    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly TrackRepository $tracks,
        private readonly ReverseGeocodingService $geocoding,
        private readonly JobRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $entryId = (int) ($payload['day_entry_id'] ?? 0);
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            return; // Gone.
        }

        $needsName = empty($entry['location_name']);
        $needsCoords = $entry['lat'] === null || $entry['lng'] === null;
        if (!$needsName && !$needsCoords) {
            return; // Already has both (user-set or an earlier run) - nothing to do.
        }

        $coords = $this->findCoordinates($entry);
        if ($coords === null) {
            return;
        }

        if ($needsCoords) {
            $this->entries->updateCoordinatesIfEmpty($entryId, $coords['lat'], $coords['lng']);
            $this->jobs->dispatch('weather.fetch', ['day_entry_id' => $entryId]);
        }

        if ($needsName) {
            try {
                $name = $this->geocoding->reverseGeocode($coords['lat'], $coords['lng'])['name'];
                if ($name !== null) {
                    $this->entries->updateLocationNameIfEmpty($entryId, $name);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Entry location lookup failed (non-fatal)', [
                    'day_entry_id' => $entryId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{lat: float, lng: float}|null
     */
    private function findCoordinates(array $entry): ?array
    {
        foreach ($this->photos->findByEntry((int) $entry['id']) as $photo) {
            if ($photo['lat'] !== null && $photo['lng'] !== null) {
                return ['lat' => (float) $photo['lat'], 'lng' => (float) $photo['lng']];
            }
        }
        foreach ($this->videos->findByEntry((int) $entry['id']) as $video) {
            if ($video['lat'] !== null && $video['lng'] !== null) {
                return ['lat' => (float) $video['lat'], 'lng' => (float) $video['lng']];
            }
        }
        return $this->nearestTrackPoint((int) $entry['trip_id'], (string) $entry['entry_date']);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function nearestTrackPoint(int $tripId, string $entryDate): ?array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return null;
        }

        $target = strtotime($entryDate . ' 12:00:00');
        $best = null;
        $bestDiff = null;
        foreach ($this->tracks->findPoints((int) $track['id']) as $point) {
            if ($point['recorded_at'] === null) {
                continue;
            }
            $diff = abs(strtotime((string) $point['recorded_at']) - $target);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $point;
            }
        }

        // A track point several days off the entry's date almost certainly
        // isn't from that day - e.g. a trip with a single track covering
        // only part of it, and this entry falls outside that range.
        if ($best === null || $bestDiff > self::MAX_TRACK_DATE_DRIFT_SECONDS) {
            return null;
        }

        return ['lat' => (float) $best['lat'], 'lng' => (float) $best['lng']];
    }
}
