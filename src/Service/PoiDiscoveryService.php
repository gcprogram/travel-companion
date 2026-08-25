<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PoiRepository;
use App\Repository\StationRepository;
use App\Repository\TrackRepository;

/**
 * Finds sightseeing spots (museums, monuments, sacred buildings, zoos,
 * viewpoints, attractions) near a trip's route via the public Overpass API
 * — the "Nominatim/Overpass mit Caching" decision from CLAUDE.md. Results
 * are persisted straight to trip_pois, which doubles as the cache: results
 * are trip-scoped anyway, so there's no separate global cache table.
 *
 * Queries a bounding box per cluster of nearby track points rather than one
 * box around the whole trip, so a transcontinental trip doesn't pull in
 * places along the flight path it never actually visited. Bounded to a
 * fixed number of clusters/requests per run to stay a good citizen of the
 * free public Overpass instance.
 *
 * Name picking: a sight discovered outside the Latin-script world (Thailand,
 * Russia, ...) used to show up in a script most travellers can't read at
 * all - delegated to OsmNameLocalizer (name:de/name:en tags first, a
 * script-detecting translation fallback otherwise, never dropping the
 * sight) - discovered against Stefan's real report from a Thailand/Russia-
 * adjacent trip, later shared with ReverseGeocodingService's stay-landmark
 * naming on his explicit ask.
 */
final class PoiDiscoveryService
{
    private const ENDPOINT = 'https://overpass-api.de/api/interpreter';
    private const CLUSTER_GAP_METERS = 2000.0;
    private const MAX_CLUSTERS = 15;
    private const MAX_RESULTS_PER_CLUSTER = 40;
    private const METERS_PER_DEGREE = 111320.0;

