<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Fetches weather from Open-Meteo. Free, no API key. Tries the FORECAST
 * endpoint first (covers roughly the last ~90 days through the next ~16,
 * including today - the common case, a diary entry written during or right
 * after the trip) and falls back to the ARCHIVE endpoint (ERA5 reanalysis,
 * back to 1940, but only filled in for dates more than ~5 days old) on a
 * non-2xx response - specifically for a diary entry added long after the
 * trip actually happened (e.g. an old trip only just entered into the app),
 * whose date has since aged out of the forecast endpoint's rolling window
 * entirely (Stefan's real case: a trip from October 2024 got no weather at
 * all, discovered when the forecast endpoint started rejecting it with
 * "start_date is out of allowed range"). Trying forecast first rather than
 * branching on the entry's own age keeps this correct without hardcoding
 * Open-Meteo's rolling window boundary, which shifts every day.
 */
final class WeatherService
{
    private const FORECAST_ENDPOINT = 'https://api.open-meteo.com/v1/forecast';
    private const ARCHIVE_ENDPOINT = 'https://archive-api.open-meteo.com/v1/archive';

    /**
     * @return array{temp_c: float, code: int}|null
     */
    public function fetchDaily(float $lat, float $lng, string $date): ?array
    {
        $query = http_build_query([
            'latitude' => $lat,
            'longitude' => $lng,
            'start_date' => $date,
            'end_date' => $date,
            'daily' => 'weathercode,temperature_2m_mean',
            'timezone' => 'UTC',
        ]);

        $data = $this->requestWithFallback($query);
        $temp = $data['daily']['temperature_2m_mean'][0] ?? null;
        $code = $data['daily']['weathercode'][0] ?? null;

        if ($temp === null || $code === null) {
            return null; // No data available (yet) for this date.
        }

        return ['temp_c' => (float) $temp, 'code' => (int) $code];
    }

    /**
     * One calendar day, hour by hour, at a single lat/lng - the caller
     * (WeatherFetchHandler) is responsible for splitting a day across
     * several calls if the traveller was in more than one place.
     * timezone=auto asks Open-Meteo to return each hour already in the
     * local time of the queried coordinates (the appropriate "local time"
     * for a travel diary about that place), rather than UTC needing
     * conversion here or client-side.
     *
     * @return array<int, array{tempC: ?float, feelsLikeC: ?float, precipitationProbability: ?int, weatherCode: ?int, windSpeedKmh: ?float, windDirectionDeg: ?int}>
     *         keyed by local hour of day (0-23); missing hours (e.g. a date
     *         outside the forecast window) are simply absent from the array
     */
    public function fetchHourly(float $lat, float $lng, string $date): array
    {
        $query = http_build_query([
            'latitude' => $lat,
            'longitude' => $lng,
            'start_date' => $date,
            'end_date' => $date,
            'hourly' => 'temperature_2m,apparent_temperature,precipitation_probability,weathercode,windspeed_10m,winddirection_10m',
            'timezone' => 'auto',
        ]);

        $data = $this->requestWithFallback($query);
        $times = $data['hourly']['time'] ?? [];

        $byHour = [];
        foreach ($times as $i => $time) {
            // "2026-07-25T14:00" - the hour is the two digits after 'T'.
            $hour = (int) substr((string) $time, 11, 2);
            $byHour[$hour] = [
                'tempC' => $this->numOrNull($data['hourly']['temperature_2m'][$i] ?? null),
                'feelsLikeC' => $this->numOrNull($data['hourly']['apparent_temperature'][$i] ?? null),
                'precipitationProbability' => $this->intOrNull($data['hourly']['precipitation_probability'][$i] ?? null),
                'weatherCode' => $this->intOrNull($data['hourly']['weathercode'][$i] ?? null),
                'windSpeedKmh' => $this->numOrNull($data['hourly']['windspeed_10m'][$i] ?? null),
                'windDirectionDeg' => $this->intOrNull($data['hourly']['winddirection_10m'][$i] ?? null),
            ];
        }
        return $byHour;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestWithFallback(string $query): array
    {
        try {
            return $this->request(self::FORECAST_ENDPOINT . '?' . $query);
        } catch (\RuntimeException) {
            // Most likely the date has aged out of the forecast endpoint's
            // rolling window (too far in the past) - archive covers that
            // case back to 1940, just without a same-day/near-future range.
            return $this->request(self::ARCHIVE_ENDPOINT . '?' . $query);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['User-Agent: travel-companion'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Open-Meteo request failed: ' . $error);
        }
        if ($status !== 200) {
            throw new \RuntimeException('Open-Meteo responded with status ' . $status . ': ' . $body);
        }

        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function numOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
