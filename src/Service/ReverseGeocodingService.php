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

    /**
     * @return array{name: ?string, country: ?string}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'format' => 'jsonv2',
            'lat' => $lat,
            'lon' => $lng,
            // 18 = building-level detail. zoom=14 (the old value) capped
            // Nominatim's own address-detail level at "hamlet/suburb", so
            // it never even considered an actual named amenity sitting
            // right at the point - discovered against Stefan's real report
            // ("Landhaus Schlösser" resolved to "Schloss Lörsfeld", ~1km
            // away: at zoom=14 that whole area shares one hamlet-sized
            // catchment, the restaurant itself was never in the running).
            // Nominatim still falls back to a coarser feature server-side
            // when nothing more specific exists at a point, so this is
            // strictly more precise, not just differently precise.
            'zoom' => 18,
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
            return ['name' => null, 'country' => null];
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['name' => null, 'country' => null];
        }

        return ['name' => $this->pickName($data), 'country' => $this->pickCountry($data)];
    }

    /**
     * A result whose own "name" is just a sub-city administrative boundary -
     * Nominatim resolves a point inside e.g. Frankfurt-Nordend directly to
     * that suburb polygon, and its "name" is bare ("Nordend West") with no
     * city attached. Meaningless on its own (discovered against Stefan's
     * real report - see pickName()), so these fall through to
     * composeAddress() the same as if there'd been no name.
     *
     * @var list<string>
     */
    private const SUBDIVISION_ADDRESS_TYPES = ['suburb', 'city_district', 'borough', 'quarter', 'neighbourhood'];

    /**
     * A result that's just a street/way, no place of its own - at
     * zoom=18's building-level precision, a point out in the country with
     * nothing built on it resolves to the nearest road by that name alone
     * ("Ketziner Straße"), which means nothing without the village it runs
     * through. composeAddress() already knows how to combine a road with
     * its settlement, so these get the same treatment as a subdivision.
     *
     * @var list<string>
     */
    private const WAY_ADDRESS_TYPES = [
        'road', 'residential', 'living_street', 'pedestrian', 'path', 'footway', 'track', 'service', 'cycleway',
    ];

    /**
     * @param mixed $data decoded Nominatim jsonv2 response
     */
    private function pickName(mixed $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }

        $addressType = $data['addresstype'] ?? null;
        $needsComposition = is_string($addressType)
            && (in_array($addressType, self::SUBDIVISION_ADDRESS_TYPES, true)
                || in_array($addressType, self::WAY_ADDRESS_TYPES, true));

        // The nearest named feature (a village, a national park, a named
        // attraction, ...) if Nominatim found one at this zoom level - but
        // not a bare subdivision/road name, see SUBDIVISION_ADDRESS_TYPES/
        // WAY_ADDRESS_TYPES.
        $name = $data['name'] ?? null;
        if (is_string($name) && $name !== '' && !$needsComposition) {
            return mb_substr($name, 0, 190);
        }

        $address = $data['address'] ?? null;
        if (is_array($address)) {
            $composed = $this->composeAddress($address);
            if ($composed !== null) {
                return mb_substr($composed, 0, 190);
            }
        }

        // No usable address components to compose with - the bare
        // subdivision name is still better than nothing at this point.
        if (is_string($name) && $name !== '') {
            return mb_substr($name, 0, 190);
        }

        $displayName = $data['display_name'] ?? null;
        if (is_string($displayName) && $displayName !== '') {
            return mb_substr($displayName, 0, 190);
        }

        return null;
    }

    /**
     * Combines Nominatim's address parts into something actually useful for
     * an auto-detected stay that isn't already a known sight/geocache
     * (those already carry their own real name) - a bare district/suburb
     * name like "Nordend" without its city was worse than useless on its
     * own. Prefers street(+house number) or the finest sub-locality
     * (suburb/district/quarter), each combined with the settlement name
     * ("Musterstraße 12, Frankfurt am Main" / "Nordend, Frankfurt am
     * Main") - falls back to just the settlement (or null) if neither a
     * street nor a locality is present at all.
     *
     * @param array<string, mixed> $address
     */
    private function composeAddress(array $address): ?string
    {
        // hamlet first: OSM's place hierarchy runs city > town > village >
        // hamlet (most to least populous), so a hamlet is the *most*
        // specific/local of these when present - "Schloss Lörsfeld"
        // (a hamlet) rather than "Kerpen" (the town it's part of) is the
        // useful label for a travel diary, same reasoning as
        // suburb/city_district already winning over their parent city.
        // County last: for a kreisfreie Stadt (Frankfurt, Munich, ...) the
        // address object can have a suburb but no city/town/village key at
        // all - county is that settlement's own name in that case, not an
        // actual rural county, so it's still worth combining with.
        $settlement = $this->firstStringOf($address, ['hamlet', 'city', 'town', 'village', 'municipality', 'county']);
        $locality = $this->firstStringOf($address, ['suburb', 'city_district', 'borough', 'quarter']);

        $street = null;
        $road = $address['road'] ?? null;
        if (is_string($road) && $road !== '') {
            $houseNumber = $address['house_number'] ?? null;
            $street = (is_string($houseNumber) && $houseNumber !== '') ? "$road $houseNumber" : $road;
        }

        $lead = $street ?? $locality;
        $parts = [];
        if ($lead !== null) {
            $parts[] = $lead;
        }
        if ($settlement !== null && $settlement !== $lead) {
            $parts[] = $settlement;
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @param array<string, mixed> $address
     * @param list<string> $keys
     */
    private function firstStringOf(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $address[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param mixed $data decoded Nominatim jsonv2 response
     */
    private function pickCountry(mixed $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }
        $country = $data['address']['country'] ?? null;
        return (is_string($country) && $country !== '') ? mb_substr($country, 0, 100) : null;
    }
}
