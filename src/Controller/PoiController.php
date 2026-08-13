<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\JobRepository;
use App\Repository\PlaceDetailsCacheRepository;
use App\Repository\PoiRepository;
use App\Repository\TripRepository;
use App\Service\GooglePlacesService;
use App\Service\PoiDiscoveryService;
use App\Service\ReverseGeocodingService;
use App\Service\TripAccess;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class PoiController
{
    private const CATEGORIES = ['museum', 'zoo', 'attraction', 'viewpoint', 'monument', 'sacred_building', 'other'];
    // Overpass is a free shared service; an unbounded radius from the form
    // would turn one search into a country-sized query.
    private const MAX_SEARCH_RADIUS_METERS = 5000;

    public function __construct(
        private readonly TripRepository $trips,
        private readonly PoiRepository $pois,
        private readonly JobRepository $jobs,
        private readonly TripAccess $access,
        private readonly ReverseGeocodingService $geocoding,
        private readonly GooglePlacesService $places,
        private readonly PlaceDetailsCacheRepository $placeCache,
        private readonly Flash $flash,
    ) {
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
        return $this->redirectToMap($response, $trip);
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
        return $this->redirectToMap($response, $trip);
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
            return $this->redirectToMap($response, $trip);
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
        return $this->redirectToMap($response, $trip);
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
            return $this->redirectToMap($response, $trip);
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
                $name = $this->geocoding->reverseGeocode($lat, $lng);
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

        $this->flash->add('success', t('trip.map.stay_added', ['name' => $name]));
        return $this->redirectToMap($response, $trip);
    }

    public function toggleVisited(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $poi = $this->requireEditablePoi($request, (int) $args['id']);
        $this->pois->setVisited((int) $poi['id'], !((bool) $poi['visited']));

        $trip = $this->trips->findById((int) $poi['trip_id']);
        return $this->redirectToMap($response, $trip);
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
        return $this->redirectToMap($response, $trip);
    }

    /**
     * @param array<string, mixed>|null $trip
     */
    private function redirectToMap(ResponseInterface $response, ?array $trip): ResponseInterface
    {
        $location = $trip !== null ? '/trip/' . $trip['slug'] . '/map' : '/';
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
