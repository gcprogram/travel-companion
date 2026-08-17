<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly TrackRepository $tracks,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');

        $trips = $this->trips->findVisibleFor(
            $user !== null ? (int) $user['id'] : null,
            $user !== null && $user['role'] === 'admin',
        );

        $trips = $this->withTrackPreviews($trips);
        return $this->view->render($response, 'home/index', [
            'trips' => $trips,
            'headExtra' => $this->mapHeadExtra($trips),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $trips
     * @return list<array<string, mixed>>
     */
    private function withTrackPreviews(array $trips): array
    {
        return array_map(function (array $trip): array {
            $trip['trackPreview'] = $this->tracks->previewPoints((int) $trip['id']);
            return $trip;
        }, $trips);
    }

    /**
     * The Leaflet CSS is only worth loading when at least one trip card on
     * this page actually has a track preview to render - most trips won't,
     * and this page can list many at once.
     *
     * @param list<array<string, mixed>> $trips
     */
    private function mapHeadExtra(array $trips): string
    {
        foreach ($trips as $trip) {
            if (!empty($trip['trackPreview'])) {
                return '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">';
            }
        }
        return '';
    }

    /**
     * The nav username link: only this user's own trips (private included),
     * unlike the general feed above which mixes in everyone's public ones.
     * RequireLogin sits in front of this route, but it now also admits a
     * share-token visitor with no real account (see TripAccess) - "my
     * trips" makes no sense for one, so it's checked again explicitly here.
     */
    public function myTrips(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $trips = $this->withTrackPreviews($this->trips->findByUser((int) $user['id']));
        return $this->view->render($response, 'home/index', [
            'trips' => $trips,
            'headExtra' => $this->mapHeadExtra($trips),
            'title' => t('home.my_trips_title'),
            'emptyMessage' => t('home.my_trips_empty'),
        ]);
    }
}
