<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PoiMediaRepository;
use App\Repository\PoiRepository;
use App\Repository\TripRepository;
use App\Service\PoiApproachService;
use App\Service\PoiDiscoveryService;
use App\Service\Settings;
use App\Service\StorageQuotaService;
use App\Service\TripAccess;
use App\Service\TripRouteSummaryService;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * Stage 3 of the new trip-creation flow (see HANDOVER.md): "Reise
 * bearbeiten" for an existing trip lands here instead of the plain
 * metadata form - one page, four collapsible sections (Metadaten/Route/
 * Fotos/Besuchte Orte), only one open at a time
 * (exclusive-accordion.js), unlike the sequential wizard pages
 * (form.php -> map.php -> photos.php -> pois.php) a brand new trip walks
 * through instead. Each section reuses the exact same partial template
 * its standalone page renders (_metadata_fields.php, _route_fields.php,
 * _photos_fields.php, _pois_fields.php) so there's exactly one
 * implementation of each form, not two drifting apart - this controller
 * just gathers the union of what those four pages' own controllers
 * (TripController, TripMapController, TripPhotoController, PoiController)
 * each already compute.
 */
final class TripManageController
{
    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly PoiRepository $pois,
        private readonly PoiMediaRepository $poiMedia,
        private readonly PoiApproachService $poiApproach,
        private readonly Settings $settings,
        private readonly TripAccess $access,
        private readonly TripRouteSummaryService $routeSummary,
        private readonly StorageQuotaService $storage,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (string) $args['slug']);
        $user = $request->getAttribute('user');
        $isOwner = $user !== null && (int) $trip['user_id'] === (int) $user['id'];

        $pois = $this->pois->findByTrip((int) $trip['id']);
        $poiMedia = [];
        foreach ($pois as $poi) {
            $poiId = (int) $poi['id'];
            $poiMedia[$poiId] = [
                'photos' => $this->poiMedia->findPhotosForPoi($poiId),
                'videos' => $this->poiMedia->findVideosForPoi($poiId),
            ];
        }

        return $this->view->render($response, 'trips/manage', [
            'trip' => $trip,
            'errors' => [],
            'canEdit' => true, // requireEditable() below already gated this
            // Only the owner sees this (Stefan's ask) - see TripController::edit().
            'tripStorageBytes' => $isOwner ? $this->storage->tripBytes((int) $trip['id']) : null,
            'wizardQs' => '', // this page is never part of the creation wizard
            'track' => $this->routeSummary->trackSummary((int) $trip['id']),
            'stays' => $this->routeSummary->detectStays((int) $trip['id'], $pois),
            'pois' => $pois,
            'poiMedia' => $poiMedia,
            'poiApproach' => $this->poiApproach->computeForTrip((int) $trip['id'], $pois),
            'poiSearchRadius' => $this->settings->getInt('poi.search_radius_meters'),
            'poiSearchCategories' => $this->settings->getList('poi.categories'),
            'searchableCategories' => PoiDiscoveryService::searchableCategories(),
            'headExtra' => '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditable(ServerRequestInterface $request, string $slug): array
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
}
