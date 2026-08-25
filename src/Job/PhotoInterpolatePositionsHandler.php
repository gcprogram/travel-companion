<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\PhotoPositionInterpolationService;
use Psr\Log\LoggerInterface;

/**
 * Job type "photo.interpolate". Payload: {"trip_id": int}.
 * See PhotoPositionInterpolationService for the actual logic - dispatched
 * after every track upload (TrackController) and every photo finishing
 * processing (PhotoProcessHandler), cheap no-op via
 * findNeedingInterpolation() when there's nothing to (re)compute.
 */
final class PhotoInterpolatePositionsHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PhotoPositionInterpolationService $interpolation,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $tripId = (int) ($payload['trip_id'] ?? 0);
        if ($tripId === 0) {
            return;
        }

        $count = $this->interpolation->interpolateForTrip($tripId);
        if ($count > 0) {
            $this->logger->info('Photo position interpolation finished', ['trip_id' => $tripId, 'count' => $count]);
        }
    }
}
