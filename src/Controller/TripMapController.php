<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Repository\VideoRepository;
use App\Service\TripAccess;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class TripMapController
{
    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly TrackRepository $tracks,
        private readonly TripAccess $access,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireViewable($request, (string) $args['slug']);

        return $this->view->render($response, 'trips/map', [
            'trip' => $trip,
            'canEdit' => $this->access->canEdit($trip, $request->getAttribute('user')),
            'track' => $this->trackSummary((int) $trip['id']),
        ]);
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
                    'takenAt' => $photo['created_at'],
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

        $response->getBody()->write((string) json_encode([
            'pins' => $pins,
            'track' => $this->buildTrack((int) $trip['id']),
            'pois' => [],
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
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

        return [
            'points' => array_map(static fn (array $p): array => [
                'lat' => (float) $p['lat'],
                'lng' => (float) $p['lng'],
                'elevation' => $p['elevation_m'] !== null ? (float) $p['elevation_m'] : null,
                'recordedAt' => $p['recorded_at'],
            ], $visible),
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
        if (!$this->access->canView($trip, $request->getAttribute('user'))) {
            // Treat private trips as "doesn't exist" for strangers, matching TripController::show.
            throw new HttpNotFoundException($request);
        }
        return $trip;
    }
}
