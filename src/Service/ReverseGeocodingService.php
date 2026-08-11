<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns coordinates into a short place name via Nominatim's free reverse
 * geocoding endpoint - same OSM family as PoiDiscoveryService's Overpass
 * calls, but a different endpoint (reverse geocoding, not a tag search).
 * Best-effort throughout: called from job handlers where a missing location
 * name is a cosmetic gap, never worth failing/retrying a job over.
 *
 * Nominatim's usage policy caps this at ~1 request/second and requires a
 * real identifying User-Agent - both satisfied here: calls only happen a
 * handful of times per entry (photo/video upload, track upload), sequenced
 * through the job worker rather than fired in a burst.
 */
final class ReverseGeocodingService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    public function reverseGeocode(float $lat, float $lng): ?string
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'format' => 'jsonv2',
            'lat' => $lat,
            'lon' => $lng,
            'zoom' => 14,
            'accept-language' => 'de,en',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['User-Agent: travel-companion (reverse geocoding, contact via app owner)'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return null;
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $this->pickName($data);
    }

    /**
     * @param mixed $data decoded Nominatim jsonv2 response
     */
    private function pickName(mixed $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }

        // The nearest named feature (a village, a national park, a named
        // attraction, ...) if Nominatim found one at this zoom level.
        $name = $data['name'] ?? null;
        if (is_string($name) && $name !== '') {
            return mb_substr($name, 0, 190);
        }

        $address = $data['address'] ?? null;
        if (is_array($address)) {
            foreach (['city', 'town', 'village', 'suburb', 'municipality', 'county'] as $key) {
                $value = $address[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return mb_substr($value, 0, 190);
                }
            }
        }

        $displayName = $data['display_name'] ?? null;
        if (is_string($displayName) && $displayName !== '') {
            return mb_substr($displayName, 0, 190);
        }

        return null;
    }
}
