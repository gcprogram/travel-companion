<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves a Google placeId into name/address/coordinates via the Places
 * API "Place Details" endpoint. Only used for Google Timeline imports: the
 * new export generation's visit.topCandidate carries a placeId but no
 * plain-text name (unlike the old export, which already embeds one) - see
 * google-timeline-import.js. Needs an admin-configured API key
 * (Settings::getSecret('google.places_api_key'), /admin/settings) - returns
 * null without one, since this is a paid Google API and enabling it must be
 * a deliberate admin choice, never silently attempted.
 *
 * Best-effort like ReverseGeocodingService: called from PoiController::
 * addStay, an explicit single user action, so a failed/slow lookup is worth
 * falling back from (to Nominatim), never worth blocking on.
 */
final class GooglePlacesService
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/place/details/json';

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array{name: ?string, address: ?string, lat: ?float, lng: ?float}|null
     */
    public function fetchDetails(string $placeId): ?array
    {
        $apiKey = $this->settings->getSecret('google.places_api_key');
        if ($apiKey === null) {
            return null;
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'place_id' => $placeId,
            'fields' => 'name,formatted_address,geometry',
            'key' => $apiKey,
            'language' => 'de',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['User-Agent: travel-companion (Places lookup, contact via app owner)'],
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

        if (!is_array($data) || ($data['status'] ?? null) !== 'OK' || !is_array($data['result'] ?? null)) {
            return null;
        }

        $result = $data['result'];
        $lat = $result['geometry']['location']['lat'] ?? null;
        $lng = $result['geometry']['location']['lng'] ?? null;
        return [
            'name' => is_string($result['name'] ?? null) ? $result['name'] : null,
            'address' => is_string($result['formatted_address'] ?? null) ? $result['formatted_address'] : null,
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lng' => is_numeric($lng) ? (float) $lng : null,
        ];
    }
}
