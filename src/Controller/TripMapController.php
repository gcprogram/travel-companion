<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\GeocodeCacheRepository;
use App\Repository\JobRepository;
use App\Repository\PhotoRepository;
use App\Repository\PoiRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Repository\VideoRepository;
use App\Service\StayDetectionService;
use App\Service\TrackSmoothingService;
use App\Service\TripAccess;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class TripMapController
{
    /** A stay this close to an existing POI is treated as already recorded. */
    private const STAY_POI_MATCH_METERS = 150.0;

    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly TrackRepository $tracks,
        private readonly TrackSmoothingService $smoothing,
        private readonly PoiRepository $pois,
        private readonly TripAccess $access,
        private readonly StayDetectionService $stayDetection,
        private readonly GeocodeCacheRepository $geocodeCache,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * Sights (PoiController::index()) moved to their own page - this one is
     * just the map + track tools + detected stays now. Still needs the POI
     * list internally (not passed to the template) since detectStays()
     * filters out stays that already have a nearby POI.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireViewable($request, (string) $args['slug']);
        $pois = $this->pois->findByTrip((int) $trip['id']);

        return $this->view->render($response, 'trips/map', [
            'trip' => $trip,
            'canEdit' => $this->access->canEdit($trip, $request->getAttribute('user'), $request),
            'track' => $this->trackSummary((int) $trip['id']),
            'stays' => $this->detectStays((int) $trip['id'], $pois),
            'headExtra' => '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">',
        ]);
    }

    /**
     * Places the traveller stopped at long enough to count as a visit,
     * derived from the raw track (see StayDetectionService). Stays that
     * already have a POI nearby are dropped, so a stay disappears from the
     * suggestion list once it's been added - and discovered sights the user
     * genuinely stopped at don't get offered a second time.
     *
     * Each remaining stay gets a best-effort 'locationName' straight from
     * the geocode_cache grid - never a live Nominatim call from within this
     * request (see GeocodeCacheRepository/GeocodeResolveHandler): a cache
     * miss just dispatches a job and the name shows up on the next page
     * load. Recomputed on every request since stays themselves aren't
     * persisted, so this must never turn into a per-stay external call.
     *
     * @param list<array<string, mixed>> $pois
     * @return list<array<string, mixed>>
     */
    private function detectStays(int $tripId, array $pois): array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return [];
        }

        $points = array_map(static fn (array $p): array => [
            'seq' => (int) $p['seq'],
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'recordedAt' => $p['recorded_at'],
        ], $this->tracks->findPoints((int) $track['id']));

        $stays = $this->stayDetection->detect($points);

        $unmatched = array_values(array_filter(
            $stays,
            fn (array $stay): bool => !$this->hasPoiNear($pois, $stay['lat'], $stay['lng']),
        ));

        return array_map(function (array $stay): array {
            $cached = $this->geocodeCache->find($stay['lat'], $stay['lng']);
            $stay['locationName'] = $cached['name'];
            $stay['locationResolved'] = $cached['found'];
            if (!$cached['found']) {
                $this->jobs->dispatch('geocode.resolve', ['lat' => $stay['lat'], 'lng' => $stay['lng']]);
            }
            return $stay;
        }, $unmatched);
    }

    /**
     * @param list<array<string, mixed>> $pois
     */
    private function hasPoiNear(array $pois, float $lat, float $lng): bool
    {
        foreach ($pois as $poi) {
            $dLat = deg2rad((float) $poi['lat'] - $lat);
            $dLng = deg2rad((float) $poi['lng'] - $lng);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float) $poi['lat'])) * sin($dLng / 2) ** 2;
            if (6371000.0 * 2 * atan2(sqrt($a), sqrt(1 - $a)) <= self::STAY_POI_MATCH_METERS) {
                return true;
            }
        }
        return false;
    }

    /**
     * Just enough for the upload-tools/trim-slider form to render with the
     * right bounds — the full point list is only needed by the map JS,
     * fetched separately via /map/data.
     *
     * @return array{totalPoints: int, trimStart: int, trimEnd: int}|null
     */
    private function trackSummary(int $tripId): ?array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return null;
        }

        $totalPoints = $this->tracks->countPoints((int) $track['id']);
        return [
            'totalPoints' => $totalPoints,
            'trimStart' => $track['trim_start_seq'] !== null ? (int) $track['trim_start_seq'] : 0,
            'trimEnd' => $track['trim_end_seq'] !== null ? (int) $track['trim_end_seq'] : max(0, $totalPoints - 1),
        ];
    }

    /**
     * JSON data for trip-map.js: geotagged photo/video pins, ordered
     * chronologically. Track/POI data join in once tasks #30/#33 land —
     * the response shape is fixed now so the frontend doesn't need to
     * change again later.
     */
    public function data(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireViewable($request, (string) $args['slug']);

        $pins = [];
        foreach ($this->entries->findByTrip((int) $trip['id']) as $entry) {
            foreach ($this->photos->findByEntry((int) $entry['id']) as $photo) {
                if ($photo['status'] !== 'ready' || $photo['lat'] === null) {
                    continue;
                }
                $pins[] = [
                    'kind' => 'photo',
                    'id' => (int) $photo['id'],
                    'lat' => (float) $photo['lat'],
                    'lng' => (float) $photo['lng'],
                    'thumbUrl' => '/photos/' . $photo['id'] . '/thumb',
                    'fullUrl' => '/photos/' . $photo['id'] . '/web',
                    'entryDate' => $entry['entry_date'],
                    // Real EXIF capture time where available; upload time
                    // (created_at) as a fallback for photos processed before
                    // taken_at existed, or lacking a DateTimeOriginal tag.
                    'takenAt' => $photo['taken_at'] ?? $photo['created_at'],
                ];
            }
            foreach ($this->videos->findByEntry((int) $entry['id']) as $video) {
                if ($video['status'] !== 'ready' || $video['lat'] === null) {
                    continue;
                }
                $pins[] = [
                    'kind' => 'video',
                    'id' => (int) $video['id'],
                    'lat' => (float) $video['lat'],
                    'lng' => (float) $video['lng'],
                    'thumbUrl' => '/videos/' . $video['id'] . '/poster',
                    'fullUrl' => '/videos/' . $video['id'],
                    'entryDate' => $entry['entry_date'],
                    'takenAt' => $video['created_at'],
                ];
            }
        }

        usort($pins, static fn (array $a, array $b): int => $a['takenAt'] <=> $b['takenAt']);

        $pois = array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'category' => $p['category'],
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'visited' => (bool) $p['visited'],
            // Only set for category='geocache' (see PoiController::importGpx()) -
            // trip-map.js swaps in the real cache_type SVG icon when present.
            'cacheIconUrl' => $p['category'] === 'geocache' ? cache_type_icon_url($p['cache_type']) : null,
        ], $this->pois->findByTrip((int) $trip['id']));

        $canEdit = $this->access->canEdit($trip, $request->getAttribute('user'), $request);

        $response->getBody()->write((string) json_encode([
            'pins' => $pins,
            'track' => $this->buildTrack((int) $trip['id']),
            // Full-resolution, untrimmed, unsmoothed points - only the trim
            // slider UI needs these (to look up a time for any given seq, or
            // preview the selection live before it's committed), so this is
            // left out entirely for read-only viewers.
            'trackFull' => $canEdit ? $this->buildRawTrackPoints((int) $trip['id']) : null,
            'pois' => $pois,
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * @return list<array{seq: int, lat: float, lng: float, recordedAt: ?string}>
     */
    private function buildRawTrackPoints(int $tripId): array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return [];
        }

        return array_map(static fn (array $p): array => [
            'seq' => (int) $p['seq'],
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'recordedAt' => $p['recorded_at'],
        ], $this->tracks->findPoints((int) $track['id']));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildTrack(int $tripId): ?array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return null;
        }

        $points = $this->tracks->findPoints((int) $track['id']);
        $totalPoints = count($points);

        $trimStart = $track['trim_start_seq'] !== null ? (int) $track['trim_start_seq'] : 0;
        $trimEnd = $track['trim_end_seq'] !== null ? (int) $track['trim_end_seq'] : max(0, $totalPoints - 1);

        $visible = array_values(array_filter(
            $points,
            static fn (array $p): bool => (int) $p['seq'] >= $trimStart && (int) $p['seq'] <= $trimEnd,
        ));

        $forSmoothing = array_map(static fn (array $p): array => [
            'seq' => (int) $p['seq'],
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'elevation' => $p['elevation_m'] !== null ? (float) $p['elevation_m'] : null,
            'recordedAt' => $p['recorded_at'],
            'accuracy' => $p['accuracy_m'] !== null ? (float) $p['accuracy_m'] : null,
        ], $visible);

        return [
            'points' => $this->smoothing->smooth($forSmoothing),
            'totalPoints' => $totalPoints,
            'trimStart' => $trimStart,
            'trimEnd' => $trimEnd,
            'source' => $track['source'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireViewable(ServerRequestInterface $request, string $slug): array
    {
        $trip = $this->trips->findBySlug($slug);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->access->canView($trip, $request->getAttribute('user'), $request)) {
            // Treat private trips as "doesn't exist" for strangers, matching TripController::show.
            throw new HttpNotFoundException($request);
        }
        return $trip;
    }
}
