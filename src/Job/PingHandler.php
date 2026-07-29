<?php

declare(strict_types=1);

namespace App\Job;

use Psr\Log\LoggerInterface;

/**
 * Demo handler for testing the queue infrastructure:
 *   php bin/console.php jobs:ping
 *   php bin/console.php jobs:work
 * Real handlers (thumbnails, EXIF, AI, ...) join this from Phase 2 onward.
 */
final class PingHandler implements JobHandlerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(array $payload): void
    {
        $this->logger->info('Ping job executed', ['payload' => $payload]);
    }
}
