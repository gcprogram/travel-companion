<?php

declare(strict_types=1);

namespace App\Job;

use Psr\Log\LoggerInterface;

/**
 * Demo-Handler zum Testen der Queue-Infrastruktur:
 *   php bin/console.php jobs:ping
 *   php bin/console.php jobs:work
 * Ab Phase 2 kommen echte Handler dazu (Thumbnails, EXIF, KI, ...).
 */
final class PingHandler implements JobHandlerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(array $payload): void
    {
        $this->logger->info('Ping-Job ausgeführt', ['payload' => $payload]);
    }
}
