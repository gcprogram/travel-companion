<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Holt Tageswetter (Mitteltemperatur + WMO-Wettercode) von Open-Meteo.
 * Kostenlos, ohne API-Key. Der Forecast-Endpunkt (statt des Archiv-
 * Endpunkts) wird bewusst verwendet, weil das ERA5-Archiv erst nach ca.
 * 5 Tagen befüllt wird – Tagebucheinträge entstehen aber meist während
 * oder direkt nach der Reise.
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
            throw new \RuntimeException('Open-Meteo-Anfrage fehlgeschlagen: ' . $error);
        }
        if ($status !== 200) {
            throw new \RuntimeException('Open-Meteo antwortete mit Status ' . $status . ': ' . $body);
        }

        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        $temp = $data['daily']['temperature_2m_mean'][0] ?? null;
        $code = $data['daily']['weathercode'][0] ?? null;

        if ($temp === null || $code === null) {
            return null; // Für dieses Datum liegen (noch) keine Daten vor.
        }

        return ['temp_c' => (float) $temp, 'code' => (int) $code];
    }
}
