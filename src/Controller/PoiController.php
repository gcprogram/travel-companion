<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\JobRepository;
use App\Repository\PoiRepository;
use App\Repository\TripRepository;
use App\Service\PoiDiscoveryService;
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
        if (!$this->access->canEdit($trip, $request->getAttribute('user'))) {
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
