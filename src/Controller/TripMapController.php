<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
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
        private readonly TripAccess $access,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireViewable($request, (string) $args['slug']);

        return $this->view->render($response, 'trips/map', [
            'trip' => $trip,
        ]);
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
            'track' => null,
            'pois' => [],
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
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
