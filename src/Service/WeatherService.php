<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Fetches daily weather (mean temperature + WMO weather code) from Open-Meteo.
 * Free, no API key. The forecast endpoint (rather than the archive endpoint)
 * is used deliberately, because the ERA5 archive only fills in after about
 * 5 days – diary entries are usually written during or right after the trip.
 */
final class WeatherService
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    /**
     * @return array{temp_c: float, code: int}|null
     */
    public function fetchDaily(float $lat, float $lng, string $date): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'latitude' => $lat,
            'longitude' => $lng,
            'start_date' => $date,
            'end_date' => $date,
            'daily' => 'weathercode,temperature_2m_mean',
            'timezone' => 'UTC',
        ]);

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

        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        $temp = $data['daily']['temperature_2m_mean'][0] ?? null;
        $code = $data['daily']['weathercode'][0] ?? null;

        if ($temp === null || $code === null) {
            return null; // No data available (yet) for this date.
        }

        return ['temp_c' => (float) $temp, 'code' => (int) $code];
    }
}
