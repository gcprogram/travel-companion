<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\PhotoTrackGapFillService;
use Psr\Log\LoggerInterface;

/**
 * Job type "track.gapfill". Payload: {"trip_id": int}.
 * Dispatched after any photo finishes processing (or is registered as a
 * dedup reference) - cheap no-op via PhotoTrackGapFillService::fillGaps()
 * when the trip has no track yet or the photo doesn't fall in a gap, so
 * callers don't need to check first. Track/points uploads don't go through
 * this job - TrackController calls the service directly, since that path
 * already has the trip loaded in the same request.
 */
final class TrackGapFillHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PhotoTrackGapFillService $gapFill,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $tripId = (int) ($payload['trip_id'] ?? 0);
        if ($tripId === 0) {
            return;
        }

        $added = $this->gapFill->fillGaps($tripId);
        if ($added > 0) {
            $this->logger->info('Track gap-fill finished', ['trip_id' => $tripId, 'count' => $added]);
        }
    }
}
