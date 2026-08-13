<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\DayEntryWeatherHourRepository;
use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;
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
 * Hours are grouped by location first (rounded to ~1km) so a day mostly
 * spent in one place costs one Open-Meteo call, not up to 24 - each
 * distinct location still only needs one "whole day, hour by hour" request
 * to cover every hour assigned to it.
 */
final class WeatherFetchHandler implements JobHandlerInterface
{
    // Rounding to 2 decimal degrees is roughly 1km at mid latitudes - close
    // enough that hours a few minutes apart on the same street don't each
    // trigger their own API call, coarse enough to still separate distinct
    // stops on a touring day.
    private const LOCATION_ROUNDING = 2;

    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly TrackRepository $tracks,
        private readonly DayEntryWeatherHourRepository $weatherHours,
        private readonly WeatherService $weather,
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
        $points = $this->pointsForDay((int) $entry['trip_id'], $entryId);

        // hour => [lat, lng] of whichever point is nearest in time to that hour.
        $locationByHour = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $locationByHour[$hour] = $this->nearestPointForHour($points, $date, $hour) ?? ['lat' => $fallbackLat, 'lng' => $fallbackLng];
        }

        // Group hours by rounded location so each distinct spot is only
        // queried once for its whole day, not once per hour.
        $hoursByBucket = [];
        $bucketLocation = [];
        foreach ($locationByHour as $hour => $loc) {
            $bucket = round($loc['lat'], self::LOCATION_ROUNDING) . ',' . round($loc['lng'], self::LOCATION_ROUNDING);
            $hoursByBucket[$bucket][] = $hour;
            $bucketLocation[$bucket] = $loc;
        }

        $rows = [];
        foreach ($hoursByBucket as $bucket => $hours) {
            $loc = $bucketLocation[$bucket];
            $hourlyData = $this->weather->fetchHourly($loc['lat'], $loc['lng'], $date);
            foreach ($hours as $hour) {
                $w = $hourlyData[$hour] ?? null;
                $rows[] = [
                    'hour' => $hour,
                    'lat' => $loc['lat'],
                    'lng' => $loc['lng'],
                    'tempC' => $w['tempC'] ?? null,
                    'feelsLikeC' => $w['feelsLikeC'] ?? null,
                    'precipitationProbability' => $w['precipitationProbability'] ?? null,
                    'weatherCode' => $w['weatherCode'] ?? null,
                    'windSpeedKmh' => $w['windSpeedKmh'] ?? null,
                    'windDirectionDeg' => $w['windDirectionDeg'] ?? null,
                ];
            }
        }

        $this->weatherHours->replaceForEntry($entryId, $rows);
    }

    /**
     * Every point with a known time on this entry's day: the trip's track,
     * plus this entry's own geotagged photos (which may be more precise for
     * a stop the track missed, e.g. GPS off indoors).
     *
     * @return list<array{lat: float, lng: float, at: string}>
     */
    private function pointsForDay(int $tripId, int $entryId): array
    {
        $points = [];

        $track = $this->tracks->findByTrip($tripId);
        if ($track !== null) {
            foreach ($this->tracks->findPoints((int) $track['id']) as $p) {
                if ($p['recorded_at'] !== null) {
                    $points[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'at' => (string) $p['recorded_at']];
                }
            }
        }

        foreach ($this->photos->findByEntry($entryId) as $photo) {
            if ($photo['lat'] !== null && $photo['lng'] !== null && $photo['taken_at'] !== null) {
                $points[] = ['lat' => (float) $photo['lat'], 'lng' => (float) $photo['lng'], 'at' => (string) $photo['taken_at']];
            }
        }

        return $points;
    }

    /**
     * @param list<array{lat: float, lng: float, at: string}> $points
     * @return array{lat: float, lng: float}|null
     */
    private function nearestPointForHour(array $points, string $date, int $hour): ?array
    {
        if ($points === []) {
            return null;
        }

        $target = strtotime(sprintf('%s %02d:30:00', $date, $hour));
        $best = null;
        $bestDiff = null;
        foreach ($points as $p) {
            $diff = abs(strtotime($p['at']) - $target);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $p;
            }
        }

        return $best !== null ? ['lat' => $best['lat'], 'lng' => $best['lng']] : null;
    }
}
