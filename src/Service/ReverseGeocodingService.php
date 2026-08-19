<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns coordinates into a short place name. Two-stage lookup:
 *
 * 1. A small Overpass "around" search for named OSM elements close to the
 *    point - same public API PoiDiscoveryService already queries for
 *    sightseeing search, different query shape (radius around a point, not
 *    a bbox). Nominatim's own reverse endpoint only ever returns its single
 *    best-weighted guess for a point, which loses against a nearby-but-not-
 *    nearest landmark whenever two named places sit close together (a
 *    church next to a dentist's office, a restaurant near the hamlet it's
 *    part of, ...) - discovered against three of Stefan's real stay
 *    reports, see HANDOVER.md Teil 8. Overpass lets us actually rank the
 *    candidates instead of trusting Nominatim's single pick.
 * 2. Nominatim's /reverse, either to fill in the address around a landmark
 *    Overpass found (which rarely carries a complete addr:* tag set), or as
 *    the sole result when Overpass found nothing landmark-like nearby.
 *
 * Best-effort throughout: called from job handlers where a missing location
 * name is a cosmetic gap, never worth failing/retrying a job over.
 *
 * Nominatim's usage policy caps this at ~1 request/second and requires a
 * real identifying User-Agent; Overpass's public instance is similarly
 * quick to rate-limit a burst (observed directly: two "around" queries ~2s
 * apart already got refused). A single stay/photo/track upload naturally
 * spaces calls out enough on its own, but detectStays() can dispatch a
 * geocode.resolve job for a dozen-plus stays at once (e.g. right after a
 * cache-clear or a big new track), and the job worker then runs them
 * back-to-back inside one process with nothing else slowing it down - a
 * silent Overpass rate-limit on one of those calls doesn't fail loudly, it
 * just falls through to the plain Nominatim path and THAT gets cached
 * permanently, indistinguishable from "genuinely no landmark here"
 * (discovered against Stefan's real data: several stays regressed to a
 * worse address composition right after a batch cache-clear). throttle()
 * enforces a floor between every external call this class makes, Overpass
 * and Nominatim alike, so a big batch just takes a bit longer across
 * several job-worker runs instead of quietly degrading its own results.
 */
final class ReverseGeocodingService
{
    private const NOMINATIM_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';
    private const OVERPASS_ENDPOINT = 'https://overpass-api.de/api/interpreter';
    private const MIN_CALL_INTERVAL_SECONDS = 1.1;

    // Static rather than an instance property: the throttle needs to hold
    // across every job the worker process handles in one run, not just
    // calls made through whichever single instance happens to receive them.
    private static float $lastExternalCallAt = 0.0;

    // Tight pass: dense city blocks need a short leash, or the "nearest
    // named thing" becomes noise (the next shop over, a parked landmark two
    // doors down). Wide pass only fires when the tight one found nothing,
    // and only for features that are genuinely visible/relevant from
    // further out (a mountain, a national park, a church tower) - Stefan's
    // own framing: "ein Berg ist weithin sichtbar, in der Innenstadt muss
    // man enger schauen".
    private const TIGHT_RADIUS_METERS = 50;
    private const WIDE_RADIUS_METERS = 200;

    /**
     * Named OSM elements that are background infrastructure, never what a
     * travel diary means by "the place" - a dentist's office or a bank
     * happening to sit nearest to a stay's centroid shouldn't ever win over
     * an actual named building/venue a few meters further out. Checked
     * against the element's OSM key=value tags; a key with no listed values
     * means "any value under that key is excluded" (e.g. every office=*).
     *
     * @var array<string, list<string>|true>
     */
    private const EXCLUDED_TAGS = [
        'office' => true,
        'healthcare' => true,
        'craft' => true,
        'highway' => true, // road names ("Werkstraße") are handled via composeAddress(), not as a landmark hit
        'amenity' => [
            'bank', 'atm', 'fuel', 'car_wash', 'vending_machine', 'dentist', 'doctors', 'clinic', 'pharmacy',
            'veterinary', 'driving_school', 'police', 'fire_station', 'prison', 'toilets', 'parking',
            'bicycle_parking', 'waste_disposal', 'recycling', 'post_box', 'post_depot', 'telephone',
            'charging_station', 'social_facility', 'courthouse',
        ],
        'shop' => ['hairdresser', 'beauty', 'massage', 'tattoo', 'dry_cleaning', 'laundry', 'funeral_directors'],
    ];

