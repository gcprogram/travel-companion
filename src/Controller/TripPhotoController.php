<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TripRepository;
use App\Service\TripAccess;
use App\Service\TripMediaStatsService;
use App\Support\View;
use App\Support\WizardNav;
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
        private readonly TripMediaStatsService $mediaStats,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireViewable($request, (string) $args['slug']);

        $canEdit = $this->access->canEdit($trip, $request->getAttribute('user'), $request);

        return $this->view->render($response, 'trips/photos', [
            'trip' => $trip,
            'canEdit' => $canEdit,
            'mediaStats' => $canEdit ? $this->mediaStats->forTrip((int) $trip['id']) : null,
            'wizard' => WizardNav::isActive($request),
            'headExtra' => '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">',
        ]);
    }

    /**
     * HTML fragment of just the statistics table - polled by
     * trip-photo-upload.js after an upload. Edit-gated like the upload
     * widget the table lives in.
     */
    public function mediaStats(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->trips->findById((int) $args['id']);
        if ($trip === null || !$this->access->canEdit($trip, $request->getAttribute('user'), $request)) {
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, 'trips/_media_stats', [
            'mediaStats' => $this->mediaStats->forTrip((int) $trip['id']),
        ], layout: null)->withHeader('Cache-Control', 'no-store');
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
