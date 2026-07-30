<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\PoiAssignmentService;
use Psr\Log\LoggerInterface;

/**
 * Job type "poi.assign". Payload: {"trip_id": int}.
 * Dispatched after POI discovery finishes and after any photo/video upload
 * finishes processing — cheap no-op via PoiAssignmentService::assignForTrip()
 * when the trip has no POIs yet, so callers don't need to check first.
 */
final class PoiAssignmentHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PoiAssignmentService $assignment,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $tripId = (int) ($payload['trip_id'] ?? 0);
        if ($tripId === 0) {
            return;
        }

        $count = $this->assignment->assignForTrip($tripId);
        if ($count > 0) {
            $this->logger->info('POI assignment finished', ['trip_id' => $tripId, 'count' => $count]);
        }
    }
}
