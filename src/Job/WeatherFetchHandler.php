<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\DayEntryWeatherHourRepository;
use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;
use App\Service\ReverseGeocodingService;
use App\Service\WeatherService;
use Psr\Log\LoggerInterface;

/**
 * Job type "weather.fetch". Payload: {"day_entry_id": int}.
 * Enqueued when a diary entry with a location is saved.
 *
 * Fetches both the existing compact daily summary (day_entries.weather_*)
 * and a new hour-by-hour breakdown (day_entry_weather_hours) - unlike the
 * daily summary, each hour uses whichever location the traveller was
 * actually nearest to at that time (track point or geotagged photo closest
 * in time), not the entry's one fixed lat/lng for the whole day. A day
 * spent driving 200km has meaningfully different weather at 8:00 and
 * 18:00 in different places; a single query for the whole day would just
 * be wrong for most of it.
 *
 * Every row is anchored on a real UTC instant (Stefan's find: the previous
 * version compared an implicitly-UTC "hour of day" target against
 * Open-Meteo's timezone=auto per-location LOCAL time - silently wrong for
 * a day that crosses timezones, since "hour 14" at a Frankfurt bucket and
 * "hour 14" at an Ulaanbaatar bucket aren't the same moment). The window
 * covered starts at the day's first known point (rounded down to the
 * hour) and steps forward one UTC hour at a time - one row per hour,
 * deliberately never compacted/grouped - until local 23:00 at the LAST
 * point's own location, which can be well over 24 UTC hours for a trip
 * travelling west (Stefan's example: Germany GMT+1 to San Francisco
 * GMT-7).
 */
final class WeatherFetchHandler implements JobHandlerInterface
{
    // Rounding to 2 decimal degrees is roughly 1km at mid latitudes - close
    // enough that points a few minutes apart on the same street share a
    // bucket (and its one Open-Meteo call), coarse enough to still separate
    // distinct stops on a touring day.
    private const LOCATION_ROUNDING = 2;

    // Requested on both sides of the diary date so a segment's own local
    // calendar date - which can differ from entry_date once a trip has
    // crossed timezones - is still comfortably covered; wider than any
    // real-world UTC offset (max ±14h) needs.
    private const RANGE_PADDING_DAYS = 1;

    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly TrackRepository $tracks,
        private readonly DayEntryWeatherHourRepository $weatherHours,
        private readonly WeatherService $weather,
        private readonly ReverseGeocodingService $geocoding,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $entryId = (int) ($payload['day_entry_id'] ?? 0);
        $entry = $this->entries->findById($entryId);

        if ($entry === null || $entry['lat'] === null || $entry['lng'] === null) {
            return; // Entry was deleted in the meantime, or has no location.
        }

        $lat = (float) $entry['lat'];
        $lng = (float) $entry['lng'];
        $date = (string) $entry['entry_date'];

        $result = $this->weather->fetchDaily($lat, $lng, $date);
        if ($result !== null) {
            $this->entries->updateWeather($entryId, $result['temp_c'], $result['code']);
        } else {
            $this->logger->info('No daily weather available for diary entry', ['id' => $entryId]);
        }

