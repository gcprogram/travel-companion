<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\PoiDiscoveryService;
use Psr\Log\LoggerInterface;

/**
 * Job type "poi.discover". Payload: {"trip_id": int}.
 * Enqueued when the user requests POI discovery for a trip's map — several
 * sequential Overpass calls (one per route cluster), clearly job territory.
 */
final class PoiDiscoveryHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PoiDiscoveryService $discovery,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $tripId = (int) ($payload['trip_id'] ?? 0);
        if ($tripId === 0) {
            return;
        }

        $count = $this->discovery->discoverForTrip($tripId);
        $this->logger->info('POI discovery finished', ['trip_id' => $tripId, 'count' => $count]);
    }
}
