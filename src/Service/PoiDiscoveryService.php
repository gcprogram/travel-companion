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
 */
final class PoiDiscoveryService
{
    private const ENDPOINT = 'https://overpass-api.de/api/interpreter';
    private const CLUSTER_GAP_METERS = 2000.0;
    private const BBOX_BUFFER_DEGREES = 0.005; // ~550m
    private const MAX_CLUSTERS = 15;
    private const MAX_RESULTS_PER_CLUSTER = 40;

    /**
     * @var array<string, string> OSM tag key => Overpass regex value filter
     */
    private const TAG_FILTERS = [
        'tourism' => 'museum|zoo|attraction|viewpoint',
        'historic' => 'monument|memorial|castle|ruins',
        'amenity' => 'place_of_worship',
    ];

    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly StationRepository $stations,
        private readonly PoiRepository $pois,
    ) {
    }

    /**
     * @return int number of POIs discovered/updated
     */
    public function discoverForTrip(int $tripId): int
    {
        $boxes = $this->buildBoundingBoxes($tripId);
        if ($boxes === []) {
            return 0;
        }

        $count = 0;
        foreach ($boxes as $box) {
            foreach ($this->queryOverpass($box) as $element) {
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
     * @return list<array{south: float, west: float, north: float, east: float}>
     */
    private function buildBoundingBoxes(int $tripId): array
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
                'south' => min($lats) - self::BBOX_BUFFER_DEGREES,
                'west' => min($lngs) - self::BBOX_BUFFER_DEGREES,
                'north' => max($lats) + self::BBOX_BUFFER_DEGREES,
                'east' => max($lngs) + self::BBOX_BUFFER_DEGREES,
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
    private function queryOverpass(array $box): array
    {
        $query = $this->buildQuery($box);

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
            $name = $el['tags']['name'] ?? $el['tags']['name:en'] ?? null;
            if ($name === null) {
                continue; // Skip nameless entries — not useful in a sightseeing list.
            }
            $category = $this->categorize($el['tags'] ?? []);
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
                'name' => mb_substr((string) $name, 0, 190),
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
     * @param array{south: float, west: float, north: float, east: float} $box
     */
    private function buildQuery(array $box): string
    {
        $bbox = sprintf('%F,%F,%F,%F', $box['south'], $box['west'], $box['north'], $box['east']);

        $clauses = [];
        foreach (self::TAG_FILTERS as $key => $valuePattern) {
            foreach (['node', 'way', 'relation'] as $elementType) {
                $clauses[] = sprintf('%s["%s"~"%s"](%s);', $elementType, $key, $valuePattern, $bbox);
            }
        }

        return '[out:json][timeout:25];(' . implode('', $clauses) . ');out center;';
    }

    /**
     * @param array<string, string> $tags
     */
    private function categorize(array $tags): ?string
    {
        $tourism = $tags['tourism'] ?? null;
        if (in_array($tourism, ['museum', 'zoo', 'attraction', 'viewpoint'], true)) {
            return $tourism;
        }
        $historic = $tags['historic'] ?? null;
        if (in_array($historic, ['monument', 'memorial', 'castle', 'ruins'], true)) {
            return 'monument';
        }
        if (($tags['amenity'] ?? null) === 'place_of_worship') {
            return 'sacred_building';
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