        try {
            $this->fetchHourly($entry, $entryId, $lat, $lng, $date);
        } catch (\Throwable $e) {
            // Best-effort, same as the daily summary above - a missing
            // hourly breakdown is cosmetic, never worth failing the job over.
            $this->logger->warning('Hourly weather fetch failed (non-fatal)', [
                'day_entry_id' => $entryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function fetchHourly(array $entry, int $entryId, float $fallbackLat, float $fallbackLng, string $date): void
    {
        $points = $this->pointsForDay((int) $entry['trip_id'], $entryId, $date, $fallbackLat, $fallbackLng);
        usort($points, static fn (array $a, array $b): int => $a['atUtc'] <=> $b['atUtc']);

        // Consecutive points sharing a rounded bucket become one segment -
        // a day that returns to an earlier bucket later gets a second,
        // separate segment (correct: it's a real second visit), but the
        // per-bucket Open-Meteo fetch below is still cached across segments
        // sharing the same bucket, so a same-day return trip costs no extra
        // call.
        $segments = [];
        $currentBucket = null;
        foreach ($points as $p) {
            $bucket = round($p['lat'], self::LOCATION_ROUNDING) . ',' . round($p['lng'], self::LOCATION_ROUNDING);
            if ($bucket !== $currentBucket) {
                $segments[] = ['bucket' => $bucket, 'lat' => $p['lat'], 'lng' => $p['lng']];
                $currentBucket = $bucket;
            }
        }

        $rangeStart = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('-' . self::RANGE_PADDING_DAYS . ' days');
        $rangeEnd = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('+' . self::RANGE_PADDING_DAYS . ' days');

        /** @var array<string, array{offsetSeconds: int, byUtcHour: array<string, array<string, mixed>>}> $weatherByBucket */
        $weatherByBucket = [];
        /** @var array<string, ?string> $nameByBucket */
        $nameByBucket = [];
        foreach ($segments as $seg) {
            $bucket = $seg['bucket'];
            if (isset($weatherByBucket[$bucket])) {
                continue;
            }
            $range = $this->weather->fetchHourlyRange($seg['lat'], $seg['lng'], $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'));
            $byUtcHour = [];
            foreach ($range['hours'] as $h) {
                $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $h['localTime'], new \DateTimeZone('UTC'));
                if ($local === false) {
                    continue;
                }
                $utc = $local->modify('-' . $range['offsetSeconds'] . ' seconds');
                $byUtcHour[$utc->format('Y-m-d H:00:00')] = $h;
            }
            $weatherByBucket[$bucket] = ['offsetSeconds' => $range['offsetSeconds'], 'byUtcHour' => $byUtcHour];

            try {
                $nameByBucket[$bucket] = $this->locationLabel($seg['lat'], $seg['lng']);
            } catch (\Throwable $e) {
                $nameByBucket[$bucket] = null;
                $this->logger->warning('Weather-hour location naming failed (non-fatal)', ['error' => $e->getMessage()]);
            }
        }

        if ($points === [] || $segments === []) {
            return;
        }

        // Window: first point's UTC time rounded down to the hour, through
        // local 23:00 at the LAST point's own location - not a fixed UTC
        // day, so a westward-travelling day naturally runs past 24 hours.
        $firstPoint = $points[0];
        $lastPoint = $points[count($points) - 1];
        $lastBucket = round($lastPoint['lat'], self::LOCATION_ROUNDING) . ',' . round($lastPoint['lng'], self::LOCATION_ROUNDING);
        $lastOffset = $weatherByBucket[$lastBucket]['offsetSeconds'] ?? 0;

        $windowStart = $firstPoint['atUtc']->setTime((int) $firstPoint['atUtc']->format('H'), 0, 0);
        $lastLocal = $lastPoint['atUtc']->modify("+{$lastOffset} seconds");
        $windowEndLocal = $lastLocal->setTime(23, 0, 0);
        $windowEnd = $windowEndLocal->modify('-' . $lastOffset . ' seconds');
        if ($windowEnd < $windowStart) {
            $windowEnd = $windowStart;
        }

        $rows = [];
        $cursor = $windowStart;
        while ($cursor <= $windowEnd) {
            $nearest = $this->nearestPointTo($points, $cursor);
            $bucket = round($nearest['lat'], self::LOCATION_ROUNDING) . ',' . round($nearest['lng'], self::LOCATION_ROUNDING);
            $bucketWeather = $weatherByBucket[$bucket] ?? null;
            $w = $bucketWeather['byUtcHour'][$cursor->format('Y-m-d H:00:00')] ?? null;
            $offsetSeconds = $bucketWeather['offsetSeconds'] ?? 0;
            $localHour = (int) $cursor->modify("+{$offsetSeconds} seconds")->format('H');

            $rows[] = [
                'hour' => $localHour,
                'lat' => $nearest['lat'],
                'lng' => $nearest['lng'],
                'observedAtUtc' => $cursor->format('Y-m-d H:i:s'),
                'utcOffsetSeconds' => $offsetSeconds,
                'locationName' => $nameByBucket[$bucket] ?? null,
                'tempC' => $w['tempC'] ?? null,
                'feelsLikeC' => $w['feelsLikeC'] ?? null,
                'precipitationProbability' => $w['precipitationProbability'] ?? null,
                'weatherCode' => $w['weatherCode'] ?? null,
                'windSpeedKmh' => $w['windSpeedKmh'] ?? null,
                'windDirectionDeg' => $w['windDirectionDeg'] ?? null,
            ];
            $cursor = $cursor->modify('+1 hour');
        }

        $this->weatherHours->replaceForEntry($entryId, $rows);
    }

    private function locationLabel(float $lat, float $lng): ?string
    {
        $result = $this->geocoding->placeLabel($lat, $lng);
        $name = $result['name'];
        $country = $result['country'];
        if ($name !== null && $country !== null) {
            return $name . ', ' . $country;
        }
        return $name ?? $country;
    }

    /**
     * Every point with a known time on this entry's day: the trip's track,
     * plus this entry's own geotagged photos (which may be more precise for
     * a stop the track missed, e.g. GPS off indoors). Falls back to the
     * entry's single fixed location (as a synthetic noon-UTC point) when
     * neither exists, matching the old single-location behaviour.
     *
     * @return list<array{lat: float, lng: float, atUtc: \DateTimeImmutable}>
     */
    private function pointsForDay(int $tripId, int $entryId, string $date, float $fallbackLat, float $fallbackLng): array
    {
        $points = [];

        $track = $this->tracks->findByTrip($tripId);
        if ($track !== null) {
            foreach ($this->tracks->findPoints((int) $track['id']) as $p) {
                if ($p['recorded_at'] !== null) {
                    $points[] = [
                        'lat' => (float) $p['lat'],
                        'lng' => (float) $p['lng'],
                        'atUtc' => new \DateTimeImmutable((string) $p['recorded_at'], new \DateTimeZone('UTC')),
                    ];
                }
            }
        }

        foreach ($this->photos->findByEntry($entryId) as $photo) {
            if ($photo['lat'] !== null && $photo['lng'] !== null && $photo['taken_at'] !== null) {
                $points[] = [
                    'lat' => (float) $photo['lat'],
                    'lng' => (float) $photo['lng'],
                    // taken_at is only genuinely UTC when the camera wrote an
                    // EXIF offset (see PhotoProcessHandler::parseExifDateTime())
                    // - a known, accepted imprecision, not fixed here; GPX
                    // recorded_at above is the reliable source for a
                    // multi-timezone day.
                    'atUtc' => new \DateTimeImmutable((string) $photo['taken_at'], new \DateTimeZone('UTC')),
                ];
            }
        }

        if ($points === []) {
            $points[] = [
                'lat' => $fallbackLat,
                'lng' => $fallbackLng,
                'atUtc' => new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')),
            ];
        }

        return $points;
    }

    /**
     * @param list<array{lat: float, lng: float, atUtc: \DateTimeImmutable}> $points
     * @return array{lat: float, lng: float}
     */
    private function nearestPointTo(array $points, \DateTimeImmutable $target): array
    {
        $best = $points[0];
        $bestDiff = abs($target->getTimestamp() - $best['atUtc']->getTimestamp());
        foreach ($points as $p) {
            $diff = abs($target->getTimestamp() - $p['atUtc']->getTimestamp());
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $p;
            }
        }
        return ['lat' => $best['lat'], 'lng' => $best['lng']];
    }
}
