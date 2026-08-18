<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GeocodeCacheRepository;
use App\Repository\JobRepository;
use App\Repository\PlaceDetailsCacheRepository;
use App\Repository\PoiMediaRepository;
use App\Repository\PoiRepository;
use App\Repository\StayDismissalRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Service\FieldNotesParser;
use App\Service\GeocachingGpxParser;
use App\Service\GooglePlacesService;
use App\Service\PoiApproachService;
use App\Service\PoiDiscoveryService;
use App\Service\ReverseGeocodingService;
use App\Service\Settings;
use App\Service\TripAccess;
use App\Support\Flash;
use App\Support\View;
use App\Support\WizardNav;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class PoiController
{
    private const CATEGORIES = ['museum', 'zoo', 'attraction', 'viewpoint', 'monument', 'sacred_building', 'other'];
    // Overpass is a free shared service; an unbounded radius from the form
    // would turn one search into a country-sized query.
    private const MAX_SEARCH_RADIUS_METERS = 5000;

    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly PoiRepository $pois,
        private readonly TrackRepository $tracks,
        private readonly PoiMediaRepository $poiMedia,
        private readonly PoiApproachService $poiApproach,
        private readonly Settings $settings,
        private readonly GeocachingGpxParser $geocachingGpx,
        private readonly FieldNotesParser $fieldNotes,
        private readonly JobRepository $jobs,
        private readonly TripAccess $access,
        private readonly ReverseGeocodingService $geocoding,
        private readonly GooglePlacesService $places,
        private readonly PlaceDetailsCacheRepository $placeCache,
        private readonly StayDismissalRepository $dismissals,
        private readonly GeocodeCacheRepository $geocodeCache,
        private readonly Flash $flash,
    ) {
    }

    /**
     * Sights as their own page (moved off the map page - it was getting
     * overloaded, see HANDOVER.md step 5). Still needs the map itself for
     * the "pick on map" manual-add flow and to show POI pins in context.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireViewable($request, (string) $args['slug']);

        $pois = $this->pois->findByTrip((int) $trip['id']);
        $poiApproach = $this->poiApproach->computeForTrip((int) $trip['id'], $pois);

        // PoiRepository::findByTrip() orders alphabetically (category,
        // name) - fine for grouping, useless for "what did we do when".
        // Re-sort chronologically here instead: the track's own closest-
        // approach time (poiApproach, precise to the minute) when known,
        // falling back to the coarser visit_date (geocache found/DNF date,
        // or a manually typed one) for POIs the track never passed near -
        // alphabetically among themselves as the final tiebreak so the
        // order stays deterministic instead of depending on array/DB scan
        // order.
        usort($pois, function (array $a, array $b) use ($poiApproach): int {
            $aTime = $poiApproach[(int) $a['id']]['closestAt'] ?? $a['visit_date'];
            $bTime = $poiApproach[(int) $b['id']]['closestAt'] ?? $b['visit_date'];
            if ($aTime === null && $bTime === null) {
                return strcmp((string) $a['name'], (string) $b['name']);
            }
            if ($aTime === null) {
                return 1;
            }
            if ($bTime === null) {
                return -1;
            }
            return $aTime <=> $bTime;
        });

        $poiMedia = [];
        foreach ($pois as $poi) {
            $poiId = (int) $poi['id'];
            $poiMedia[$poiId] = [
                'photos' => $this->poiMedia->findPhotosForPoi($poiId),
                'videos' => $this->poiMedia->findVideosForPoi($poiId),
            ];
        }

        return $this->view->render($response, 'trips/pois', [
            'trip' => $trip,
            'canEdit' => $this->access->canEdit($trip, $request->getAttribute('user'), $request),
            'pois' => $pois,
            'poiMedia' => $poiMedia,
            // Admin defaults pre-filling the discovery form; the form can
            // override them for a single search (see discover() below).
            'poiSearchRadius' => $this->settings->getInt('poi.search_radius_meters'),
            'poiSearchCategories' => $this->settings->getList('poi.categories'),
            'searchableCategories' => PoiDiscoveryService::searchableCategories(),
            'poiApproach' => $poiApproach,
            'wizard' => WizardNav::isActive($request),
            'headExtra' => '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">',
        ]);
    }

    public function discover(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $body = (array) $request->getParsedBody();

        // The form pre-fills these from the admin defaults; anything invalid
        // just falls back to those defaults inside PoiDiscoveryService.
        $payload = ['trip_id' => (int) $trip['id']];

        $radius = trim((string) ($body['radius_meters'] ?? ''));
        if ($radius !== '' && is_numeric($radius) && (int) $radius > 0) {
            $payload['radius_meters'] = min((int) $radius, self::MAX_SEARCH_RADIUS_METERS);
        }

        $categories = $body['categories'] ?? null;
        if (is_array($categories)) {
            $valid = array_values(array_intersect(
                array_map(strval(...), $categories),
                PoiDiscoveryService::searchableCategories(),
            ));
            if ($valid !== []) {
                $payload['categories'] = $valid;
            }
        }

        $this->jobs->dispatch('poi.discover', $payload);

        $this->flash->add('success', t('trip.map.poi_discover_started'));
        return $this->redirectToPois($request, $response, $trip);
    }

    /**
     * Imports found and DNF caches from a Geocaching GPX (c:geo / GSAK /
     * pocket query export, see GeocachingGpxParser) as sights with their
     * real cache_type icon - a DNF still counts as a visited sight (the
     * traveller was there and searched). The GC username is only ever used
     * for this one request (matching it against each cache's own logs) -
     * never stored server-side; geocaching-gpx-import.js's client-only
     * convenience (localStorage) prefills the field so it doesn't have to
     * be retyped.
     *
     * An optional field-notes file (see FieldNotesParser) backs up the
     * username match: some c:geo exports don't carry enough of a cache's
     * own log history for the own-log match to find the traveller's log at
     * all, especially for older finds. Field notes have no coordinates of
     * their own - matched against this same GPX's waypoints by GC code -
     * and are further restricted to the trip's own date range (with a
     * generous buffer for late logging) so a field-notes file covering the
     * user's entire caching history doesn't pull in finds from unrelated
     * trips that happen to share a GC code list.
     */
    public function importGpx(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);

        $files = $request->getUploadedFiles();
        $file = $files['geocaching_gpx'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->flash->add('error', t('trip.map.geocaching_gpx_upload_error'));
            return $this->redirectToPois($request, $response, $trip);
        }

        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['gc_username'] ?? ''));

        $fieldNotes = [];
        $notesFile = $files['field_notes'] ?? null;
        if ($notesFile !== null && $notesFile->getError() === UPLOAD_ERR_OK) {
            $parsed = $this->fieldNotes->parse($notesFile->getStream()->getContents());
            $fieldNotes = $this->filterFieldNotesToTripRange($parsed, $trip);
        }

        $documents = $this->extractGpxDocuments($file);
        if ($documents === []) {
            $this->flash->add('error', t('trip.map.geocaching_gpx_zip_empty'));
            return $this->redirectToPois($request, $response, $trip);
        }

        // A Pocket Query ZIP's companion -wpts.gpx (extractGpxDocuments()
        // sorts it after the main file) only ever fills in a cache the
        // main file didn't already resolve, or backs up a found/DNF
        // signal the main file's own log matching missed - it never
        // downgrades one the main file already confirmed.
        $combined = [];
        foreach ($documents as $xml) {
            foreach ($this->geocachingGpx->parse($xml, $username, $fieldNotes) as $cache) {
                $existing = $combined[$cache['gcCode']] ?? null;
                if ($existing !== null && ($existing['found'] || $existing['dnf']) && !($cache['found'] || $cache['dnf'])) {
                    continue;
                }
                $combined[$cache['gcCode']] = $cache;
            }
        }
        $caches = array_values($combined);
        $relevant = array_values(array_filter($caches, static fn (array $c): bool => $c['found'] || $c['dnf']));

        // A Pocket Query/field-notes combo has no location awareness of its
        // own - it matches purely by GC code and date, so a find from a
        // completely different place the traveller visited on the same
        // trip (or just before/after) would otherwise get imported here
        // too. Drop anything too far from this trip's own track, if one
        // exists yet (nothing to compare against otherwise - import
        // everything rather than silently dropping it all).
        $droppedByDistance = 0;
        $trackLatLngs = $this->trackLatLngs((int) $trip['id']);
        if ($trackLatLngs !== []) {
            $radius = $this->settings->getInt('poi.geocache_import_radius_meters');
            $before = count($relevant);
            $relevant = array_values(array_filter(
                $relevant,
                fn (array $c): bool => $this->nearAnyPoint($c['lat'], $c['lng'], $trackLatLngs, $radius),
            ));
            $droppedByDistance = $before - count($relevant);
        }

        foreach ($relevant as $cache) {
            $this->pois->upsertFromGpxImport(
                (int) $trip['id'],
                $cache['gcCode'],
                $cache['name'],
                $cache['lat'],
                $cache['lng'],
                $cache['cacheType'],
                $cache['difficulty'],
                $cache['terrain'],
                $cache['found'] ? $cache['foundDate'] : $cache['dnfDate'],
                $cache['found'] ? 'found' : 'dnf',
            );
        }

        if ($relevant === [] && $droppedByDistance > 0) {
            $this->flash->add('error', t('trip.map.geocaching_gpx_all_too_far', ['count' => (string) $droppedByDistance]));
        } elseif ($relevant === []) {
            $this->flash->add('error', t('trip.map.geocaching_gpx_none_found'));
        } elseif ($droppedByDistance > 0) {
            $this->flash->add('success', t('trip.map.geocaching_gpx_imported_with_dropped', [
                'count' => (string) count($relevant),
                'dropped' => (string) $droppedByDistance,
            ]));
        } else {
            $this->flash->add('success', t('trip.map.geocaching_gpx_imported', ['count' => (string) count($relevant)]));
        }
        return $this->redirectToPois($request, $response, $trip);
    }

    /**
     * @return list<array{lat: float, lng: float}>
     */
    private function trackLatLngs(int $tripId): array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return [];
        }
        return array_map(
            static fn (array $p): array => ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']],
            $this->tracks->findPoints((int) $track['id']),
        );
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     */
    private function nearAnyPoint(float $lat, float $lng, array $points, int $radiusMeters): bool
    {
        foreach ($points as $point) {
            if ($this->haversineMeters($lat, $lng, $point['lat'], $point['lng']) <= $radiusMeters) {
                return true;
            }
        }
        return false;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371000.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * A geocaching.com Pocket Query downloads as a ZIP with two GPX members
     * (the main <id>.gpx with full cache descriptions/logs, and a companion
     * <id>-wpts.gpx of additional waypoints - stages, parking, almost never
     * actual cache codes) - c:geo/GSAK exports are a single plain .gpx file.
     * Detects a ZIP by magic bytes rather than trusting the filename
     * extension, extracts every *.gpx member (main file first,
     * -wpt(s).gpx sorted after per importGpx()'s merge precedence), and
     * returns their raw XML for GeocachingGpxParser. A plain, non-ZIP
     * upload passes through unchanged as a single-element list.
     *
     * @return list<string>
     */
    private function extractGpxDocuments(UploadedFileInterface $file): array
    {
        $contents = $file->getStream()->getContents();
        if (!str_starts_with($contents, "PK\x03\x04")) {
            return [$contents];
        }

        // ZipArchive only opens real files, not in-memory streams.
        $tmpPath = tempnam(sys_get_temp_dir(), 'tcgpx');
        file_put_contents($tmpPath, $contents);

        $documents = [];
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && str_ends_with(strtolower($name), '.gpx')) {
                    $names[] = $name;
                }
            }
            usort(
                $names,
                static fn (string $a, string $b): int => (int) (bool) preg_match('/-wpts?\.gpx$/i', $a)
                    <=> (int) (bool) preg_match('/-wpts?\.gpx$/i', $b),
            );

            foreach ($names as $name) {
                $xml = $zip->getFromName($name);
                if ($xml !== false) {
                    $documents[] = $xml;
                }
            }
            $zip->close();
        }
        unlink($tmpPath);

        return $documents;
    }

    /**
     * Bulk cleanup after the trip's photos are in: drops every discovered
     * POI nothing was photographed at. See PoiRepository::deleteUnphotographed
     * for what it deliberately spares.
     */
    public function deleteUnphotographed(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $removed = $this->pois->deleteUnphotographed((int) $trip['id']);

        $this->flash->add('success', t('trip.map.poi_unphotographed_removed', ['count' => (string) $removed]));
        return $this->redirectToPois($request, $response, $trip);
    }

    /**
     * Clears this trip's own share of geocode_cache only - the admin
     * "Ortsnamen-Cache leeren" button (AdminSettingsController) wipes every
     * trip of every user at once, which is overkill for "this one trip's
     * place names look off, re-resolve them". Nothing but a re-resolve
     * delay either way (GeocodeResolveHandler/TripMetadataAutoFillHandler
     * refill it on the next miss).
     */
    public function clearGeocodeCache(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $removed = $this->geocodeCache->clearForTrip((int) $trip['id']);

        $this->flash->add('success', t('trip.map.geocode_cache_cleared', ['count' => (string) $removed]));
        return $this->redirectToPois($request, $response, $trip);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $body = (array) $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        $category = in_array($body['category'] ?? '', self::CATEGORIES, true) ? (string) $body['category'] : 'other';
        $lat = $body['lat'] ?? '';
        $lng = $body['lng'] ?? '';
        $visitDate = $this->validDateOrNull($body['visit_date'] ?? null);
        $notes = trim((string) ($body['notes'] ?? ''));

        if ($name === '' || !is_numeric($lat) || !is_numeric($lng)
            || (float) $lat < -90.0 || (float) $lat > 90.0 || (float) $lng < -180.0 || (float) $lng > 180.0
        ) {
            $this->flash->add('error', t('trip.map.poi_add_error'));
            return $this->redirectToPois($request, $response, $trip);
        }

        $this->pois->createManual(
            (int) $trip['id'],
            $category,
            mb_substr($name, 0, 190),
            round((float) $lat, 6),
            round((float) $lng, 6),
            $visitDate,
            $notes === '' ? null : $notes,
        );

        $this->flash->add('success', t('trip.map.poi_added'));
        return $this->redirectToPois($request, $response, $trip);
    }

    /**
     * Turns a detected stay into a sight - either one StayDetectionService
     * inferred from GPS wobble (no name, reverse-geocoded here for one),
     * or a "place visit" a client-side Google Timeline import already knows
     * the name/address of (old-format Semantic Location History embeds
     * both directly - see google-timeline-import.js). A supplied name
     * always wins over reverse-geocoding, both to avoid the pointless
     * Nominatim call and because Google's own name is more precise than
     * "nearest town" for an actual venue. The stay's time span becomes the
     * visit date/note. Marked visited straight away - the track (or
     * Google's own visit detection) proves the person was there, which is
     * the whole point.
     */
    public function addStay(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $body = (array) $request->getParsedBody();

        $lat = $body['lat'] ?? '';
        $lng = $body['lng'] ?? '';
        if (!is_numeric($lat) || !is_numeric($lng)
            || (float) $lat < -90.0 || (float) $lat > 90.0 || (float) $lng < -180.0 || (float) $lng > 180.0
        ) {
            $this->flash->add('error', t('trip.map.poi_add_error'));
            return $this->redirectToMap($request, $response, $trip);
        }

        $lat = round((float) $lat, 6);
        $lng = round((float) $lng, 6);

        $providedName = trim((string) ($body['name'] ?? ''));
        $address = trim((string) ($body['address'] ?? ''));
        $placeId = trim((string) ($body['place_id'] ?? ''));
        $name = $providedName !== '' ? $providedName : null;

        // A Google Timeline "visit" from the newer export generation has a
        // placeId but no plain-text name (see google-timeline-import.js) -
        // resolve it via the Places API (admin-configured key required,
        // cached by placeId since it's a paid call) before falling back to
        // Nominatim like a track-detected stay would.
        if ($name === null && $placeId !== '') {
            $cached = $this->placeCache->find($placeId);
            if (!$cached['found']) {
                $details = $this->places->fetchDetails($placeId);
                $this->placeCache->store(
                    $placeId,
                    $details['name'] ?? null,
                    $details['address'] ?? null,
                    $details['lat'] ?? null,
                    $details['lng'] ?? null,
                );
                $cached = ['found' => true, 'name' => $details['name'] ?? null, 'address' => $details['address'] ?? null];
            }
            if ($cached['name'] !== null) {
                $name = $cached['name'];
            }
            if ($address === '' && !empty($cached['address'])) {
                $address = $cached['address'];
            }
        }

        if ($name === null) {
            try {
                $name = $this->geocoding->reverseGeocode($lat, $lng)['name'];
            } catch (\Throwable) {
                // Nominatim unreachable/slow - still worth recording the visit,
                // just with a fallback name the user can rename.
            }
        }
        $name ??= t('trip.map.stay_fallback_name');

        $startedAt = $this->validDateTimeOrNull($body['started_at'] ?? null);
        $endedAt = $this->validDateTimeOrNull($body['ended_at'] ?? null);

        $noteParts = [];
        if ($address !== '') {
            $noteParts[] = mb_substr($address, 0, 300);
        }
        if ($startedAt !== null && $endedAt !== null) {
            $noteParts[] = t('trip.map.stay_note', ['from' => $startedAt, 'to' => $endedAt]);
        }
        $notes = $noteParts !== [] ? implode("\n\n", $noteParts) : null;

        $poiId = $this->pois->createManual(
            (int) $trip['id'],
            'other',
            mb_substr($name, 0, 190),
            $lat,
            $lng,
            $startedAt !== null ? substr($startedAt, 0, 10) : null,
            $notes,
        );
        $this->pois->setVisited($poiId, true);

        // The "Besuchte Orte prüfen" reviewer (stay-review.js) drives this
        // same endpoint via fetch() to advance through candidates without a
        // full page reload each time - same X-Inline-Delete-style opt-in
        // header convention as confirm-remember.js's inline delete.
        if ($request->getHeaderLine('X-Requested-With') === 'stay-review') {
            $response->getBody()->write((string) json_encode(['ok' => true, 'name' => $name], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $this->flash->add('success', t('trip.map.stay_added', ['name' => $name]));
        return $this->redirectToMap($request, $response, $trip);
    }

    /**
     * The reviewer's reject action - see addStay() for why a stay needs
     * StayDismissalRepository at all (it's the only "existence" a rejected,
     * never-persisted stay gets, so it stops resurfacing as a candidate).
     * JSON-only: only ever called from stay-review.js's fetch(), no plain
     * form fallback needed (same convention as PoiController::rate()).
     */
    public function dismissStay(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $body = (array) $request->getParsedBody();

        $lat = $body['lat'] ?? '';
        $lng = $body['lng'] ?? '';
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return $response->withStatus(422);
        }

        $this->dismissals->dismiss((int) $trip['id'], (float) $lat, (float) $lng);

        $response->getBody()->write((string) json_encode(['ok' => true], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function toggleVisited(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $poi = $this->requireEditablePoi($request, (int) $args['id']);
        $this->pois->setVisited((int) $poi['id'], !((bool) $poi['visited']));

        $trip = $this->trips->findById((int) $poi['trip_id']);
        return $this->redirectToPois($request, $response, $trip);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $poi = $this->requireEditablePoi($request, (int) $args['id']);
        $this->pois->delete((int) $poi['id']);

        // confirm-remember.js's inline-delete path: the list row is already
        // removed client-side, no navigation happens for a flash message or
        // redirect target to matter.
        if ($request->getHeaderLine('X-Inline-Delete') === '1') {
            return $response->withStatus(204);
        }

        $trip = $this->trips->findById((int) $poi['trip_id']);
        $this->flash->add('success', t('trip.map.poi_deleted'));
        return $this->redirectToPois($request, $response, $trip);
    }

    /**
     * @param array<string, mixed>|null $trip
     */
    private function redirectToPois(ServerRequestInterface $request, ResponseInterface $response, ?array $trip): ResponseInterface
    {
        $location = $trip !== null ? WizardNav::preserve('/trip/' . $trip['slug'] . '/pois', $request) : '/';
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /**
     * addStay() alone still redirects here: turning a detected stay into a
     * sight is a track-analysis workflow that starts on the map page (see
     * TripMapController::detectStays()), so that's where the user should
     * land again to see the stay disappear from the suggestion list.
     *
     * @param array<string, mixed>|null $trip
     */
    private function redirectToMap(ServerRequestInterface $request, ResponseInterface $response, ?array $trip): ResponseInterface
    {
        $location = $trip !== null ? WizardNav::preserve('/trip/' . $trip['slug'] . '/map', $request) : '/';
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private function validDateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        return ($dt !== false && $dt->format('Y-m-d H:i:s') === $value) ? $value : null;
    }

    private function validDateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($dt !== false && $dt->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * @param array<string, array{type: 'found'|'dnf', date: string}> $notes
     * @param array<string, mixed> $trip
     * @return array<string, array{type: 'found'|'dnf', date: string}>
     */
    private function filterFieldNotesToTripRange(array $notes, array $trip): array
    {
        $start = $this->validDateOrNull($trip['date_start'] ?? null);
        $end = $this->validDateOrNull($trip['date_end'] ?? null);
        if ($start === null && $end === null) {
            return $notes; // Trip has no dates set yet - nothing to filter against.
        }

        // A few days' slack in both directions: logging can lag the actual
        // find by up to a day or two, and the trip's own dates are
        // themselves auto-filled/approximate.
        $rangeStart = ($start !== null ? new \DateTimeImmutable($start) : new \DateTimeImmutable($end))->modify('-3 days');
        $rangeEnd = ($end !== null ? new \DateTimeImmutable($end) : new \DateTimeImmutable($start))->modify('+3 days');

        return array_filter($notes, static function (array $note) use ($rangeStart, $rangeEnd): bool {
            $date = new \DateTimeImmutable($note['date']);
            return $date >= $rangeStart && $date <= $rangeEnd;
        });
    }

    /**
     * Same visibility rule as the trip page itself - the sights page is
     * public/view-gated like the trip and map pages, not edit-gated.
     */
    private function requireViewable(ServerRequestInterface $request, string $slug): array
    {
        $trip = $this->trips->findBySlug($slug);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->access->canView($trip, $request->getAttribute('user'), $request)) {
            throw new HttpNotFoundException($request);
        }
        return $trip;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditable(ServerRequestInterface $request, int $tripId): array
    {
        $trip = $this->trips->findById($tripId);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->access->canEdit($trip, $request->getAttribute('user'), $request)) {
            throw new HttpForbiddenException($request);
        }
        return $trip;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditablePoi(ServerRequestInterface $request, int $poiId): array
    {
        $poi = $this->pois->findById($poiId);
        if ($poi === null) {
            throw new HttpNotFoundException($request);
        }
        $this->requireEditable($request, (int) $poi['trip_id']);
        return $poi;
    }
}
