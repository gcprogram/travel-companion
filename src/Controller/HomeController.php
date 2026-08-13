<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TripRepository;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');

        $trips = $this->trips->findVisibleFor(
            $user !== null ? (int) $user['id'] : null,
            $user !== null && $user['role'] === 'admin',
        );

        return $this->view->render($response, 'home/index', ['trips' => $trips]);
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

        return $this->view->render($response, 'home/index', [
            'trips' => $this->trips->findByUser((int) $user['id']),
            'title' => t('home.my_trips_title'),
            'emptyMessage' => t('home.my_trips_empty'),
        ]);
    }
}