    /**
     * The wide-pass allow-list: only features notable/visible enough that
     * being 50-200m off their centroid is still clearly "there" - the
     * inverse of the tight pass's deny-list approach, since at this radius
     * most named things are simply too far away to be what a stay is at.
     *
     * @var array<string, list<string>>
     */
    private const WIDE_LANDMARK_TAGS = [
        'natural' => ['peak', 'volcano', 'glacier', 'cape'],
        'boundary' => ['national_park', 'protected_area'],
        'leisure' => ['nature_reserve'],
        'tourism' => ['attraction', 'viewpoint', 'zoo', 'museum', 'theme_park'],
        'historic' => ['castle', 'monument', 'memorial', 'ruins', 'fort'],
        'amenity' => ['place_of_worship'],
        'waterway' => ['waterfall'],
    ];

    /**
     * @return array{name: ?string, country: ?string}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        $landmark = $this->findLandmark($lat, $lng);
        if ($landmark !== null) {
            return $this->resolveLandmark($landmark);
        }

        return $this->reverseGeocodeViaNominatim($lat, $lng);
    }

    /**
     * @return array{name: ?string, country: ?string}
     */
    private function reverseGeocodeViaNominatim(float $lat, float $lng): array
    {
        $data = $this->nominatimReverseRaw($lat, $lng);
        if ($data === null) {
            return ['name' => null, 'country' => null];
        }

        return ['name' => $this->pickName($data), 'country' => $this->pickCountry($data)];
    }

    /**
     * @return array{name: string, lat: float, lng: float, tags: array<string, string>}|null
     */
    private function findLandmark(float $lat, float $lng): ?array
    {
        try {
            $tight = $this->queryOverpassAround($lat, $lng, self::TIGHT_RADIUS_METERS);
        } catch (\Throwable) {
            return null; // Best-effort - falls through to the Nominatim-only path.
        }

        $best = $this->nearestAllowed($tight, $lat, $lng, fn (array $tags) => !$this->isExcluded($tags));
        if ($best !== null) {
            return $best;
        }

        try {
            $wide = $this->queryOverpassAround($lat, $lng, self::WIDE_RADIUS_METERS);
        } catch (\Throwable) {
            return null;
        }

        return $this->nearestAllowed($wide, $lat, $lng, fn (array $tags) => $this->isWideLandmark($tags));
    }

