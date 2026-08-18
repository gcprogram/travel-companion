<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Service\TrackEditService;
use App\Service\TripAccess;
use App\Service\TripRouteSummaryService;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * "Route editieren": the trim slider + "Tracks löschen" (moved here from
 * the Routen-Details page, see _route_fields.php) plus new single-point
 * surgery - delete a point, insert one between two existing neighbours,
 * undo the last such edit, or reset back to before this editing session's
 * first one. Edit-only, same as TripMapController::review() - nothing to
 * look at read-only here.
 */
final class TrackEditController
{
    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly TrackRepository $tracks,
        private readonly TripRouteSummaryService $routeSummary,
        private readonly TripAccess $access,
        private readonly TrackEditService $trackEdit,
        private readonly Flash $flash,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableBySlug($request, (string) $args['slug']);

        return $this->view->render($response, 'trips/route_edit', [
            'trip' => $trip,
            'track' => $this->routeSummary->trackSummary((int) $trip['id']),
            'headExtra' => '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">',
        ]);
    }

    /**
     * Raw, unsmoothed points with their real id - unlike /map/data (which
     * smooths pauses together and never exposes an id, fine for display but
     * useless for an editor that needs to address one specific point).
     */
    public function data(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableBySlug($request, (string) $args['slug']);
        $track = $this->tracks->findByTrip((int) $trip['id']);
        $points = $track !== null ? $this->tracks->findPoints((int) $track['id']) : [];

        $payload = array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'seq' => (int) $p['seq'],
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'recordedAt' => $p['recorded_at'],
        ], $points);

        return $this->json($response, ['points' => $payload], 200);
    }

    public function deletePoint(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableById($request, (int) $args['id']);
        $result = $this->trackEdit->deletePoint((int) $trip['id'], (int) $args['pointId']);
        return $this->json($response, $result, $result['ok'] ? 200 : 422);
    }

    public function insertPoint(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableById($request, (int) $args['id']);
        $body = (array) $request->getParsedBody();

        $pointIdA = (int) ($body['point_id_a'] ?? 0);
        $pointIdB = (int) ($body['point_id_b'] ?? 0);
        $lat = $body['lat'] ?? null;
        $lng = $body['lng'] ?? null;
        if ($pointIdA <= 0 || $pointIdB <= 0 || !is_numeric($lat) || !is_numeric($lng)
            || (float) $lat < -90.0 || (float) $lat > 90.0 || (float) $lng < -180.0 || (float) $lng > 180.0
        ) {
            return $this->json($response, ['ok' => false, 'error' => 'invalid'], 422);
        }

        $result = $this->trackEdit->insertPoint(
            (int) $trip['id'],
            $pointIdA,
            $pointIdB,
            round((float) $lat, 6),
            round((float) $lng, 6),
        );
        return $this->json($response, $result, $result['ok'] ? 200 : 422);
    }

    public function undo(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableById($request, (int) $args['id']);
        $ok = $this->trackEdit->undo((int) $trip['id']);
        $this->flash->add($ok ? 'success' : 'error', t($ok ? 'trip.route_edit.undo_done' : 'trip.route_edit.undo_none'));
        return $this->redirectToRouteEdit($request, $response, $trip);
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableById($request, (int) $args['id']);
        $ok = $this->trackEdit->reset((int) $trip['id']);
        $this->flash->add($ok ? 'success' : 'error', t($ok ? 'trip.route_edit.reset_done' : 'trip.route_edit.reset_no_changes'));
        return $this->redirectToRouteEdit($request, $response, $trip);
    }

    private function redirectToRouteEdit(ServerRequestInterface $request, ResponseInterface $response, array $trip): ResponseInterface
    {
        return $response->withHeader('Location', '/trip/' . $trip['slug'] . '/route-edit')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(ResponseInterface $response, array $data, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditableBySlug(ServerRequestInterface $request, string $slug): array
    {
        $trip = $this->trips->findBySlug($slug);
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
    private function requireEditableById(ServerRequestInterface $request, int $tripId): array
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
}
