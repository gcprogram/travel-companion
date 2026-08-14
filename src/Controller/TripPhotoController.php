<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TripRepository;
use App\Service\TripAccess;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Trip-level photo/video upload (Stage 1 of the new trip-creation flow, see
 * HANDOVER.md "Großes Thema" / the approved plan for it): unlike the
 * existing per-entry upload widget in day_entries/form.php, this page lets
 * someone upload photos/videos before any diary entry exists at all -
 * trip-photo-upload.js reads each file's capture date client-side and
 * resolves (finds or creates) the matching day_entry via
 * DayEntryController::resolveForDate() before handing the file to the
 * existing chunked-upload endpoint. No new upload/storage code here; this
 * controller only renders the page.
 */
final class TripPhotoController
{
    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly TripAccess $access,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireViewable($request, (string) $args['slug']);

        return $this->view->render($response, 'trips/photos', [
            'trip' => $trip,
            'canEdit' => $this->access->canEdit($trip, $request->getAttribute('user'), $request),
            'headExtra' => '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">',
        ]);
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