    /**
     * @param list<array{name: string, lat: float, lng: float, tags: array<string, string>}> $elements
     * @param callable(array<string, string>): bool $accept
     * @return array{name: string, lat: float, lng: float, tags: array<string, string>}|null
     */
    private function nearestAllowed(array $elements, float $lat, float $lng, callable $accept): ?array
    {
        $best = null;
        $bestDistance = null;
        foreach ($elements as $element) {
            if (!$accept($element['tags'])) {
                continue;
            }
            $distance = $this->haversineMeters($lat, $lng, $element['lat'], $element['lng']);
            if ($bestDistance === null || $distance < $bestDistance) {
                $best = $element;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /**
     * @param array{name: string, lat: float, lng: float, tags: array<string, string>} $landmark
     * @return array{name: ?string, country: ?string}
     */
    private function resolveLandmark(array $landmark): array
    {
        $tags = $landmark['tags'];
        $street = $tags['addr:street'] ?? null;
        $houseNumber = $tags['addr:housenumber'] ?? null;
        $postcode = $tags['addr:postcode'] ?? null;
        $city = $tags['addr:city'] ?? null;

        // Overpass tags rarely carry the full address set - fill whatever's
        // missing (and grab the country, which OSM tags never carry) from
        // Nominatim at the landmark's own coordinate. Overpass-supplied
        // parts win where present: they're specific to this exact element,
        // Nominatim's reverse pick at this point might not even agree it's
        // the same building.
        $country = null;
        if ($street === null || $postcode === null || $city === null) {
            $data = $this->nominatimReverseRaw($landmark['lat'], $landmark['lng']);
            $address = is_array($data) ? ($data['address'] ?? null) : null;
            if (is_array($address)) {
                $street ??= $this->firstStringOf($address, ['road']);
                $houseNumber ??= $this->firstStringOf($address, ['house_number']);
                $postcode ??= $this->firstStringOf($address, ['postcode']);
                $city ??= $this->firstStringOf($address, ['city', 'town', 'village', 'municipality', 'hamlet']);
            }
            $country = is_array($data) ? $this->pickCountry($data) : null;
        }

        $streetPart = $street !== null ? trim($street . ' ' . ($houseNumber ?? '')) : null;
        $localityPart = ($postcode !== null && $city !== null) ? "$postcode $city" : $city;

        $addressBits = array_values(array_filter([$streetPart, $localityPart], static fn ($v) => $v !== null && $v !== ''));
        $name = $addressBits !== []
            ? $landmark['name'] . ' (' . implode(', ', $addressBits) . ')'
            : $landmark['name'];

        return ['name' => mb_substr($name, 0, 190), 'country' => $country];
    }

    /**
     * @param array<string, string> $tags
     */
    private function isExcluded(array $tags): bool
    {
        foreach (self::EXCLUDED_TAGS as $key => $values) {
            if (!isset($tags[$key])) {
                continue;
            }
            if ($values === true || in_array($tags[$key], $values, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, string> $tags
     */
    private function isWideLandmark(array $tags): bool
    {
        foreach (self::WIDE_LANDMARK_TAGS as $key => $values) {
            if (isset($tags[$key]) && in_array($tags[$key], $values, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{name: string, lat: float, lng: float, tags: array<string, string>}>
     */
    private function queryOverpassAround(float $lat, float $lng, int $radiusMeters): array
    {
        $this->throttle();

        $query = sprintf(
            '[out:json][timeout:15];(node(around:%d,%F,%F)[name];way(around:%d,%F,%F)[name];relation(around:%d,%F,%F)[name];);out center tags;',
            $radiusMeters, $lat, $lng,
            $radiusMeters, $lat, $lng,
            $radiusMeters, $lat, $lng,
        );

        $ch = curl_init(self::OVERPASS_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['User-Agent: travel-companion (landmark-aware reverse geocoding)'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return [];
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $results = [];
        foreach ($data['elements'] ?? [] as $el) {
            $name = $el['tags']['name'] ?? null;
            $elLat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $elLng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if (!is_string($name) || $name === '' || $elLat === null || $elLng === null) {
                continue;
            }
            $results[] = [
                'name' => mb_substr($name, 0, 190),
                'lat' => (float) $elLat,
                'lng' => (float) $elLng,
                'tags' => is_array($el['tags'] ?? null) ? $el['tags'] : [],
            ];
        }
        return $results;
    }

    /**
     * @return array<string, mixed>|null decoded Nominatim jsonv2 response
     */
    private function nominatimReverseRaw(float $lat, float $lng): ?array
    {
        $this->throttle();

        $url = self::NOMINATIM_ENDPOINT . '?' . http_build_query([
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
            return null;
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
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

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Blocks until at least MIN_CALL_INTERVAL_SECONDS has passed since the
     * last external call this class made (Overpass or Nominatim, either
     * one counts against the same floor - see the class docblock for why a
     * batch of many stays resolving back-to-back needs this, not just a
     * single lookup). A worker resolving a big batch just runs a bit longer
     * across more cron ticks; that's the acceptable cost, cheaper than a
     * silently rate-limited call permanently caching a worse fallback name.
     */
    private function throttle(): void
    {
        $now = microtime(true);
        $elapsed = $now - self::$lastExternalCallAt;
        if ($elapsed < self::MIN_CALL_INTERVAL_SECONDS) {
            usleep((int) round((self::MIN_CALL_INTERVAL_SECONDS - $elapsed) * 1_000_000));
        }
        self::$lastExternalCallAt = microtime(true);
    }
}