    /**
     * Which OSM tag each searchable category maps to. 'other' is absent on
     * purpose: it's a manual-entry bucket, nothing to search OSM for.
     *
     * @var array<string, array{key: string, value: string}>
     */
    private const CATEGORY_TAGS = [
        'museum' => ['key' => 'tourism', 'value' => 'museum'],
        'zoo' => ['key' => 'tourism', 'value' => 'zoo'],
        'attraction' => ['key' => 'tourism', 'value' => 'attraction'],
        'viewpoint' => ['key' => 'tourism', 'value' => 'viewpoint'],
        'monument' => ['key' => 'historic', 'value' => 'monument|memorial|castle|ruins'],
        'sacred_building' => ['key' => 'amenity', 'value' => 'place_of_worship'],
    ];

    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly StationRepository $stations,
        private readonly PoiRepository $pois,
        private readonly Settings $settings,
        private readonly OsmNameLocalizer $nameLocalizer,
    ) {
    }

    /**
     * @return list<string> categories discovery can actually search for
     */
    public static function searchableCategories(): array
    {
        return array_keys(self::CATEGORY_TAGS);
    }

    /**
     * @param int|null $radiusMeters override for this run; null uses the
     *        admin default (poi.search_radius_meters)
     * @param list<string>|null $categories override for this run; null uses
     *        the admin default (poi.categories)
     * @return int number of POIs discovered/updated
     */
    public function discoverForTrip(int $tripId, ?int $radiusMeters = null, ?array $categories = null): int
    {
        $radiusMeters ??= $this->settings->getInt('poi.search_radius_meters');
        $categories ??= $this->settings->getList('poi.categories');

        $categories = array_values(array_intersect($categories, self::searchableCategories()));
        if ($categories === [] || $radiusMeters <= 0) {
            return 0;
        }

        $boxes = $this->buildBoundingBoxes($tripId, $radiusMeters / self::METERS_PER_DEGREE);
        if ($boxes === []) {
            return 0;
        }

        $count = 0;
        foreach ($boxes as $box) {
            foreach ($this->queryOverpass($box, $categories) as $element) {
                // The bbox itself can be far larger than radiusMeters: a
                // cluster only breaks on a >2km hop between *consecutive*
                // track points, so one continuous day of driving/walking -
                // however many km it actually covers - stays a single
                // cluster, and its bbox spans the whole thing corner to
                // corner. Overpass elements near one corner of that
                // rectangle can be tens of km from the route that's actually
                // a thin line through it, so the bbox alone isn't a
                // sufficient filter - only the true distance to this
                // cluster's own points is.
                if (!$this->withinRadius($box['points'], $element['lat'], $element['lng'], $radiusMeters)) {
                    continue;
                }
                $this->pois->upsertFromOverpass(
                    $tripId,
                    $element['type'] . '/' . $element['id'],
                    $element['category'],
                    $element['name'],
                    $element['lat'],
                    $element['lng'],
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     */
    private function withinRadius(array $points, float $lat, float $lng, int $radiusMeters): bool
    {
        foreach ($points as $point) {
            if ($this->haversineMeters($point['lat'], $point['lng'], $lat, $lng) <= $radiusMeters) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{south: float, west: float, north: float, east: float, points: list<array{lat: float, lng: float}>}>
     */
    private function buildBoundingBoxes(int $tripId, float $bufferDegrees): array
    {
        $track = $this->tracks->findByTrip($tripId);
        $points = [];
        if ($track !== null) {
            foreach ($this->tracks->findPoints((int) $track['id']) as $p) {
                $points[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
            }
        }

        if ($points === []) {
            foreach ($this->stations->findByTrip($tripId) as $s) {
                if ($s['lat'] !== null && $s['lng'] !== null) {
                    $points[] = ['lat' => (float) $s['lat'], 'lng' => (float) $s['lng']];
                }
            }
        }

        if ($points === []) {
            return [];
        }

        $clusters = $this->clusterPoints($points);

        $boxes = [];
        foreach (array_slice($clusters, 0, self::MAX_CLUSTERS) as $cluster) {
            $lats = array_column($cluster, 'lat');
            $lngs = array_column($cluster, 'lng');
            $boxes[] = [
                'south' => min($lats) - $bufferDegrees,
                'west' => min($lngs) - $bufferDegrees,
                'north' => max($lats) + $bufferDegrees,
                'east' => max($lngs) + $bufferDegrees,
                'points' => $cluster,
            ];
        }
        return $boxes;
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     * @return list<list<array{lat: float, lng: float}>>
     */
    private function clusterPoints(array $points): array
    {
        $clusters = [];
        $current = [$points[0]];

        for ($i = 1; $i < count($points); $i++) {
            $gap = $this->haversineMeters(
                $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                $points[$i]['lat'], $points[$i]['lng'],
            );
            if ($gap > self::CLUSTER_GAP_METERS) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $points[$i];
        }
        $clusters[] = $current;

        return $clusters;
    }

    /**
     * @param array{south: float, west: float, north: float, east: float} $box
     * @return list<array{type: string, id: int, category: string, name: string, lat: float, lng: float}>
     */
    private function queryOverpass(array $box, array $categories): array
    {
        $query = $this->buildQuery($box, $categories);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['User-Agent: travel-companion (POI discovery)'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Overpass request failed: ' . $error);
        }
        if ($status !== 200) {
            throw new \RuntimeException('Overpass responded with status ' . $status . ': ' . substr((string) $body, 0, 500));
        }

        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        $elements = $data['elements'] ?? [];

        $results = [];
        foreach ($elements as $el) {
            $name = $this->pickName($el['tags'] ?? []);
            if ($name === null) {
                continue; // No name in any form — not useful in a sightseeing list.
            }
            $category = $this->categorize($el['tags'] ?? [], $categories);
            if ($category === null) {
                continue;
            }
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($lat === null || $lng === null) {
                continue; // Way/relation without a computed center (shouldn't happen with "out center").
            }
            $results[] = [
                'type' => (string) $el['type'],
                'id' => (int) $el['id'],
                'category' => $category,
                'name' => $name,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
            if (count($results) >= self::MAX_RESULTS_PER_CLUSTER) {
                break;
            }
        }
        return $results;
    }

    /**
     * Groups the requested categories by OSM tag key, so several categories
     * sharing one key (museum/zoo/... all being "tourism") stay a single
     * regex clause per element type instead of one query clause each.
     *
     * @param array{south: float, west: float, north: float, east: float} $box
     * @param list<string> $categories
     */
    private function buildQuery(array $box, array $categories): string
    {
        $bbox = sprintf('%F,%F,%F,%F', $box['south'], $box['west'], $box['north'], $box['east']);

        $byKey = [];
        foreach ($categories as $category) {
            $tag = self::CATEGORY_TAGS[$category];
            $byKey[$tag['key']][] = $tag['value'];
        }

        $clauses = [];
        foreach ($byKey as $key => $values) {
            $valuePattern = implode('|', $values);
            foreach (['node', 'way', 'relation'] as $elementType) {
                $clauses[] = sprintf('%s["%s"~"%s"](%s);', $elementType, $key, $valuePattern, $bbox);
            }
        }

        return '[out:json][timeout:25];(' . implode('', $clauses) . ');out center;';
    }

    /**
     * @param array<string, string> $tags
     */
    private function pickName(array $tags): ?string
    {
        return $this->nameLocalizer->fromTags($tags);
    }

    /**
     * @param array<string, string> $tags
     * @param list<string> $categories the ones actually requested - a shared
     *        tag key can return more than was asked for (e.g. "tourism"
     *        matching a zoo when only museums were wanted)
     */
    private function categorize(array $tags, array $categories): ?string
    {
        $tourism = $tags['tourism'] ?? null;
        if (in_array($tourism, ['museum', 'zoo', 'attraction', 'viewpoint'], true)) {
            return in_array($tourism, $categories, true) ? $tourism : null;
        }
        $historic = $tags['historic'] ?? null;
        if (in_array($historic, ['monument', 'memorial', 'castle', 'ruins'], true)) {
            return in_array('monument', $categories, true) ? 'monument' : null;
        }
        if (($tags['amenity'] ?? null) === 'place_of_worship') {
            return in_array('sacred_building', $categories, true) ? 'sacred_building' : null;
        }
        return null;
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
}
