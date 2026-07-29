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
}
