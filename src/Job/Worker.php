<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\JobRepository;
use Psr\Log\LoggerInterface;

/**
 * Works off due jobs. Meant for the minute-cron on the hosting:
 *
 *   * * * * * php /path/to/app/bin/console.php jobs:work --max-runtime=50
 *
 * --max-runtime keeps the process under the next cron start; the web
 * server's max_execution_time doesn't apply to CLI, so we cap it ourselves.
 */
final class Worker
{
    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(string $type, JobHandlerInterface $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function run(int $maxRuntimeSeconds = 50): int
    {
        $deadline = time() + $maxRuntimeSeconds;
        $processed = 0;

        while (time() < $deadline) {
            $job = $this->jobs->claimNext();
            if ($job === null) {
                break; // Nothing due – stop the process, the next cron run takes over.
            }

            $id = (int) $job['id'];
            $type = (string) $job['type'];

            try {
                $handler = $this->handlers[$type]
                    ?? throw new \RuntimeException(sprintf('No handler registered for job type "%s".', $type));

                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);

                $handler->handle($payload);
                $this->jobs->markDone($id);
                $this->logger->info('Job done', ['id' => $id, 'type' => $type]);
            } catch (\Throwable $e) {
                $this->jobs->markFailed(
                    $id,
                    (int) $job['attempts'], // claimNext returns the row after the claim, attempts is already incremented
                    (int) $job['max_attempts'],
                    $e->getMessage() . "\n" . $e->getTraceAsString(),
                );
                $this->logger->error('Job failed', ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
            }

            $processed++;
        }

        return $processed;
    }
}
