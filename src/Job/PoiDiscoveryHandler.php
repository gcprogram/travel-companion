<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\JobRepository;
use App\Service\PoiDiscoveryService;
use Psr\Log\LoggerInterface;

/**
 * Job type "poi.discover". Payload: {"trip_id": int, "radius_meters"?: int,
 * "categories"?: string[]}. Enqueued when the user requests POI discovery
 * for a trip's map — several sequential Overpass calls (one per route
 * cluster), clearly job territory. radius_meters/categories are the search
 * form's per-run overrides; absent means "use the admin defaults".
 */
final class PoiDiscoveryHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PoiDiscoveryService $discovery,
        private readonly JobRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $tripId = (int) ($payload['trip_id'] ?? 0);
        if ($tripId === 0) {
            return;
        }

        $radius = isset($payload['radius_meters']) ? (int) $payload['radius_meters'] : null;
        $categories = isset($payload['categories']) && is_array($payload['categories'])
            ? array_values(array_map(strval(...), $payload['categories']))
            : null;

        $count = $this->discovery->discoverForTrip($tripId, $radius, $categories);
        $this->logger->info('POI discovery finished', [
            'trip_id' => $tripId, 'count' => $count, 'radius_meters' => $radius,
        ]);

        if ($count > 0) {
            // Newly discovered POIs may now be the nearest match for
            // already-uploaded photos/videos that had no POI yet.
            $this->jobs->dispatch('poi.assign', ['trip_id' => $tripId]);
        }
    }
}
